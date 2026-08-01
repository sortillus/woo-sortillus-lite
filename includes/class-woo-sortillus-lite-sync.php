<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Sync {
	const GROUP           = 'woo-sortillus-lite';
	const IMPORT_HOOK     = 'woo_sortillus_lite_import_batch';
	const DELTA_HOOK      = 'woo_sortillus_lite_send_product';
	const BATCH_SIZE      = 100;
	const MAX_RETRY_COUNT = 5;

	private $settings;
	private $client;
	private $builder;

	public function __construct(
		Woo_Sortillus_Lite_Settings $settings,
		Woo_Sortillus_Lite_Client $client,
		Woo_Sortillus_Lite_Offer_Builder $builder
	) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->builder  = $builder;
	}

	public function init() {
		add_action( 'woocommerce_new_product', array( $this, 'queue_product' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'queue_product' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_product' ), 20, 1 );
		add_action( self::IMPORT_HOOK, array( $this, 'run_import_batch' ), 10, 4 );
		add_action( self::DELTA_HOOK, array( $this, 'run_product_delta' ), 10, 3 );
	}

	public function start_import() {
		if ( ! $this->settings->is_connected() ) {
			return new WP_Error( 'not_connected', __( 'Connect to Sortillus before importing products.', 'woo-sortillus-lite' ) );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'scheduler_missing', __( 'WooCommerce Action Scheduler is unavailable.', 'woo-sortillus-lite' ) );
		}

		$current = $this->settings->get_sync_state();
		if ( in_array( $current['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return new WP_Error( 'sync_running', __( 'A product import is already running.', 'woo-sortillus-lite' ) );
		}

		$counts = wp_count_posts( 'product', 'readable' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$run_id = wp_generate_uuid4();
		$now    = gmdate( 'c' );
		$state  = array(
			'run_id'                  => $run_id,
			'status'                  => 'queued',
			'total'                   => $total,
			'processed'               => 0,
			'accepted'                => 0,
			'rejected'                => 0,
			'cursor'                  => 0,
			'source_total_at_start'   => $total,
			'started_at'              => $now,
			'finished_at'             => null,
			'last_successful_sync_at' => $current['last_successful_sync_at'] ?? null,
			'last_error'              => null,
			'errors'                  => array(),
		);
		$this->settings->set_sync_state( $state );

		$report = $this->client->report_sync(
			$this->sync_payload( $state, 'initial', 'started' ),
			$run_id
		);
		if ( is_wp_error( $report ) ) {
			$state['report_error'] = $report->get_error_message();
			$this->settings->set_sync_state( $state );
		}

		$action_id = $this->schedule_import( $run_id, 0, 0, $this->batch_key( $run_id, 0 ), 0 );
		if ( ! $action_id || is_wp_error( $action_id ) ) {
			$state['status']      = 'failed';
			$state['finished_at'] = gmdate( 'c' );
			$state['last_error']  = __( 'Could not schedule the product import.', 'woo-sortillus-lite' );
			$state['errors'][]    = $state['last_error'];
			$this->settings->set_sync_state( $state );
			$this->client->report_sync( $this->sync_payload( $state, 'initial', 'failed' ), $run_id );
			return new WP_Error( 'schedule_failed', $state['last_error'] );
		}
		return $state;
	}

	public function queue_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! $this->settings->is_connected() || 'publish' !== get_post_status( $product_id ) ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$lock_key = 'woo_sortillus_lite_delta_' . $product_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, '1', HOUR_IN_SECONDS );
		$action_id = as_schedule_single_action(
			time() + 5,
			self::DELTA_HOOK,
			array( $product_id, 0, wp_generate_uuid4() ),
			self::GROUP,
			true
		);
		if ( ! $action_id || is_wp_error( $action_id ) ) {
			delete_transient( $lock_key );
		}
	}

	public function run_import_batch( $run_id, $cursor, $attempt, $idempotency_key ) {
		$state = $this->settings->get_sync_state();
		if ( ( $state['run_id'] ?? '' ) !== $run_id || ! in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return;
		}

		$state['status'] = 'running';
		$this->settings->set_sync_state( $state );
		$product_ids = $this->product_ids_after( (int) $cursor );
		if ( empty( $product_ids ) ) {
			$this->finish_import( $state );
			return;
		}

		$offers  = array();
		$invalid = 0;
		foreach ( $product_ids as $product_id ) {
			$offer = $this->builder->build( $product_id );
			if ( null === $offer ) {
				++$invalid;
				continue;
			}
			$offers[] = $offer;
		}

		$result = empty( $offers ) ? array() : $this->client->send_offers( $offers, $idempotency_key );
		if ( is_wp_error( $result ) && $this->retryable( $result ) && (int) $attempt < self::MAX_RETRY_COUNT ) {
			$delay = $this->retry_delay( $result, (int) $attempt );
			$action_id = $this->schedule_import( $run_id, $cursor, (int) $attempt + 1, $idempotency_key, $delay );
			if ( $action_id && ! is_wp_error( $action_id ) ) {
				return;
			}
		}

		$count              = count( $product_ids );
		$state['processed'] = (int) $state['processed'] + $count;
		$state['cursor']    = max( $product_ids );
		$state['rejected']  = (int) $state['rejected'] + $invalid;
		if ( is_wp_error( $result ) ) {
			$state['rejected']   = (int) $state['rejected'] + count( $offers );
			$state['last_error'] = $result->get_error_message();
			$state['errors'][]   = $result->get_error_message();
		} else {
			$state['accepted'] = (int) $state['accepted'] + count( $offers );
		}
		$state['errors'] = array_slice( array_values( array_unique( $state['errors'] ) ), -10 );
		$this->settings->set_sync_state( $state );

		$action_id = $this->schedule_import(
			$run_id,
			$state['cursor'],
			0,
			$this->batch_key( $run_id, $state['cursor'] ),
			1
		);
		if ( ! $action_id || is_wp_error( $action_id ) ) {
			$remaining             = max( 0, (int) $state['total'] - (int) $state['processed'] );
			$state['rejected']      = (int) $state['rejected'] + $remaining;
			$state['last_error']    = __( 'Could not schedule the next product-import batch.', 'woo-sortillus-lite' );
			$state['errors'][]      = $state['last_error'];
			$state['errors']        = array_slice( array_values( array_unique( $state['errors'] ) ), -10 );
			$this->settings->set_sync_state( $state );
			$this->finish_import( $state );
		}
	}

	public function run_product_delta( $product_id, $attempt, $idempotency_key ) {
		$product_id = absint( $product_id );
		$lock_key   = 'woo_sortillus_lite_delta_' . $product_id;
		$offer      = $this->builder->build( $product_id );
		if ( null === $offer ) {
			delete_transient( $lock_key );
			return;
		}

		$started = gmdate( 'c' );
		$result  = $this->client->send_offer( $offer, $idempotency_key );
		if ( is_wp_error( $result ) && $this->retryable( $result ) && (int) $attempt < self::MAX_RETRY_COUNT ) {
			$action_id = as_schedule_single_action(
				time() + $this->retry_delay( $result, (int) $attempt ),
				self::DELTA_HOOK,
				array( $product_id, (int) $attempt + 1, $idempotency_key ),
				self::GROUP,
				true
			);
			if ( $action_id && ! is_wp_error( $action_id ) ) {
				return;
			}
		}

		$state                  = $this->settings->get_sync_state();
		$state['last_delta_at'] = gmdate( 'c' );
		$status                 = is_wp_error( $result ) ? 'failed' : 'succeeded';
		if ( is_wp_error( $result ) ) {
			$state['last_delta_error'] = $result->get_error_message();
		} else {
			$state['last_delta_error'] = null;
		}
		$this->settings->set_sync_state( $state );
		delete_transient( $lock_key );

		$this->client->report_sync(
			array(
				'kind'             => 'delta',
				'status'           => $status,
				'idempotency_key'  => $idempotency_key,
				'received_count'   => 1,
				'accepted_count'   => is_wp_error( $result ) ? 0 : 1,
				'rejected_count'   => is_wp_error( $result ) ? 1 : 0,
				'started_at'       => $started,
				'finished_at'      => gmdate( 'c' ),
				'errors'           => is_wp_error( $result ) ? array( $result->get_error_message() ) : array(),
				'metadata'         => array( 'woocommerce_product_id' => $product_id ),
			),
			$idempotency_key
		);
	}

	private function finish_import( array $state ) {
		$accepted = (int) ( $state['accepted'] ?? 0 );
		$rejected = (int) ( $state['rejected'] ?? 0 );
		$total    = (int) ( $state['total'] ?? 0 );
		if ( $rejected > 0 && 0 === $accepted && $total > 0 ) {
			$status = 'failed';
		} elseif ( $rejected > 0 ) {
			$status = 'partial';
		} else {
			$status = 'succeeded';
		}

		$state['status']      = $status;
		$state['finished_at'] = gmdate( 'c' );
		if ( 'succeeded' === $status ) {
			$state['last_successful_sync_at'] = $state['finished_at'];
			$state['last_error']              = null;
		}
		$this->settings->set_sync_state( $state );
		$report = $this->client->report_sync( $this->sync_payload( $state, 'initial', $status ), $state['run_id'] );
		if ( is_wp_error( $report ) ) {
			$state['report_error'] = $report->get_error_message();
		} else {
			unset( $state['report_error'] );
		}
		$this->settings->set_sync_state( $state );
	}

	private function sync_payload( array $state, $kind, $status ) {
		return array(
			'kind'             => $kind,
			'status'           => $status,
			'idempotency_key'  => (string) $state['run_id'],
			'received_count'   => (int) ( $state['total'] ?? 0 ),
			'accepted_count'   => (int) ( $state['accepted'] ?? 0 ),
			'rejected_count'   => (int) ( $state['rejected'] ?? 0 ),
			'started_at'       => $state['started_at'] ?? gmdate( 'c' ),
			'finished_at'      => 'started' === $status ? null : ( $state['finished_at'] ?? gmdate( 'c' ) ),
			'errors'           => array_values( $state['errors'] ?? array() ),
			'metadata'         => array( 'connector_variant' => 'lite' ),
		);
	}

	private function product_ids_after( $cursor ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
			'product',
			'publish',
			(int) $cursor,
			self::BATCH_SIZE
		);
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function schedule_import( $run_id, $cursor, $attempt, $idempotency_key, $delay ) {
		return as_schedule_single_action(
			time() + max( 0, (int) $delay ),
			self::IMPORT_HOOK,
			array( $run_id, (int) $cursor, (int) $attempt, $idempotency_key ),
			self::GROUP,
			true
		);
	}

	private function batch_key( $run_id, $cursor ) {
		return $run_id . ':after:' . (int) $cursor;
	}

	private function retryable( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['retryable'] );
	}

	private function retry_delay( WP_Error $error, $attempt ) {
		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		$delays      = array( 60, 300, 900, 3600, 9000 );
		return max( $retry_after, $delays[ min( (int) $attempt, count( $delays ) - 1 ) ] );
	}
}
