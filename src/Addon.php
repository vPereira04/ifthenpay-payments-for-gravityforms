<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\GravityForms\Api\GFFormData;
use Ifthenpay\GravityForms\Api\IfthenpayClient;
use Ifthenpay\GravityForms\Api\IfthenpayEmailHelper;
use Ifthenpay\GravityForms\Api\IfthenpayPayload;
use Ifthenpay\GravityForms\Api\IfthenpayReturn;

require_once IFTP_GF_DIR . 'src/Api/IfthenpayClient.php';
require_once IFTP_GF_DIR . 'src/Api/IfthenpayPayload.php';
require_once IFTP_GF_DIR . 'src/Api/IfthenpayReturn.php';
require_once IFTP_GF_DIR . 'src/Api/IfthenpayEmailHelper.php';
require_once IFTP_GF_DIR . 'src/Api/GFFormData.php';
require_once IFTP_GF_DIR . 'src/Field/GF_Field_Ifthenpay.php';

class Addon extends \GFPaymentAddOn {

	// -------------------------------------------------------------------------
	// Properties
	// -------------------------------------------------------------------------

	protected $_version                  = IFTP_GF_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'iftp_gf';
	protected $_path                     = 'ifthenpay-payments-for-gravityforms/ifthenpay-payments-for-gravityforms.php';
	protected $_full_path                = IFTP_GF_FILE;
	protected $_title                    = 'Gravity Forms Ifthenpay Add-On';
	protected $_short_title              = 'ifthenpay';
	protected $_supports_callbacks       = false;
	protected $_requires_credit_card     = false;

	private static ?self $_instance = null;

	private const OPTION_BACKOFFICE_KEY  = 'iftp_gf_backofficekey';
	private const OPTION_GATEWAY_CATALOG = 'iftp_gf_gateway_catalog';
	private const OPTION_METHOD_CATALOG  = 'iftp_gf_method_catalog';
	private const SIGNUP_URL             = 'https://ifthenpay.com';

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	public static function get_instance(): self {
		if ( self::$_instance === null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	// -------------------------------------------------------------------------
	// Menu icon
	// -------------------------------------------------------------------------

	public function get_menu_icon(): string {
		return $this->is_gravityforms_supported( '2.5-beta-4' ) ? 'ifthenpay-icon' : 'dashicons-admin-generic';
	}

	// -------------------------------------------------------------------------
	// Initialization
	// -------------------------------------------------------------------------

	public function init(): void {
		parent::init();

		\GF_Fields::register( new \Ifthenpay\GravityForms\Field\GF_Field_Ifthenpay() );
		add_action( 'wp', [ $this, 'handle_gateway_return' ] );
		add_action( 'wp', [ $this, 'handle_popup_result' ] );
	}

	public function init_admin(): void {
		parent::init_admin();
	}

	public function init_ajax(): void {
		parent::init_ajax();

		add_action( 'wp_ajax_iftp_gf_connect_backoffice',    [ $this, 'ajax_connect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_disconnect_backoffice', [ $this, 'ajax_disconnect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_load_gateway_methods',  [ $this, 'ajax_load_gateway_methods' ] );
		add_action( 'wp_ajax_iftp_gf_activate_method',       [ $this, 'ajax_activate_payment_method' ] );
		// Table AJAX
		add_action('wp_ajax_iftp_gf_get_gateway_methods',[ $this, 'ajax_get_gateway_methods' ]);
		// Payment creation + status check — available to logged-in and guest users
		add_action( 'wp_ajax_iftp_gf_create_payment',        [ $this, 'ajax_create_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_gf_create_payment', [ $this, 'ajax_create_payment' ] );
		add_action( 'wp_ajax_iftp_gf_check_payment_status',        [ $this, 'ajax_check_payment_status' ] );
		add_action( 'wp_ajax_nopriv_iftp_gf_check_payment_status', [ $this, 'ajax_check_payment_status' ] );
	}

	// -------------------------------------------------------------------------
	// Scripts & Styles
	// -------------------------------------------------------------------------

	public function scripts(): array {
		$scripts = [
			[
				'handle'  => 'ifthenpay_gf_admin',
				'src'     => IFTP_GF_URL . 'assets/js/admin.js',
				'version' => IFTP_GF_VERSION,
				'deps'    => [ 'jquery' ],
				'strings' => [
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'iftp_gf_admin' ),
					'connecting'          => __( 'Connecting...', 'ifthenpay-payments-for-gravityforms' ),
					'disconnecting'       => __( 'Disconnecting...', 'ifthenpay-payments-for-gravityforms' ),
					'connect'             => __( 'Connect', 'ifthenpay-payments-for-gravityforms' ),
					'disconnect'          => __( 'Disconnect', 'ifthenpay-payments-for-gravityforms' ),
					'generic_error'       => __( 'Request failed. Please try again.', 'ifthenpay-payments-for-gravityforms' ),
					'loading_methods'     => __( 'Loading methods...', 'ifthenpay-payments-for-gravityforms' ),
					'activation_sent'     => __( 'Activation request sent.', 'ifthenpay-payments-for-gravityforms' ),
					'activation_cooldown' => __( 'Request already sent. Please wait 24 hours.', 'ifthenpay-payments-for-gravityforms' ),
				],
				'enqueue' => [
					[ 'admin_page' => [ 'plugin_settings' ] ],
					[ 'admin_page' => [ 'form_settings' ], 'tab' => $this->_slug ],
				],
			],
			[
				'handle'  => 'ifthenpay_gf_frontend',
				'src'     => IFTP_GF_URL . 'assets/js/frontend.js',
				'version' => IFTP_GF_VERSION,
				'deps'    => [],
				'strings' => [
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'generic_error'     => __( 'Request failed. Please try again.', 'ifthenpay-payments-for-gravityforms' ),
					'waiting'           => __( 'Waiting for payment confirmation…', 'ifthenpay-payments-for-gravityforms' ),
					'payment_success'   => __( 'Payment received. You can now submit the form.', 'ifthenpay-payments-for-gravityforms' ),
					'payment_cancelled' => __( 'Payment was not completed.', 'ifthenpay-payments-for-gravityforms' ),
					'popup_blocked'     => __( 'Could not open the payment window. Please allow pop-ups for this site and try again.', 'ifthenpay-payments-for-gravityforms' ),
					'popup_closed'      => __( 'Payment window was closed before completion.', 'ifthenpay-payments-for-gravityforms' ),
					'pay_first'         => __( 'Please complete the payment before submitting the form.', 'ifthenpay-payments-for-gravityforms' ),
					'amount_zero'       => __( 'Please select a product before paying.', 'ifthenpay-payments-for-gravityforms' ),
					'no_gateway'        => __( 'Payment gateway is not configured.', 'ifthenpay-payments-for-gravityforms' ),
					'paid_label'        => __( 'Paid ✓', 'ifthenpay-payments-for-gravityforms' ),
				],
				'enqueue' => [
					[ 'field_types' => [ 'iftp_pbl' ] ],
				],
			],
		];

		return array_merge( parent::scripts(), $scripts );
	}

	public function styles(): array {
		$styles = [
			[
				'handle'  => 'ifthenpay-gf-admin',
				'src'     => IFTP_GF_URL . 'assets/css/admin.css',
				'version' => IFTP_GF_VERSION,
				'enqueue' => [
					[ 'admin_page' => [ 'plugin_settings' ] ],
					[ 'admin_page' => [ 'form_settings' ], 'tab' => $this->_slug ],
				],
			],
			[
				'handle'  => 'ifthenpay-gf-frontend',
				'src'     => IFTP_GF_URL . 'assets/css/frontend.css',
				'version' => IFTP_GF_VERSION,
				'enqueue' => [
					[ 'field_types' => [ 'iftp_pbl' ] ],
				],
			],
		];

		return array_merge( parent::styles(), $styles );
	}

	// -------------------------------------------------------------------------
	// Plugin settings (global — backoffice key)
	// -------------------------------------------------------------------------

	public function plugin_settings_fields(): array {
		return [
			[
				'title'  => __( 'ifthenpay | Payment Gateway', 'ifthenpay-payments-for-gravityforms' ),
				'fields' => [
					[
						'type'     => 'iftp_gf_backoffice_connection',
						'name'     => 'iftp_gf_connection_ui',
						'no_label' => true,
					],
				],
			],
		];
	}

	/**
	 * Custom settings field renderer for the full backoffice connection UI.
	 */
	public function settings_iftp_gf_backoffice_connection( $field, bool $echo = true ): string {
		$is_connected = self::get_backoffice_key() !== '';
		$nonce = wp_create_nonce( 'iftp_gf_admin' );
		$masked_key   = $is_connected ? str_repeat( '*', 18 ) : '';

		ob_start();
		?>
		<p class="description">
			<?php esc_html_e( 'Connect your ifthenpay Backoffice Key to load your gateways. Gateway selection and payment methods are configured per form in feed settings.', 'ifthenpay-payments-for-gravityforms' ); ?>
			&nbsp;<a href="<?php echo esc_url( self::SIGNUP_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Not a client? Sign up here.', 'ifthenpay-payments-for-gravityforms' ); ?>
			</a>
		</p>

		<div class="iftp-gf-key-row<?php echo $is_connected ? ' iftp-gf-key-field-hidden' : ''; ?>">
			<input
				type="text"
				id="iftp-gf-backoffice-key-input"
				class="regular-text"
				placeholder="Insert your backoffice key here..."
				value="<?php echo esc_attr( $masked_key ); ?>"
				autocomplete="off"
			/>&#8203;
			<button type="button" id="iftp-gf-connect-backoffice" class="primary button large">
				<?php esc_html_e( 'Connect', 'ifthenpay-payments-for-gravityforms' ); ?>
			</button>
		</div>

		<div id="iftp-gf-connection-status-card">
			<?php if ( $is_connected ) : ?>
			<div class="alert gforms_note_success">
				<h4><?php esc_html_e( 'Connected', 'ifthenpay-payments-for-gravityforms' ); ?></h4>
				<p><?php esc_html_e( 'Your Backoffice Key is connected. Open any form\'s Settings → ifthenpay to configure gateway keys and payment methods.', 'ifthenpay-payments-for-gravityforms' ); ?></p>
				<button type="button" id="iftp-gf-disconnect-backoffice" class="button button-secondary">
					<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-gravityforms' ); ?>
				</button>
			</div>
			<?php endif; ?>
			<?php if ( $is_connected ) : ?>
			<?php
			$catalog = self::get_gateway_catalog();
			?>

			<div class="iftp-gf-gateway-selector">

				<label for="iftp-gf-settings-gateway">
					<?php esc_html_e(
						'Gateway',
						'ifthenpay-payments-for-gravityforms'
					); ?>
				</label>

				<select id="iftp-gf-settings-gateway">

					<option value="">
						<?php esc_html_e(
							'Select a gateway',
							'ifthenpay-payments-for-gravityforms'
						); ?>
					</option>

					<?php foreach ( $catalog as $gateway_key => $gateway ) : ?>

						<option value="<?php echo esc_attr( $gateway_key ); ?>">

							<?php echo esc_html(
								$gateway['label'] ?? $gateway_key
							); ?>

						</option>

					<?php endforeach; ?>

				</select>

			</div>

			<div id="iftp-gf-settings-methods-wrapper"></div>
			<?php endif; ?>
		</div>

		<p><span class="iftp-gf-message" aria-live="polite"></span></p>

		<input type="hidden" id="iftp-gf-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Feed settings (per-form gateway + methods config)
	// -------------------------------------------------------------------------

	public function feed_settings_fields(): array {
		return [
			[
				'title'  => __( 'Feed Settings', 'ifthenpay-payments-for-gravityforms' ),
				'fields' => [
					[
						'label'    => __( 'Feed Name', 'ifthenpay-payments-for-gravityforms' ),
						'type'     => 'text',
						'name'     => 'feedName',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => __( 'A name for this payment feed. Used to identify it in the feed list.', 'ifthenpay-payments-for-gravityforms' ),
					],
					[
						'label'   => __( 'Gateway Key', 'ifthenpay-payments-for-gravityforms' ),
						'type'    => 'select',
						'name'    => 'gateway_key',
						'tooltip' => __( 'Select the ifthenpay gateway key for this form.', 'ifthenpay-payments-for-gravityforms' ),
						'choices' => $this->get_gateway_key_choices(),
						'onchange' => 'iftpGfFeedSettings.onGatewayKeyChange(this)',
					],
					[
						'label'    => __( 'Payment Methods', 'ifthenpay-payments-for-gravityforms' ),
						'type'     => 'iftp_gf_methods_table',
						'name'     => 'methods_config',
						'no_label' => false,
						'tooltip'  => __( 'Enable the payment methods available on this gateway. Disabled methods will not appear at checkout.', 'ifthenpay-payments-for-gravityforms' ),
					],
					[
						'label'   => __( 'Default Method', 'ifthenpay-payments-for-gravityforms' ),
						'type'    => 'select',
						'name'    => 'default_method',
						'tooltip' => __( 'The payment method preselected when the customer opens the Pay By Link page.', 'ifthenpay-payments-for-gravityforms' ),
						'choices' => $this->get_default_method_choices(),
					],
					[
						'label'   => __( 'Payment Description', 'ifthenpay-payments-for-gravityforms' ),
						'type'    => 'text',
						'name'    => 'description',
						'class'   => 'medium',
						'tooltip' => __( 'Optional description shown on the payment page (e.g., the store or product name).', 'ifthenpay-payments-for-gravityforms' ),
					],
				],
			],
		];
	}

	/**
	 * Custom settings field renderer for the payment methods table.
	 */
	public function settings_iftp_gf_methods_table( $field, bool $echo = true ): string {
		$feed        = $this->get_active_feed_for_settings();
		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		$catalog     = self::get_gateway_catalog();
		$nonce       = wp_create_nonce( 'iftp_gf_admin' );

		$methods_config = [];
		if ( $feed && ! empty( $feed['meta']['methods_config'] ) ) {
			$raw = $feed['meta']['methods_config'];
			$methods_config = is_string( $raw ) ? (array) json_decode( $raw, true ) : (array) $raw;
		}

		$available_methods = [];
		if ( $gateway_key !== '' && isset( $catalog[ $gateway_key ]['methods'] ) ) {
			$available_methods = $catalog[ $gateway_key ]['methods'];
		}

		ob_start();
		?>
		<div id="iftp-gf-methods-table-wrapper" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( empty( $available_methods ) ) : ?>
				<p class="iftp-gf-no-methods">
					<?php esc_html_e( 'Select a gateway key above to load available payment methods.', 'ifthenpay-payments-for-gravityforms' ); ?>
				</p>
			<?php else : ?>
				<?php $this->render_methods_table( $available_methods, $methods_config ); ?>
			<?php endif; ?>
		</div>
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	private function render_methods_table( array $available_methods, array $methods_config ): void {
		?>
		<table class="iftp-gf-methods-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-gravityforms' ); ?></th>
					<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-gravityforms' ); ?></th>
					<th><?php esc_html_e( 'Account', 'ifthenpay-payments-for-gravityforms' ); ?></th>
					<th><?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $available_methods as $entity => $method ) : ?>
					<?php
					$entity_key   = strtoupper( (string) $entity );
					$is_enabled   = ! empty( $methods_config[ $entity_key ]['enabled'] );
					$account      = (string) ( $method['account'] ?? '' );
					$method_label = (string) ( $method['method'] ?? $entity_key );
					$field_name   = '_gform_setting_methods_config[' . esc_attr( $entity_key ) . '][enabled]';
					?>
					<tr data-entity="<?php echo esc_attr( $entity_key ); ?>">
						<td>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $field_name ); ?>"
								value="1"
								<?php checked( $is_enabled ); ?>
								class="iftp-gf-method-toggle"
								data-entity="<?php echo esc_attr( $entity_key ); ?>"
							/>
							<input
								type="hidden"
								name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][account]"
								value="<?php echo esc_attr( $account ); ?>"
							/>
						</td>
						<td><?php echo esc_html( $method_label ); ?></td>
						<td class="iftp-gf-account-col">
							<?php if ( $account !== '' ) : ?>
								<code><?php echo esc_html( $account ); ?></code>
							<?php else : ?>
								<em class="iftp-gf-no-account"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-gravityforms' ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $account === '' ) : ?>
								<button
									type="button"
									class="button button-small iftp-gf-activate-method"
									data-entity="<?php echo esc_attr( $entity_key ); ?>"
									data-gateway-key="<?php echo esc_attr( $this->get_setting( 'gateway_key' ) ?? '' ); ?>"
								>
									<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ); ?>
								</button>
							<?php else : ?>
								<span class="iftp-gf-active-badge">&#10003;</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function feed_list_message(): ?string {
		if ( self::get_backoffice_key() === '' ) {
			return sprintf(
				/* translators: %s: link to plugin settings page */
				__( 'To get started, %s.', 'ifthenpay-payments-for-gravityforms' ),
				'<a href="' . esc_url( $this->get_plugin_settings_url() ) . '">'
				. esc_html__( 'connect your ifthenpay Backoffice Key', 'ifthenpay-payments-for-gravityforms' )
				. '</a>'
			);
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	public function get_submission_data( $feed, $form, $entry ): array {
		$data = parent::get_submission_data( $feed, $form, $entry );

		if ( empty( $data['payment_amount'] ) || (float) $data['payment_amount'] <= 0 ) {
			$amount = GFFormData::resolve_amount( $form, $entry );
			if ( $amount > 0 ) {
				$data['payment_amount'] = $amount;
			}
		}

		return $data;
	}

	public function redirect_url( $feed, $submission_data, $form, $entry ): string {
		// Popup flow: payment was completed before form submission via the field widget.
		if ( $this->form_has_popup_field( $form ) ) {
			$this->finalize_popup_payment( $form, $entry );
			return '';
		}

		// Server-side redirect flow (forms without the iftp_pbl field).
		$backoffice_key = self::get_backoffice_key();
		if ( $backoffice_key === '' ) {
			$this->log_error( __METHOD__ . '(): No backoffice key configured.' );
			return '';
		}

		$gateway_key = (string) rgars( $feed, 'meta/gateway_key' );
		if ( $gateway_key === '' ) {
			$this->log_error( __METHOD__ . '(): No gateway key in feed.' );
			return '';
		}

		$amount = (float) ( $submission_data['payment_amount'] ?? 0 );
		if ( $amount <= 0 ) {
			$this->log_error( __METHOD__ . '(): Payment amount is 0.' );
			return '';
		}

		$entry_id   = (int) rgar( $entry, 'id' );
		$form_id    = (int) rgar( $form, 'id' );
		$source_url = (string) rgar( $entry, 'source_url' );
		$base_url   = $source_url !== '' ? $source_url : home_url( '/' );

		$urls = IfthenpayPayload::build_gateway_urls( $entry_id, $form_id, $base_url );

		$methods_config_raw = rgars( $feed, 'meta/methods_config' );
		$methods_config     = is_string( $methods_config_raw )
			? (array) json_decode( $methods_config_raw, true )
			: (array) $methods_config_raw;

		$accounts = IfthenpayPayload::build_accounts_string( $methods_config );

		$customer_email = GFFormData::get_customer_email( $form, $entry );
		$customer_name  = GFFormData::get_customer_name( $form, $entry );

		$payload = IfthenpayPayload::build_pay_by_link_payload( [
			'id'              => (string) $entry_id,
			'amount'          => $amount,
			'description'     => sanitize_text_field( (string) rgars( $feed, 'meta/description' ) ),
			'accounts'        => $accounts,
			'success_url'     => $urls['success_url'],
			'error_url'       => $urls['error_url'],
			'cancel_url'      => $urls['cancel_url'],
			'selected_method' => IfthenpayPayload::get_selected_method_code(
				[ 'default_method' => rgars( $feed, 'meta/default_method' ) ],
				$methods_config
			),
			'email'           => $customer_email,
			'name'            => $customer_name,
		] );

		try {
			$response = IfthenpayClient::create_payment_link( $gateway_key, $payload );
		} catch ( \RuntimeException $e ) {
			$this->log_error( __METHOD__ . '(): API error — ' . $e->getMessage() );
			return '';
		}

		$redirect_url   = (string) ( $response['url'] ?? $response['PaymentUrl'] ?? $response['paymentUrl'] ?? '' );
		$transaction_id = (string) ( $response['RequestId'] ?? $response['requestId'] ?? $response['transactionId'] ?? '' );

		if ( $redirect_url === '' ) {
			$this->log_error( __METHOD__ . '(): Empty redirect URL in API response.' );
			return '';
		}

		if ( $transaction_id !== '' ) {
			$this->insert_transaction( $entry_id, 'payment', $transaction_id, $amount );
			gform_update_meta( $entry_id, 'iftp_gf_transaction_id', $transaction_id );
			gform_update_meta( $entry_id, 'iftp_gf_gateway_key', $gateway_key );
		}

		gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'pending' );

		$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} → redirect to ifthenpay. TxID: {$transaction_id}" );

		return $redirect_url;
	}

	// -------------------------------------------------------------------------
	// Gateway return handler (called on 'wp' action)
	// -------------------------------------------------------------------------

	public function handle_gateway_return(): void {
		$return_data = IfthenpayReturn::get_return_data_from_request();
		if ( empty( $return_data ) ) {
			return;
		}

		$entry_id = (int) ( $return_data['entry_id'] ?? 0 );
		$status   = IfthenpayReturn::get_return_status( $return_data );

		if ( $entry_id <= 0 ) {
			return;
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			return;
		}

		if ( $status === 'success' ) {
			$context = IfthenpayReturn::resolve_return_context( $return_data );

			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Paid' );
			\GFAPI::update_entry_property( $entry_id, 'payment_date', gmdate( 'Y-m-d H:i:s' ) );

			if ( $context['transaction_id'] !== '' ) {
				\GFAPI::update_entry_property( $entry_id, 'transaction_id', $context['transaction_id'] );
			}

			if ( $context['payment_method'] !== '' ) {
				gform_update_meta( $entry_id, 'payment_method', $context['payment_method'] );
			}

			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'paid' );

			$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} marked Paid." );
		} elseif ( in_array( $status, [ 'cancel', 'cancelled', 'canceled' ], true ) ) {
			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Cancelled' );
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'cancelled' );
		} elseif ( $status === 'error' ) {
			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Failed' );
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'failed' );
		}

		$source_url = (string) rgar( $entry, 'source_url' );
		if ( $source_url === '' ) {
			$source_url = home_url( '/' );
		}

		wp_safe_redirect( esc_url_raw( remove_query_arg( [ 'iftp_gf_return', 'entry_id', 'form_id', 'transaction_id', 'iftp_gf' ], $source_url ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: connect / disconnect backoffice key
	// -------------------------------------------------------------------------

	public function ajax_connect_backoffice(): void {
		check_ajax_referer( 'iftp_gf_settings_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-gravityforms' ) ], 403 );
		}

		$backoffice_key = isset( $_POST['backoffice_key'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['backoffice_key'] ) )
			: '';

		if ( $backoffice_key === '' || preg_match( '/^\*+$/', $backoffice_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid Backoffice Key before connecting.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		if ( ! preg_match( '/^\d{4}-\d{4}-\d{4}-\d{4}$/', $backoffice_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid Backoffice Key format.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		try {
			$raw_methods = IfthenpayClient::get_available_methods();
			$gateways    = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_catalog( $raw_methods );
		} catch ( \Throwable ) {
			$raw_methods = [];
			$gateways    = [];
		}


		if ( empty( $gateways ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: 1: support email link, 2: sign-up link */
					__( 'No gateway found for this backoffice key. If you are a client, %1$s to request one. Otherwise, %2$s.', 'ifthenpay-payments-for-gravityforms' ),
					'<a href="mailto:suporte@ifthenpay.com">' . esc_html__( 'contact support', 'ifthenpay-payments-for-gravityforms' ) . '</a>',
					'<a href="' . esc_url( self::SIGNUP_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'sign up here', 'ifthenpay-payments-for-gravityforms' ) . '</a>'
				),
			], 400 );
		}

		update_option( self::OPTION_BACKOFFICE_KEY, $backoffice_key, false );
		update_option( self::OPTION_GATEWAY_CATALOG, $gateways, false );

		if ( ! empty( $raw_methods ) ) {
			$method_catalog = IfthenpayClient::build_method_catalog_from_raw( $raw_methods );
			update_option( self::OPTION_METHOD_CATALOG, $method_catalog, false );
		}

		wp_send_json_success( [
			'message'     => __( 'Backoffice Key connected successfully.', 'ifthenpay-payments-for-gravityforms' ),
			'masked_key'  => str_repeat( '*', 18 ),
			'status_html' => $this->render_connection_card_html(),
		] );
	}

	public function ajax_disconnect_backoffice(): void {
		check_ajax_referer( 'iftp_gf_settings_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-gravityforms' ) ], 403 );
		}

		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_GATEWAY_CATALOG );
		delete_option( self::OPTION_METHOD_CATALOG );

		wp_send_json_success( [
			'message'     => __( 'Backoffice Key disconnected.', 'ifthenpay-payments-for-gravityforms' ),
			'status_html' => $this->render_connection_card_html(),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: load gateway methods for feed settings
	// -------------------------------------------------------------------------

	public function ajax_load_gateway_methods(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [], 403 );
		}

		$gateway_key = isset( $_POST['gateway_key'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['gateway_key'] ) )
			: '';

		$catalog = self::get_gateway_catalog();
		$methods = $catalog[ $gateway_key ]['methods'] ?? [];

		ob_start();
		if ( empty( $methods ) ) {
			echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No payment methods found for this gateway key.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
		} else {
			$this->render_methods_table( $methods, [] );
		}
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: request method activation email
	// -------------------------------------------------------------------------

	public function ajax_activate_payment_method(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [], 403 );
		}

		$entity      = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_POST['entity'] ?? '' ) ) ) );
		$gateway_key = sanitize_text_field( wp_unslash( (string) ( $_POST['gateway_key'] ?? '' ) ) );

		if ( $entity === '' || $gateway_key === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing entity or gateway key.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		$cooldown_key = 'iftp_gf_activation_' . md5( $gateway_key . '_' . $entity );
		if ( get_transient( $cooldown_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Activation request already sent. Please wait 24 hours before requesting again.', 'ifthenpay-payments-for-gravityforms' ) ], 429 );
		}

		$current_user = wp_get_current_user();

		IfthenpayEmailHelper::send_activation_email( [
			'gateway_key'    => $gateway_key,
			'entity'         => $entity,
			'backoffice_key' => self::get_backoffice_key(),
			'customer_email' => $current_user->user_email,
			'site_url'       => home_url( '/' ),
			'site_name'      => get_bloginfo( 'name' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'gf_version'     => \GFCommon::$version ?? '',
			'plugin_version' => IFTP_GF_VERSION,
		] );

		set_transient( $cooldown_key, 1, DAY_IN_SECONDS );

		wp_send_json_success( [ 'message' => __( 'Activation request sent to ifthenpay support.', 'ifthenpay-payments-for-gravityforms' ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: create payment link (popup flow — available to guests)
	// -------------------------------------------------------------------------

	public function ajax_create_payment(): void {
		$form_id = (int) ( $_POST['form_id'] ?? 0 );

		if ( $form_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		check_ajax_referer( 'iftp_gf_payment_' . $form_id, 'nonce' );

		$gateway_key = sanitize_text_field( (string) ( $_POST['gateway_key'] ?? '' ) );
		$amount      = round( (float) ( $_POST['amount'] ?? 0 ), 2 );

		if ( $gateway_key === '' || $amount <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payment data.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		$feeds = \GFAPI::get_feeds( null, $form_id, 'iftp_gf' );
		$feed  = null;
		if ( is_array( $feeds ) ) {
			foreach ( $feeds as $f ) {
				if ( ! empty( $f['is_active'] ) && rgar( $f['meta'], 'gateway_key' ) === $gateway_key ) {
					$feed = $f;
					break;
				}
			}
		}

		if ( ! $feed ) {
			wp_send_json_error( [ 'message' => __( 'Payment configuration not found for this form.', 'ifthenpay-payments-for-gravityforms' ) ], 400 );
		}

		$methods_config_raw = rgar( $feed['meta'], 'methods_config' );
		$methods_config     = is_string( $methods_config_raw )
			? (array) json_decode( $methods_config_raw, true )
			: (array) $methods_config_raw;

		$accounts    = IfthenpayPayload::build_accounts_string( $methods_config );
		$description = sanitize_text_field( (string) rgar( $feed['meta'], 'description' ) );

		$session_token = bin2hex( random_bytes( 16 ) );
		$result_base   = home_url( '/' );
		$success_url   = add_query_arg( [ 'iftp_gf_popup' => 'success', 'session' => $session_token ], $result_base );
		$error_url     = add_query_arg( [ 'iftp_gf_popup' => 'error',   'session' => $session_token ], $result_base );
		$cancel_url    = add_query_arg( [ 'iftp_gf_popup' => 'cancel',  'session' => $session_token ], $result_base );

		$temp_ref = 'GF-' . $form_id . '-' . time();

		$payload = IfthenpayPayload::build_pay_by_link_payload( [
			'id'              => $temp_ref,
			'amount'          => $amount,
			'description'     => $description !== '' ? $description : get_bloginfo( 'name' ),
			'accounts'        => $accounts,
			'success_url'     => $success_url,
			'error_url'       => $error_url,
			'cancel_url'      => $cancel_url,
			'selected_method' => IfthenpayPayload::get_selected_method_code(
				[ 'default_method' => rgar( $feed['meta'], 'default_method' ) ],
				$methods_config
			),
		] );

		try {
			$response = IfthenpayClient::create_payment_link( $gateway_key, $payload );
		} catch ( \RuntimeException $e ) {
			$this->log_error( __METHOD__ . '(): API error — ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'Payment gateway error. Please try again.', 'ifthenpay-payments-for-gravityforms' ) ], 500 );
		}

		$redirect_url   = (string) ( $response['url'] ?? $response['PaymentUrl'] ?? $response['paymentUrl'] ?? '' );
		$transaction_id = (string) ( $response['RequestId'] ?? $response['requestId'] ?? $response['transactionId'] ?? '' );

		if ( $redirect_url === '' ) {
			wp_send_json_error( [ 'message' => __( 'Could not generate payment link.', 'ifthenpay-payments-for-gravityforms' ) ], 500 );
		}

		set_transient( 'iftp_gf_session_' . $session_token, [
			'status'         => 'pending',
			'form_id'        => $form_id,
			'gateway_key'    => $gateway_key,
			'amount'         => $amount,
			'transaction_id' => $transaction_id,
		], HOUR_IN_SECONDS );

		wp_send_json_success( [
			'url'           => $redirect_url,
			'sessionToken'  => $session_token,
			'transactionId' => $transaction_id,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: poll payment status by session token (available to guests)
	// -------------------------------------------------------------------------

	public function ajax_check_payment_status(): void {
		$session_token = sanitize_text_field( (string) ( $_POST['session_token'] ?? '' ) );

		if ( $session_token === '' ) {
			wp_send_json_error( [], 400 );
			return;
		}

		$data = get_transient( 'iftp_gf_session_' . $session_token );

		if ( ! is_array( $data ) ) {
			wp_send_json_success( [ 'status' => 'expired' ] );
			return;
		}

		wp_send_json_success( [
			'status'        => $data['status'],
			'transactionId' => $data['transaction_id'] ?? '',
		] );
	}

	// -------------------------------------------------------------------------
	// Popup result page — gateway redirects popup here; postMessages parent
	// -------------------------------------------------------------------------

	public function handle_popup_result(): void {
		if ( ! isset( $_GET['iftp_gf_popup'] ) ) {
			return;
		}

		$status  = sanitize_key( $_GET['iftp_gf_popup'] );
		$session = sanitize_text_field( (string) ( $_GET['session'] ?? '' ) );

		if ( ! in_array( $status, [ 'success', 'error', 'cancel' ], true ) || $session === '' ) {
			return;
		}

		$data = get_transient( 'iftp_gf_session_' . $session );
		if ( is_array( $data ) ) {
			$data['status'] = ( $status === 'success' ) ? 'paid' : $status;
			set_transient( 'iftp_gf_session_' . $session, $data, HOUR_IN_SECONDS );
		}

		$tx_id = is_array( $data ) ? ( $data['transaction_id'] ?? '' ) : '';

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Payment</title></head>
<body>
<script>
(function() {
	var status  = <?php echo wp_json_encode( $status === 'success' ? 'paid' : $status ); ?>;
	var session = <?php echo wp_json_encode( $session ); ?>;
	var txId    = <?php echo wp_json_encode( $tx_id ); ?>;
	if (window.opener) {
		window.opener.postMessage(
			{ source: 'ifthenpay', status: status, sessionToken: session, transactionId: txId },
			window.location.origin
		);
	}
	window.close();
})();
</script>
<p style="font-family:sans-serif;text-align:center;margin-top:40px;color:#585e6a;">Processing payment result…</p>
</body>
</html>
		<?php
		exit;
	}

	// -------------------------------------------------------------------------
	// Popup payment finalizer — called by redirect_url() for iftp_pbl forms
	// -------------------------------------------------------------------------

	private function finalize_popup_payment( array $form, array $entry ): void {
		$form_id       = (int) rgar( $form, 'id' );
		$entry_id      = (int) rgar( $entry, 'id' );
		$session_token = sanitize_text_field( (string) ( $_POST[ 'iftp_pbl_session_' . $form_id ] ?? '' ) );

		if ( $session_token === '' ) {
			$this->log_error( __METHOD__ . "(): No session token in POST for form #{$form_id}." );
			return;
		}

		$data = get_transient( 'iftp_gf_session_' . $session_token );

		if ( ! is_array( $data ) || $data['status'] !== 'paid' ) {
			$this->log_error( __METHOD__ . "(): Session not in paid state. Status: " . ( is_array( $data ) ? $data['status'] : 'missing' ) );
			return;
		}

		$transaction_id = (string) ( $data['transaction_id'] ?? '' );
		$amount         = (float) ( $data['amount'] ?? 0 );
		$gateway_key    = (string) ( $data['gateway_key'] ?? '' );

		\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Paid' );
		\GFAPI::update_entry_property( $entry_id, 'payment_date', gmdate( 'Y-m-d H:i:s' ) );

		if ( $amount > 0 ) {
			\GFAPI::update_entry_property( $entry_id, 'payment_amount', (string) $amount );
		}

		if ( $transaction_id !== '' ) {
			\GFAPI::update_entry_property( $entry_id, 'transaction_id', $transaction_id );
			$this->insert_transaction( $entry_id, 'payment', $transaction_id, $amount );
			gform_update_meta( $entry_id, 'iftp_gf_transaction_id', $transaction_id );
		}

		if ( $gateway_key !== '' ) {
			gform_update_meta( $entry_id, 'iftp_gf_gateway_key', $gateway_key );
		}

		gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'paid' );
		delete_transient( 'iftp_gf_session_' . $session_token );

		$this->log_debug( __METHOD__ . "(): Popup payment finalized. Entry #{$entry_id}, TxID: {$transaction_id}" );
	}

	private function form_has_popup_field( array $form ): bool {
		foreach ( rgar( $form, 'fields', [] ) as $field ) {
			if ( isset( $field->type ) && $field->type === 'iftp_pbl' ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Private: render connection status card HTML (used by AJAX responses)
	// -------------------------------------------------------------------------

	private function render_connection_card_html(): string {
		$is_connected = self::get_backoffice_key() !== '';

		ob_start();
		?>
		<div id="iftp-gf-connection-status-card">
			<?php if ( $is_connected ) : ?>
			<div class="alert gforms_note_success">
				<h4><?php esc_html_e( 'Connected', 'ifthenpay-payments-for-gravityforms' ); ?></h4>
				<p><?php esc_html_e( 'Your Backoffice Key is connected. Open any form\'s Settings → ifthenpay to configure gateway keys and payment methods.', 'ifthenpay-payments-for-gravityforms' ); ?></p>
				<button type="button" id="iftp-gf-disconnect-backoffice" class="button button-secondary">
					<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-gravityforms' ); ?>
				</button>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	public static function get_backoffice_key(): string {
		return trim( (string) get_option( self::OPTION_BACKOFFICE_KEY, '' ) );
	}

	public static function get_gateway_catalog(): array {
		$catalog = get_option( self::OPTION_GATEWAY_CATALOG, [] );
		return is_array( $catalog ) ? $catalog : [];
	}

	public static function get_method_catalog(): array {
		$catalog = get_option( self::OPTION_METHOD_CATALOG, [] );
		return is_array( $catalog ) ? $catalog : [];
	}

	public static function get_methods_config() {

	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function get_gateway_key_choices(): array {
		$catalog = self::get_gateway_catalog();
		$choices = [ [ 'label' => __( '— Select a gateway key —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		foreach ( $catalog as $key => $gateway ) {
			$label     = sanitize_text_field( (string) ( $gateway['label'] ?? $key ) );
			$choices[] = [ 'label' => $label . ' (' . $key . ')', 'value' => $key ];
		}

		return $choices;
	}

	private function get_default_method_choices(): array {
		$choices = [ [ 'label' => __( '— No preference —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		$feed        = $this->get_active_feed_for_settings();
		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		$catalog     = self::get_gateway_catalog();

		$available = $catalog[ $gateway_key ]['methods'] ?? [];

		foreach ( $available as $entity => $method ) {
			$label     = sanitize_text_field( (string) ( $method['method'] ?? $entity ) );
			$choices[] = [ 'label' => $label, 'value' => strtoupper( (string) $entity ) ];
		}

		return $choices;
	}

	private function get_active_feed_for_settings(): ?array {
		$feed_id = absint( rgget( 'fid' ) );
		if ( $feed_id <= 0 ) {
			return null;
		}

		$feed = \GFAPI::get_feed( $feed_id );
		return is_array( $feed ) ? $feed : null;
	}

	private function render_gateway_methods_table(
		string $gateway_key
	): void {

		$catalog = self::get_gateway_catalog();

		$gateway = $catalog[ $gateway_key ] ?? null;

		if ( ! $gateway ) {
			return;
		}

		$methods = $gateway['methods'] ?? [];

		?>

		<table class="widefat striped">

			<thead>
				<tr>
					<th>Method</th>
					<th>Account</th>
					<th>Status</th>
				</tr>
			</thead>

			<tbody>

			<?php foreach ( $methods as $method ) : ?>

				<?php
				if ( empty( $method['is_visible'] ) ) {
					continue;
				}
				?>

				<tr>

					<td>
						<?php echo esc_html(
							$method['method']
						); ?>
					</td>

					<td>

						<?php if ( ! empty( $method['account'] ) ) : ?>

							<code>
								<?php echo esc_html(
									$method['account']
								); ?>
							</code>

						<?php else : ?>

							<em>Not activated</em>

						<?php endif; ?>

					</td>

					<td>

						<?php if ( ! empty( $method['account'] ) ) : ?>

							✓ Active

						<?php else : ?>

							✕ Inactive

						<?php endif; ?>

					</td>

				</tr>

			<?php endforeach; ?>

			</tbody>

		</table>

		<?php
	}

	public function ajax_get_gateway_methods(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$gateway_key = sanitize_text_field(
			wp_unslash(
				(string) ($_POST['gateway_key'] ?? '')
			)
		);

		ob_start();

		$this->render_gateway_methods_table(
			$gateway_key
		);

		$html = ob_get_clean();

		wp_send_json_success([
			'html' => $html,
		]);
	}
}
