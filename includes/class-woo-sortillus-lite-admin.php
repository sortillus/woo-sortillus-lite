<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Admin {
	private $settings;
	private $client;
	private $sync;

	public function __construct(
		Woo_Sortillus_Lite_Settings $settings,
		Woo_Sortillus_Lite_Client $client,
		Woo_Sortillus_Lite_Sync $sync
	) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->sync     = $sync;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_woo_sortillus_lite_activate', array( $this, 'activate' ) );
		add_action( 'admin_post_woo_sortillus_lite_save_assistant', array( $this, 'save_assistant' ) );
		add_action( 'wp_ajax_woo_sortillus_lite_import_categories', array( $this, 'import_categories' ) );
		add_action( 'wp_ajax_woo_sortillus_lite_start_import', array( $this, 'start_import' ) );
		add_action( 'wp_ajax_woo_sortillus_lite_sync_status', array( $this, 'sync_status' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Sortillus Lite', 'woo-sortillus-lite' ),
			__( 'Sortillus Lite', 'woo-sortillus-lite' ),
			'manage_options',
			'woo-sortillus-lite',
			array( $this, 'render' ),
			'dashicons-format-chat',
			56
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_woo-sortillus-lite' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'woo-sortillus-lite-admin',
			WOO_SORTILLUS_LITE_URL . 'assets/admin.js',
			array(),
			WOO_SORTILLUS_LITE_VERSION,
			true
		);
		wp_localize_script(
			'woo-sortillus-lite-admin',
			'wooSortillusLiteAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'woo_sortillus_lite_sync' ),
				'i18n'    => array(
					'categoriesFirst' => __( 'Import categories first to enable product import.', 'woo-sortillus-lite' ),
					'categoriesDone' => __( 'Categories imported. You can now import products.', 'woo-sortillus-lite' ),
					'categories' => __( 'categories imported', 'woo-sortillus-lite' ),
					'importCategories' => __( 'Import Categories', 'woo-sortillus-lite' ),
					'never'       => __( 'Products have not been imported yet.', 'woo-sortillus-lite' ),
					'starting'    => __( 'Starting import…', 'woo-sortillus-lite' ),
					'failed'      => __( 'Could not start the import.', 'woo-sortillus-lite' ),
					'products'    => __( 'products processed', 'woo-sortillus-lite' ),
					'importAgain' => __( 'Import Products', 'woo-sortillus-lite' ),
				),
			)
		);
	}

	public function activate() {
		$this->authorize_admin_action( 'woo_sortillus_lite_activate' );
		$token = isset( $_POST['activation_token'] ) ? sanitize_text_field( wp_unslash( $_POST['activation_token'] ) ) : '';
		if ( '' === trim( $token ) ) {
			$this->redirect( 'error', __( 'Enter an activation token.', 'woo-sortillus-lite' ) );
		}

		$result = $this->client->activate( $token );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		try {
			$this->settings->store_installation( $result );
			$this->settings->set_sync_state(
				array(
					'status'                  => 'idle',
					'total'                   => 0,
					'processed'               => 0,
					'accepted'                => 0,
					'rejected'                => 0,
					'last_successful_sync_at' => null,
					'last_error'              => null,
				)
			);
		} catch ( Throwable $error ) {
			$this->redirect( 'error', $error->getMessage() );
		}
		$this->redirect( 'success', __( 'Connected to Sortillus.', 'woo-sortillus-lite' ) );
	}

	public function save_assistant() {
		$this->authorize_admin_action( 'woo_sortillus_lite_save_assistant' );
		$this->settings->set_assistant_enabled( isset( $_POST['assistant_enabled'] ) );
		$this->redirect( 'success', __( 'Assistant setting saved.', 'woo-sortillus-lite' ) );
	}

	public function start_import() {
		$this->authorize_ajax();
		$result = $this->sync->start_import();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'state' => $result, 'category_state' => $this->category_status() ) );
	}

	public function import_categories() {
		$this->authorize_ajax();
		$result = $this->sync->start_category_import();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'state' => $this->settings->get_sync_state(), 'category_state' => $this->category_status() ) );
	}

	private function category_status() {
		$state = $this->settings->get_category_state();
		unset( $state['term_ids'] );
		return $state;
	}

	public function sync_status() {
		$this->authorize_ajax();
		$payload = array( 'state' => $this->settings->get_sync_state(), 'category_state' => $this->category_status() );
		if ( ! empty( $_POST['include_health'] ) && $this->settings->is_connected() ) {
			$health = $this->client->health();
			if ( is_wp_error( $health ) ) {
				$payload['health_error'] = $health->get_error_message();
			} else {
				$payload['health'] = $health;
			}
		}
		wp_send_json_success( $payload );
	}

	public function render() {
		if ( ! $this->can_manage() ) {
			return;
		}
		$connected = $this->settings->is_connected();
		$state     = $this->settings->get_sync_state();
		?>
		<div class="wrap woo-sortillus-lite-admin">
			<h1><?php esc_html_e( 'Sortillus Lite', 'woo-sortillus-lite' ); ?></h1>
			<?php $this->render_notice(); ?>

			<div class="woo-sortillus-lite-card">
				<h2><?php esc_html_e( 'Connection', 'woo-sortillus-lite' ); ?></h2>
				<p><strong><?php esc_html_e( 'Status:', 'woo-sortillus-lite' ); ?></strong>
					<?php echo $connected ? esc_html__( 'Connected', 'woo-sortillus-lite' ) : esc_html__( 'Not connected', 'woo-sortillus-lite' ); ?>
				</p>
				<?php if ( ! $connected ) : ?>
					<p><a class="button" href="https://admin.sortillus.com/users/sign_up" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Register or sign in to Sortillus', 'woo-sortillus-lite' ); ?></a></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="woo_sortillus_lite_activate">
					<?php wp_nonce_field( 'woo_sortillus_lite_activate' ); ?>
					<label for="woo-sortillus-lite-token"><strong><?php echo $connected ? esc_html__( 'Reconnect with a new activation token', 'woo-sortillus-lite' ) : esc_html__( 'Activation token', 'woo-sortillus-lite' ); ?></strong></label><br>
					<input id="woo-sortillus-lite-token" class="regular-text" type="password" name="activation_token" autocomplete="off" required>
					<button class="button button-primary" type="submit"><?php echo $connected ? esc_html__( 'Reconnect', 'woo-sortillus-lite' ) : esc_html__( 'Activate', 'woo-sortillus-lite' ); ?></button>
				</form>
			</div>

			<?php if ( $connected ) : ?>
				<div class="woo-sortillus-lite-card">
					<h2><?php esc_html_e( 'Category Import', 'woo-sortillus-lite' ); ?></h2>
					<p id="woo-sortillus-lite-category-status" aria-live="polite"></p>
					<p id="woo-sortillus-lite-category-error" class="woo-sortillus-lite-error" role="alert"></p>
					<button id="woo-sortillus-lite-import-categories" class="button button-primary" type="button"><?php esc_html_e( 'Import Categories', 'woo-sortillus-lite' ); ?></button>
				</div>
				<div class="woo-sortillus-lite-card">
					<h2><?php esc_html_e( 'Product Sync Status', 'woo-sortillus-lite' ); ?></h2>
					<p><strong><?php esc_html_e( 'State:', 'woo-sortillus-lite' ); ?></strong> <span id="woo-sortillus-lite-state"><?php echo esc_html( $state['status'] ?? 'idle' ); ?></span></p>
					<div class="woo-sortillus-lite-progress"><span id="woo-sortillus-lite-progress-bar"></span></div>
					<p id="woo-sortillus-lite-progress-text"></p>
					<p id="woo-sortillus-lite-sync-details"></p>
					<p id="woo-sortillus-lite-sync-error" class="woo-sortillus-lite-error"></p>
					<p id="woo-sortillus-lite-health"></p>
					<button id="woo-sortillus-lite-import" class="button button-primary" type="button" <?php if ( ! $this->settings->categories_imported() ) : ?>hidden<?php endif; ?>><?php esc_html_e( 'Import Products', 'woo-sortillus-lite' ); ?></button>
				</div>

				<div class="woo-sortillus-lite-card">
					<h2><?php esc_html_e( 'Shopping Assistant', 'woo-sortillus-lite' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="woo_sortillus_lite_save_assistant">
						<?php wp_nonce_field( 'woo_sortillus_lite_save_assistant' ); ?>
						<label>
							<input type="checkbox" name="assistant_enabled" value="1" <?php checked( $this->settings->assistant_enabled() ); ?>>
							<?php esc_html_e( 'Show the Sortillus assistant/chat button on the storefront', 'woo-sortillus-lite' ); ?>
						</label>
						<p><button class="button" type="submit"><?php esc_html_e( 'Save', 'woo-sortillus-lite' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<style>
			.woo-sortillus-lite-card{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04);margin:18px 0;max-width:760px;padding:20px}
			.woo-sortillus-lite-progress{background:#dcdcde;border-radius:5px;height:10px;max-width:520px;overflow:hidden}
			.woo-sortillus-lite-progress span{background:#2271b1;display:block;height:100%;transition:width .2s;width:0}
			.woo-sortillus-lite-card [hidden]{display:none!important}
			.woo-sortillus-lite-error{color:#b32d2e}
		</style>
		<?php
	}

	private function render_notice() {
		$type    = isset( $_GET['sortillus_notice'] ) ? sanitize_key( wp_unslash( $_GET['sortillus_notice'] ) ) : '';
		$message = isset( $_GET['sortillus_message'] ) ? sanitize_text_field( wp_unslash( $_GET['sortillus_message'] ) ) : '';
		if ( ! $type || ! $message ) {
			return;
		}
		$class = 'success' === $type ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function authorize_admin_action( $nonce_action ) {
		if ( ! $this->can_manage() ) {
			wp_die(
				esc_html__( 'Unauthorized.', 'woo-sortillus-lite' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $nonce_action );
	}

	private function authorize_ajax() {
		if ( ! $this->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'woo-sortillus-lite' ) ), 403 );
		}
		check_ajax_referer( 'woo_sortillus_lite_sync', 'nonce' );
	}

	private function can_manage() {
		return current_user_can( 'manage_options' );
	}

	private function redirect( $type, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'woo-sortillus-lite',
					'sortillus_notice'  => $type,
					'sortillus_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
