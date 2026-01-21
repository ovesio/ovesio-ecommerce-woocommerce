<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovesio_Ecommerce {

	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'init', array( $this, 'handle_feed_request' ) );
	}

	public static function activate() {
		if ( ! get_option( 'ovesio_ecommerce_hash' ) ) {
			update_option( 'ovesio_ecommerce_hash', md5( uniqid( wp_rand(), true ) ) );
		}
		add_option( 'ovesio_ecommerce_status', 'no' );
		add_option( 'ovesio_ecommerce_export_duration', '12' );
		add_option( 'ovesio_ecommerce_order_states', array() );
	}

	public static function deactivate() {
		// Optional: Clean up options if needed.
	}

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Ovesio Ecommerce Intelligence',
			'Ovesio Ecommerce Intelligence',
			'manage_options',
			'ovesio-ecommerce-for-woocommerce',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'ovesio_ecommerce_options', 'ovesio_ecommerce_status', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'ovesio_ecommerce_options', 'ovesio_ecommerce_export_duration', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'ovesio_ecommerce_options', 'ovesio_ecommerce_order_states', array( 'sanitize_callback' => array( $this, 'sanitize_order_states' ) ) );
	}

	public function sanitize_order_states( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', $input );
	}

	public function enqueue_admin_scripts( $hook ) {
		// Only load on our page
		if ( $hook !== 'woocommerce_page_ovesio-ecommerce-for-woocommerce' ) {
			return;
		}

		wp_enqueue_style( 'ovesio-admin-css', OVESIO_ECOMMERCE_PLUGIN_URL . 'assets/admin.css', array(), OVESIO_ECOMMERCE_VERSION );
		wp_enqueue_script( 'ovesio-admin-js', OVESIO_ECOMMERCE_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), OVESIO_ECOMMERCE_VERSION, true );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hash = get_option( 'ovesio_ecommerce_hash' );
        if( ! $hash ) {
            $hash = md5( uniqid( wp_rand(), true ) );
			update_option( 'ovesio_ecommerce_hash', $hash );
        }

		$baseUrl = home_url( '/' );
		$productFeedUrl = add_query_arg( array( 'ovesio_feed' => '1', 'hash' => $hash, 'action' => 'products' ), $baseUrl );
		$orderFeedUrl   = add_query_arg( array( 'ovesio_feed' => '1', 'hash' => $hash, 'action' => 'orders' ), $baseUrl );
		$logoUrl = OVESIO_ECOMMERCE_PLUGIN_URL . 'assets/logo.png';
		?>
		<div class="wrap">
			<div class="ovesio-header">
				<img src="<?php echo esc_url( $logoUrl ); ?>" alt="Ovesio Logo" class="ovesio-logo">
				<div class="ovesio-title">
					<h2><?php esc_html_e( 'Configuration', 'ovesio-ecommerce-for-woocommerce' ); ?></h2>
				</div>
			</div>

			<div class="ovesio-panel">
				<div class="ovesio-intro">
					<p><strong><?php esc_html_e( 'Connect your store to Ovesio to unlock powerful capabilities:', 'ovesio-ecommerce-for-woocommerce' ); ?></strong><br>
					<?php esc_html_e( 'Stock Management, Forecasting, Pricing Strategy & more.', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
				</div>

				<div class="ovesio-alert ovesio-alert-info">
					<p><?php esc_html_e( 'Please configure the "Order Export Period" below and click Save.', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
					<p><?php esc_html_e( 'Then copy the following URLs and paste them into your Ovesio dashboard.', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
				</div>

				<form action="options.php" method="post">
					<?php
					settings_fields( 'ovesio_ecommerce_options' );
					do_settings_sections( 'ovesio_ecommerce_options' );
					?>
					<table class="form-table ovesio-form-table">
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Status', 'ovesio-ecommerce-for-woocommerce' ); ?></th>
							<td>
								<select name="ovesio_ecommerce_status">
									<option value="yes" <?php selected( get_option( 'ovesio_ecommerce_status' ), 'yes' ); ?>><?php esc_html_e( 'Enabled', 'ovesio-ecommerce-for-woocommerce' ); ?></option>
									<option value="no" <?php selected( get_option( 'ovesio_ecommerce_status' ), 'no' ); ?>><?php esc_html_e( 'Disabled', 'ovesio-ecommerce-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Order Export Period', 'ovesio-ecommerce-for-woocommerce' ); ?></th>
							<td>
								<select name="ovesio_ecommerce_export_duration">
									<option value="12" <?php selected( get_option( 'ovesio_ecommerce_export_duration' ), '12' ); ?>><?php esc_html_e( 'Last 12 Months', 'ovesio-ecommerce-for-woocommerce' ); ?></option>
									<option value="24" <?php selected( get_option( 'ovesio_ecommerce_export_duration' ), '24' ); ?>><?php esc_html_e( 'Last 24 Months', 'ovesio-ecommerce-for-woocommerce' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Choose the historical period for analysis.', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Order Statuses', 'ovesio-ecommerce-for-woocommerce' ); ?></th>
							<td>
								<?php
								$statuses = wc_get_order_statuses();
								$selected_statuses = get_option( 'ovesio_ecommerce_order_states', array() );
								if ( ! is_array( $selected_statuses ) ) {
									$selected_statuses = array();
								}
								?>
								<select name="ovesio_ecommerce_order_states[]" multiple="multiple" class="wc-enhanced-select regular-text" style="width: 300px;">
									<?php foreach ( $statuses as $status_key => $status_name ) : ?>
										<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( true, in_array( $status_key, $selected_statuses ) ); ?>>
											<?php echo esc_html( $status_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select the order statuses to export. Leave empty to use default (standard valid orders).', 'ovesio-ecommerce-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Data Feeds', 'ovesio-ecommerce-for-woocommerce' ); ?></h3>
					<table class="form-table ovesio-form-table">
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Product Feed URL', 'ovesio-ecommerce-for-woocommerce' ); ?></th>
							<td>
                                <div class="ovesio-input-group">
								    <input type="text" class="regular-text" id="product_feed_url" readonly value="<?php echo esc_url( $productFeedUrl ); ?>">
                                    <button class="button ovesio-copy-btn" data-target="product_feed_url"><?php esc_html_e( 'Copy', 'ovesio-ecommerce-for-woocommerce' ); ?></button>
                                </div>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Order Feed URL', 'ovesio-ecommerce-for-woocommerce' ); ?></th>
							<td>
                                <div class="ovesio-input-group">
								    <input type="text" class="regular-text" id="order_feed_url" readonly value="<?php echo esc_url( $orderFeedUrl ); ?>">
                                    <button class="button ovesio-copy-btn" data-target="order_feed_url"><?php esc_html_e( 'Copy', 'ovesio-ecommerce-for-woocommerce' ); ?></button>
                                </div>
							</td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>

				<div class="ovesio-alert ovesio-alert-warning">
					<strong><?php esc_html_e( 'Security Hash:', 'ovesio-ecommerce-for-woocommerce' ); ?></strong> <?php echo esc_html( $hash ); ?><br>
					<?php esc_html_e( 'If you uninstall and reinstall this module, this hash will change and you will need to update your URLs in Ovesio.', 'ovesio-ecommerce-for-woocommerce' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_feed_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ovesio_feed'] ) && $_GET['ovesio_feed'] == '1' ) {
			header( 'Content-Type: application/json' );

			if ( get_option( 'ovesio_ecommerce_status' ) !== 'yes' ) {
				status_header( 403 );
				echo wp_json_encode( array( 'error' => 'Module is disabled' ) );
				exit;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
			$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
			$configuredHash = get_option( 'ovesio_ecommerce_hash' );

			if ( empty( $configuredHash ) || $hash !== $configuredHash ) {
				status_header( 403 );
				echo wp_json_encode( array( 'error' => 'Access denied: Invalid Hash' ) );
				exit;
			}

			require_once OVESIO_ECOMMERCE_PLUGIN_DIR . 'includes/class-ovesio-export.php';
			$exporter = new Ovesio_Ecommerce_Export();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
			$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'products';

			if ( $action == 'orders' ) {
				$duration = (int) get_option( 'ovesio_ecommerce_export_duration', 12 );
				$data = $exporter->get_orders_export( $duration );
				$this->output_json( $data, 'orders' );
			} else {
				$data = $exporter->get_products_export();
				$this->output_json( $data, 'products' );
			}
		}
	}

	private function output_json( $data, $type ) {
		$filename = "export_" . $type . "_" . gmdate( 'Y-m-d' ) . ".json";
		header( 'Content-Disposition: attachment; filename="' . $filename . '";' );
		echo wp_json_encode( array( 'data' => $data ), JSON_PRETTY_PRINT );
		exit;
	}
}
