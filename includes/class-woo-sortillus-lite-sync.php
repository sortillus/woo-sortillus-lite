<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Sync {
	const GROUP           = 'woo-sortillus-lite';
	const CATEGORY_HOOK   = 'woo_sortillus_lite_category_batch';
	const CATEGORY_DELTA_HOOK = 'woo_sortillus_lite_send_category';
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
		add_action( 'created_product_cat', array( $this, 'queue_category' ), 20, 1 );
		add_action( 'edited_product_cat', array( $this, 'queue_category' ), 20, 1 );
		add_action( self::CATEGORY_DELTA_HOOK, array( $this, 'run_category_delta' ), 10, 3 );
		add_action( 'woocommerce_new_product', array( $this, 'queue_product' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'queue_product' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_product' ), 20, 1 );
		add_action( self::CATEGORY_HOOK, array( $this, 'run_category_batch' ), 10, 2 );
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

		if ( ! $this->settings->categories_imported() ) {
			return new WP_Error( 'categories_required', __( 'Import categories successfully before importing products.', 'woo-sortillus-lite' ) );
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

		// Unique is OK here: nothing with this hook/group should be running yet.
		$action_id = $this->schedule_import( $run_id, 0, 0, $this->batch_key( $run_id, 0 ), 0, true );
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

	public function start_category_import() {
		if ( ! $this->settings->is_connected() ) {
			return new WP_Error( 'not_connected', __( 'Connect to Sortillus before importing categories.', 'woo-sortillus-lite' ) );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'scheduler_missing', __( 'WooCommerce Action Scheduler is unavailable.', 'woo-sortillus-lite' ) );
		}
		foreach ( array( $this->settings->get_category_state(), $this->settings->get_sync_state() ) as $current ) {
			if ( in_array( $current['status'] ?? '', array( 'queued', 'running' ), true ) ) {
				return new WP_Error( 'sync_running', __( 'An import is already running.', 'woo-sortillus-lite' ) );
			}
		}

		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		// Parents must be saved before children, even across batch boundaries.
		$pending = array();
		foreach ( $terms as $term ) {
			$pending[ (int) $term->term_id ] = $term;
		}
		$ordered = array();
		while ( $pending ) {
			$before = count( $pending );
			foreach ( $pending as $id => $term ) {
				if ( 0 === (int) $term->parent || isset( $ordered[ (int) $term->parent ] ) ) {
					$ordered[ $id ] = $id;
					unset( $pending[ $id ] );
				}
			}
			if ( count( $pending ) === $before ) {
				return new WP_Error( 'category_hierarchy', __( 'A category has a missing parent or a hierarchy cycle. Correct the WooCommerce categories and retry.', 'woo-sortillus-lite' ) );
			}
		}
		$state = array(
			'run_id' => wp_generate_uuid4(),
			'status' => $ordered ? 'queued' : 'succeeded',
			'total' => count( $ordered ),
			'processed' => 0,
			'term_ids' => array_values( $ordered ),
			'last_error' => null,
		);
		$this->settings->set_category_state( $state );
		if ( $ordered && ! $this->schedule_category_batch( $state, 0 ) ) {
			return $this->fail_category_import( $state, __( 'Could not schedule category import. Retry the import.', 'woo-sortillus-lite' ) );
		}
		return $state;
	}

	public function run_category_batch( $run_id, $attempt = 0 ) {
		$state = $this->settings->get_category_state();
		if ( ( $state['run_id'] ?? '' ) !== $run_id || ! in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return;
		}
		$state['status'] = 'running';
		$this->settings->set_category_state( $state );
		$ids = array_slice( $state['term_ids'], (int) $state['processed'], self::BATCH_SIZE );
		$categories = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				$this->fail_category_import( $state, __( 'A category changed during import. Retry category import.', 'woo-sortillus-lite' ) );
				return;
			}
			$categories[] = $this->category_payload( $term );
		}
		$result = $this->client->send_categories( $categories, $run_id . ':categories:' . $state['processed'] );
		if ( is_wp_error( $result ) ) {
			if ( $this->retryable( $result ) && (int) $attempt < self::MAX_RETRY_COUNT &&
				$this->schedule_category_batch( $state, (int) $attempt + 1, $this->retry_delay( $result, $attempt ) ) ) {
				return;
			}
			$this->fail_category_import( $state, $result->get_error_message() );
			return;
		}
		$state['processed'] += count( $categories );
		if ( $state['processed'] >= $state['total'] ) {
			$state['status'] = 'succeeded';
			$state['finished_at'] = gmdate( 'c' );
			unset( $state['term_ids'] );
		}
		$this->settings->set_category_state( $state );
		if ( 'succeeded' !== $state['status'] && ! $this->schedule_category_batch( $state, 0 ) ) {
			$this->fail_category_import( $state, __( 'Could not schedule the next category batch. Retry category import.', 'woo-sortillus-lite' ) );
		}
	}

	private function category_payload( $term ) {
		return array(
			'external_id' => (int) $term->term_id,
			'external_parent_id' => (int) $term->parent,
			'name' => $term->name,
			'description' => $term->description,
			'source_locale' => get_locale(),
			'active' => true,
		);
	}

	public function queue_category( $term_id ) {
		$term_id = absint( $term_id );
		if ( ! $term_id || ! $this->settings->is_connected() ) {
			return;
		}
		// Each save gets an action, including edits made while an earlier send is retrying.
		if ( ! $this->schedule_category_delta( $term_id, 0, wp_generate_uuid4(), 5 ) ) {
			$this->record_category_delta( __( 'Could not schedule the category update. Save the category again to retry.', 'woo-sortillus-lite' ) );
		}
	}

	public function run_category_delta( $term_id, $attempt, $idempotency_key ) {
		if ( ! $this->settings->is_connected() ) {
			return;
		}
		$term_id = absint( $term_id );
		$category_id = $term_id;
		$categories = array();
		while ( $term_id ) {
			if ( isset( $categories[ $term_id ] ) ) {
				$this->record_category_delta( __( 'A category has a hierarchy cycle. Correct the WooCommerce categories and save again.', 'woo-sortillus-lite' ) );
				return;
			}
			$term = get_term( $term_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				// A category deleted before its queued send no longer needs an update.
				if ( $categories || is_wp_error( $term ) ) {
					$this->record_category_delta( __( 'Could not read the category hierarchy. Correct the WooCommerce categories and save again.', 'woo-sortillus-lite' ) );
				}
				return;
			}
			$categories[ $term_id ] = $this->category_payload( $term );
			$term_id = (int) $term->parent;
		}
		if ( ! $categories ) {
			return;
		}
		// Ancestors may not have reached Sortillus yet. Upsert them before the child.
		foreach ( array_chunk( array_reverse( $categories ), self::BATCH_SIZE ) as $batch ) {
			// Reload current values on every attempt; changed payloads need a new key.
			$key = $idempotency_key . ':' . hash( 'sha256', wp_json_encode( $batch ) );
			$result = $this->client->send_categories( $batch, $key );
			if ( is_wp_error( $result ) ) {
				if ( $this->retryable( $result ) && (int) $attempt < self::MAX_RETRY_COUNT &&
					$this->schedule_category_delta( $category_id, (int) $attempt + 1, $idempotency_key, $this->retry_delay( $result, $attempt ) ) ) {
					return;
				}
				$this->record_category_delta( $result->get_error_message() );
				return;
			}
		}
		$this->record_category_delta( null );
	}

	private function schedule_category_delta( $term_id, $attempt, $idempotency_key, $delay ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$action = as_schedule_single_action( time() + $delay, self::CATEGORY_DELTA_HOOK, array( $term_id, $attempt, $idempotency_key ), self::GROUP, false );
		return $action && ! is_wp_error( $action );
	}

	private function record_category_delta( $error ) {
		$state = $this->settings->get_category_state();
		$state['last_delta_at'] = gmdate( 'c' );
		$state['last_delta_error'] = $error;
		$this->settings->set_category_state( $state );
	}

	private function schedule_category_batch( array $state, $attempt, $delay = 1 ) {
		$action = as_schedule_single_action( time() + $delay, self::CATEGORY_HOOK, array( $state['run_id'], $attempt ), self::GROUP, false );
		return $action && ! is_wp_error( $action );
	}

	private function fail_category_import( array $state, $message ) {
		$state['status'] = 'failed';
		$state['last_error'] = $message;
		unset( $state['term_ids'] );
		$this->settings->set_category_state( $state );
		return new WP_Error( 'category_import_failed', $message );
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
		// Do not use unique=true: AS uniqueness is hook+group only, so one in-flight
		// delta would block every other product update.
		$action_id = as_schedule_single_action(
			time() + 5,
			self::DELTA_HOOK,
			array( $product_id, 0, wp_generate_uuid4() ),
			self::GROUP,
			false
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
			// Must not be unique: this action is still running, and AS unique = hook+group.
			$action_id = $this->schedule_import( $run_id, $cursor, (int) $attempt + 1, $idempotency_key, $delay, false );
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

		// Must not be unique while this batch action is still in-progress: Action Scheduler
		// treats unique as hook+group only, so unique=true returns 0 and aborts the import.
		$action_id = $this->schedule_import(
			$run_id,
			$state['cursor'],
			0,
			$this->batch_key( $run_id, $state['cursor'] ),
			1,
			false
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
				false
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

	private function schedule_import( $run_id, $cursor, $attempt, $idempotency_key, $delay, $unique = false ) {
		return as_schedule_single_action(
			time() + max( 0, (int) $delay ),
			self::IMPORT_HOOK,
			array( $run_id, (int) $cursor, (int) $attempt, $idempotency_key ),
			self::GROUP,
			(bool) $unique
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
