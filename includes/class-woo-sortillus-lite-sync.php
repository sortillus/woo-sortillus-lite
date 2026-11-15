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
	const REMOVAL_HOOK    = 'woo_sortillus_lite_remove_product';
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
		add_action( 'transition_post_status', array( $this, 'queue_removal_on_unpublish' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'queue_removal_on_delete' ), 20, 1 );
		add_action( self::REMOVAL_HOOK, array( $this, 'run_product_removal' ), 10, 3 );
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
			'skipped'                 => 0,
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

		$offers   = array();
		$sent_ids = array();
		$skipped  = array();
		$invalid  = 0;
		foreach ( $product_ids as $product_id ) {
			$offer = $this->builder->build( $product_id );
			if ( null === $offer ) {
				++$invalid;
				continue;
			}
			$missing = $this->builder->missing_fields( $offer );
			if ( $missing ) {
				$skipped[ $product_id ] = $missing;
				continue;
			}
			$offers[]   = $offer;
			$sent_ids[] = $product_id;
		}
		// Incomplete products are removed in case an earlier version sent them.
		// Removals follow the offers, so result indexes below count offers only.
		$items = $offers;
		foreach ( array_keys( $skipped ) as $product_id ) {
			$items[] = $this->builder->removal( $product_id );
		}

		$result = empty( $items ) ? array() : $this->client->send_offers( $items, $idempotency_key );
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
		$state['rejected']  = (int) $state['rejected'] + $invalid + count( $skipped );
		$state['skipped']   = (int) ( $state['skipped'] ?? 0 ) + count( $skipped );
		foreach ( $skipped as $product_id => $missing ) {
			$this->settings->record_product_issue( $product_id, array( 'reason' => 'incomplete', 'fields' => $missing ) );
		}
		if ( is_wp_error( $result ) ) {
			$state['rejected']   = (int) $state['rejected'] + count( $offers );
			$state['last_error'] = $result->get_error_message();
			$state['errors'][]   = $result->get_error_message();
		} else {
			$failed            = $this->record_batch_results( $sent_ids, $result );
			$state['accepted'] = (int) $state['accepted'] + count( $offers ) - $failed;
			$state['rejected'] = (int) $state['rejected'] + $failed;
			if ( $failed > 0 ) {
				$state['last_error'] = __( 'Sortillus did not accept some products. See Products that need attention.', 'woo-sortillus-lite' );
				$state['errors'][]   = $state['last_error'];
			}
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
		$missing = $this->builder->missing_fields( $offer );

		$started = gmdate( 'c' );
		// An incomplete product is removed in case it was sent before.
		$payload = $missing ? $this->builder->removal( $product_id ) : $offer;
		$result  = $this->client->send_offer( $payload, $idempotency_key );
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

		if ( $missing ) {
			$this->settings->record_product_issue( $product_id, array( 'reason' => 'incomplete', 'fields' => $missing ) );
			$error = sprintf(
				/* translators: %s: comma-separated list of missing offer fields */
				__( 'Not sent to Sortillus. Missing: %s', 'woo-sortillus-lite' ),
				implode( ', ', $missing )
			);
		} else {
			$error = $this->record_offer_result( $product_id, $result );
		}
		delete_transient( $lock_key );
		$this->finish_delta( $product_id, $idempotency_key, $started, $error );
	}

	public function queue_removal_on_unpublish( $new_status, $old_status, $post ) {
		if ( is_object( $post ) && 'product' === ( $post->post_type ?? '' ) && 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->queue_removal( (int) $post->ID );
		}
	}

	// Trashing unpublishes first; this covers deleting a published product outright.
	public function queue_removal_on_delete( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
			$this->queue_removal( (int) $post_id );
		}
	}

	private function queue_removal( $product_id ) {
		if ( $product_id <= 0 || ! $this->settings->is_connected() || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$action_id = as_schedule_single_action( time() + 5, self::REMOVAL_HOOK, array( $product_id, 0, wp_generate_uuid4() ), self::GROUP, false );
		if ( ! $action_id || is_wp_error( $action_id ) ) {
			$state                     = $this->settings->get_sync_state();
			$state['last_delta_error'] = __( 'Could not schedule the removal of an unpublished product.', 'woo-sortillus-lite' );
			$this->settings->set_sync_state( $state );
		}
	}

	public function run_product_removal( $product_id, $attempt, $idempotency_key ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! $this->settings->is_connected() ) {
			return;
		}
		// A product published again before this ran keeps its offer.
		if ( 'publish' === get_post_status( $product_id ) ) {
			return;
		}
		$started = gmdate( 'c' );
		$result  = $this->client->send_offer( $this->builder->removal( $product_id ), $idempotency_key );
		if ( is_wp_error( $result ) && $this->retryable( $result ) && (int) $attempt < self::MAX_RETRY_COUNT ) {
			$action_id = as_schedule_single_action(
				time() + $this->retry_delay( $result, (int) $attempt ),
				self::REMOVAL_HOOK,
				array( $product_id, (int) $attempt + 1, $idempotency_key ),
				self::GROUP,
				false
			);
			if ( $action_id && ! is_wp_error( $action_id ) ) {
				return;
			}
		}
		$error = $this->removed( $result ) ? null : $result->get_error_message();
		if ( null === $error ) {
			$this->settings->clear_product_issue( $product_id );
		}
		$this->finish_delta( $product_id, $idempotency_key, $started, $error );
	}

	// Sortillus answers offer_not_found when it never had the offer: nothing to remove.
	private function removed( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return true;
		}
		$data = $result->get_error_data();
		return is_array( $data ) && 'offer_not_found' === ( $data['error_code'] ?? null );
	}

	// Per-offer results of a batch: errors carry the index of the offer.
	private function record_batch_results( array $product_ids, array $result ) {
		$errors = array();
		foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && isset( $error['index'] ) ) {
				$errors[ (int) $error['index'] ] = $error;
			}
		}
		$failed = 0;
		foreach ( array_values( $product_ids ) as $index => $product_id ) {
			if ( isset( $errors[ $index ] ) ) {
				++$failed;
				$this->settings->record_product_issue( $product_id, $this->issue_from_response( $errors[ $index ] ) );
			} else {
				$this->settings->clear_product_issue( $product_id );
			}
		}
		return $failed;
	}

	// Returns the delta error to show, or null when Sortillus saved the offer.
	private function record_offer_result( $product_id, $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$this->settings->record_product_issue(
				$product_id,
				array(
					'reason'     => 'rejected',
					'message'    => $result->get_error_message(),
					'fields'     => is_array( $data ) ? (array) ( $data['missing_fields'] ?? array() ) : array(),
					'error_code' => is_array( $data ) ? ( $data['error_code'] ?? null ) : null,
				)
			);
			return $result->get_error_message();
		}
		if ( 'needs_review' === ( $result['status'] ?? null ) ) {
			$issue = $this->issue_from_response( $result );
			$this->settings->record_product_issue( $product_id, $issue );
			/* translators: %s: Sortillus review error code */
			return sprintf( __( 'Held for review by Sortillus: %s', 'woo-sortillus-lite' ), (string) $issue['error_code'] );
		}
		$this->settings->clear_product_issue( $product_id );
		return null;
	}

	private function issue_from_response( array $response ) {
		if ( 'needs_review' === ( $response['status'] ?? null ) ) {
			return array( 'reason' => 'needs_review', 'error_code' => (string) ( $response['error_code'] ?? '' ) );
		}
		return array(
			'reason'  => 'rejected',
			'message' => is_string( $response['error'] ?? null ) ? $response['error'] : '',
			'fields'  => (array) ( $response['missing_fields'] ?? array() ),
		);
	}

	private function finish_delta( $product_id, $idempotency_key, $started, $error ) {
		$state                     = $this->settings->get_sync_state();
		$state['last_delta_at']    = gmdate( 'c' );
		$state['last_delta_error'] = $error;
		$this->settings->set_sync_state( $state );

		$this->client->report_sync(
			array(
				'kind'             => 'delta',
				'status'           => null === $error ? 'succeeded' : 'failed',
				'idempotency_key'  => $idempotency_key,
				'received_count'   => 1,
				'accepted_count'   => null === $error ? 1 : 0,
				'rejected_count'   => null === $error ? 0 : 1,
				'started_at'       => $started,
				'finished_at'      => gmdate( 'c' ),
				'errors'           => null === $error ? array() : array( $error ),
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
