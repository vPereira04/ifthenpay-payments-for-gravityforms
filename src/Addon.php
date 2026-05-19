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
	protected $_title                    = 'Ifthenpay | Payments for GravityForms';
	protected $_short_title              = 'ifthenpay';
	protected $_supports_callbacks       = false;
	protected $_requires_credit_card     = false;

	private static ?self $_instance = null;

	private const OPTION_BACKOFFICE_KEY  = 'iftp_gf_backofficekey';
	private const OPTION_GATEWAY_CATALOG = 'iftp_gf_gateway_catalog';
	private const OPTION_METHOD_CATALOG  = 'iftp_gf_method_catalog';

	/** WP option prefix for per-form resolved payment info (suffix = form ID). */
	private const OPTION_FORM_INFO_PREFIX = 'ifthenpay_gf_form_';

	/** Transient that signals the gateway catalog is still fresh (kept for cleanup on disconnect). */
	private const TRANSIENT_CATALOG_FRESH = 'iftp_gf_catalog_fresh';

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
		return $this->is_gravityforms_supported( '2.5' ) ? 'gform-icon--ifthenpay' : 'dashicons-admin-generic';
	}

	// -------------------------------------------------------------------------
	// Initialization
	// -------------------------------------------------------------------------

	public function init(): void {
		parent::init();

		\GF_Fields::register( new \Ifthenpay\GravityForms\Field\GF_Field_Ifthenpay() );
		add_action( 'wp', [ $this, 'handle_gateway_return' ] );
		add_action( 'wp', [ $this, 'handle_gateway_callback' ] );

		// Block form submission if another payment add-on also has an active feed on the form.
		add_filter( 'gform_validation', [ $this, 'enforce_single_payment_gateway' ] );

		// Inject a Stripe-style payment-result banner above the form when the user
		// returns from the gateway (handle_gateway_return appends ?iftp_gf_status=...).
		add_filter( 'gform_get_form_filter', [ $this, 'inject_payment_status_banner' ], 10, 2 );
	}

	public function init_admin(): void {
		parent::init_admin();
		$this->load_catalogs_if_empty();
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );

		// The block editor (Gutenberg) renders the GF form-preview block inside
		// an iframe that doesn't inherit the addon-framework enqueue chain. Push
		// our frontend stylesheet in via the block-editor-assets hook so the
		// passive preview is themed correctly inside the editor canvas.
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_styles' ] );
	}

	/**
	 * Loads the field stylesheet into the Gutenberg block-editor iframe so the
	 * GF form-block preview matches the front-end look.
	 */
	public function enqueue_block_editor_styles(): void {
		wp_enqueue_style(
			'ifthenpay-gf-frontend',
			IFTP_GF_URL . 'assets/css/frontend.css',
			[],
			IFTP_GF_VERSION
		);
	}

	public function init_ajax(): void {
		parent::init_ajax();

		add_action( 'wp_ajax_iftp_gf_connect_backoffice',    [ $this, 'ajax_connect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_disconnect_backoffice', [ $this, 'ajax_disconnect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_load_gateway_methods',  [ $this, 'ajax_load_gateway_methods' ] );
		add_action( 'wp_ajax_iftp_gf_activate_method',       [ $this, 'ajax_activate_payment_method' ] );
	}

	// -------------------------------------------------------------------------
	// Lazy catalog loader (runs on admin pages when options are empty)
	// -------------------------------------------------------------------------

	/**
	 * Fetches and stores both catalogs when they are absent.
	 * Called from init_admin() — fires once per admin request, not on every
	 * feed-settings page specifically.  The actual freshness gate is simply
	 * "are the options populated?", so no transients are needed.
	 */
	private function load_catalogs_if_empty(): void {
		$backoffice_key = self::get_backoffice_key();
		if ( $backoffice_key === '' ) {
			return;
		}

		$gateway_catalog_empty = empty( self::get_gateway_catalog() );
		$method_catalog_empty  = empty( self::get_method_catalog() );

		if ( ! $gateway_catalog_empty && ! $method_catalog_empty ) {
			return; // Both already populated — nothing to do.
		}

		try {
			$raw_methods = IfthenpayClient::get_cached_available_methods();

			if ( $method_catalog_empty && ! empty( $raw_methods ) ) {
				update_option(
					self::OPTION_METHOD_CATALOG,
					IfthenpayClient::build_method_catalog_from_raw( $raw_methods ),
					false
				);
			}

			if ( $gateway_catalog_empty ) {
				$gateways = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_catalog(
					is_array( $raw_methods ) ? $raw_methods : []
				);
				if ( ! empty( $gateways ) ) {
					update_option( self::OPTION_GATEWAY_CATALOG, $gateways, false );
				}
			}
		} catch ( \Throwable ) {
			// Silently fail — the feed-settings page will show an empty dropdown
			// and the admin can retry by reloading the page.
		}
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
	public function settings_iftp_gf_backoffice_connection( mixed $_field, bool $echo = true ): string {
		$is_connected = self::get_backoffice_key() !== '';
		$nonce = wp_create_nonce( 'iftp_gf_admin' );
		$masked_key   = $is_connected ? str_repeat( '*', 18 ) : '';

		ob_start();
		?>
		<?php if ( ! $is_connected ) : ?>
		<p class="description">
			<?php esc_html_e( 'Connect your ifthenpay Backoffice Key to load your gateways. Gateway selection and payment methods are configured per form in feed settings.', 'ifthenpay-payments-for-gravityforms' ); ?>
			&nbsp;<a href="<?php echo esc_url( self::SIGNUP_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Not a client? Sign up here.', 'ifthenpay-payments-for-gravityforms' ); ?>
			</a>
		</p>
		<?php endif; ?>

		<div class="iftp-gf-key-row<?php echo $is_connected ? ' iftp-gf-key-field-hidden' : ''; ?>">
			<input
				type="text"
				id="iftp-gf-backoffice-key-input"
				class="regular-text"
				placeholder="Insert your backoffice key here..."
				value="<?php echo esc_attr( $masked_key ); ?>"
				autocomplete="off"
			/>
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
		</div>

		<p><span class="iftp-gf-message" aria-live="polite"></span></p>

		<input type="hidden" id="iftp-gf-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo $this->kses_admin_html( $html );
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
						'tooltip' => __( 'The payment method preselected when the customer opens the Pay By Link page. Methods you have not enabled above are shown but cannot be selected.', 'ifthenpay-payments-for-gravityforms' ),
						'choices' => $this->get_default_method_choices(),
					],
					[
						'label'         => __( 'Payment Amount Source', 'ifthenpay-payments-for-gravityforms' ),
						'type'          => 'select',
						'name'          => 'payment_amount_source',
						'tooltip'       => __( 'Where does the amount to charge come from? Forms without a pricing field need a Fixed amount or a Specific field.', 'ifthenpay-payments-for-gravityforms' ),
						'default_value' => 'form_total',
						'choices'       => [
							[ 'label' => __( 'Form total (auto-detect pricing fields)', 'ifthenpay-payments-for-gravityforms' ), 'value' => 'form_total' ],
							[ 'label' => __( 'Specific form field', 'ifthenpay-payments-for-gravityforms' ), 'value' => 'field' ],
							[ 'label' => __( 'Fixed amount', 'ifthenpay-payments-for-gravityforms' ), 'value' => 'fixed' ],
						],
					],
					[
						'label'      => __( 'Amount Field', 'ifthenpay-payments-for-gravityforms' ),
						'type'       => 'select',
						'name'       => 'payment_amount_field',
						'tooltip'    => __( 'Pick the form field whose value will be charged. Number, product, and total fields are listed.', 'ifthenpay-payments-for-gravityforms' ),
						'choices'    => $this->get_amount_field_choices(),
						'dependency' => [ 'live' => true, 'fields' => [ [ 'field' => 'payment_amount_source', 'values' => [ 'field' ] ] ] ],
					],
					[
						'label'      => __( 'Fixed Amount (EUR)', 'ifthenpay-payments-for-gravityforms' ),
						'type'       => 'text',
						'name'       => 'payment_amount_fixed',
						'class'      => 'small',
						'tooltip'    => __( 'Charge every submission this exact amount (e.g. 12.50).', 'ifthenpay-payments-for-gravityforms' ),
						'dependency' => [ 'live' => true, 'fields' => [ [ 'field' => 'payment_amount_source', 'values' => [ 'fixed' ] ] ] ],
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
	 * Returns numeric form fields suitable for use as the payment amount.
	 */
	private function get_amount_field_choices(): array {
		$choices = [ [ 'label' => __( '— Select a field —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		$form_id = absint( rgget( 'id' ) );
		if ( $form_id <= 0 ) {
			return $choices;
		}

		$form = \GFAPI::get_form( $form_id );
		if ( ! is_array( $form ) ) {
			return $choices;
		}

		$valid_types = [ 'number', 'total', 'product', 'singleproduct', 'singleshipping', 'shipping', 'price', 'hiddenproduct', 'calculation' ];

		foreach ( $form['fields'] ?? [] as $field ) {
			$type = (string) ( $field->type ?? '' );
			if ( ! in_array( $type, $valid_types, true ) ) {
				continue;
			}
			$choices[] = [
				'label' => \GFCommon::get_label( $field ) . ' (' . $type . ')',
				'value' => (string) $field->id,
			];
		}

		return $choices;
	}

	/**
	 * Custom settings field renderer for the payment methods list (feed settings page).
	 */
	public function settings_iftp_gf_methods_table( mixed $_field, bool $echo = true ): string {
		$feed        = $this->get_active_feed_for_settings();
		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		$catalog     = self::get_gateway_catalog();
		$nonce       = wp_create_nonce( 'iftp_gf_admin' );

		$methods_config = [];
		if ( $feed && ! empty( $feed['meta']['methods_config'] ) ) {
			$raw            = $feed['meta']['methods_config'];
			$methods_config = is_string( $raw ) ? (array) json_decode( $raw, true ) : (array) $raw;
		}

		[ $available_methods, $is_dynamic, $dynamic_accounts ] =
			$this->resolve_available_methods( $gateway_key, $catalog );

		ob_start();
		?>
		<div id="iftp-gf-methods-table-wrapper" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( empty( $available_methods ) ) : ?>
				<p class="iftp-gf-no-methods">
					<?php
					if ( $gateway_key === '' ) {
						esc_html_e( 'Select a gateway key above to load available payment methods.', 'ifthenpay-payments-for-gravityforms' );
					} elseif ( $is_dynamic ) {
						esc_html_e( 'No accounts found for this gateway. Please contact ifthenpay support.', 'ifthenpay-payments-for-gravityforms' );
					} else {
						esc_html_e( 'No payment methods found for this gateway key.', 'ifthenpay-payments-for-gravityforms' );
					}
					?>
				</p>
			<?php else : ?>
				<?php $this->render_methods_list( $available_methods, $methods_config, $gateway_key, $is_dynamic, $dynamic_accounts ); ?>
			<?php endif; ?>
		</div>
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo $this->kses_admin_html( $html );
		}

		return $html;
	}

	private function render_methods_list(
		array $available_methods,
		array $methods_config,
		string $gateway_key,
		bool $is_dynamic = false,
		array $dynamic_accounts = []
	): void {
		$keyed_catalog = self::get_keyed_method_catalog();
		?>
		<div class="iftp-gf-methods-list">
			<?php foreach ( $available_methods as $entity => $method ) : ?>
				<?php
				$entity_key    = strtoupper( (string) $entity );
				$is_enabled    = ! empty( $methods_config[ $entity_key ]['enabled'] );
				$saved_account = (string) ( $methods_config[ $entity_key ]['account'] ?? '' );
				$method_label  = (string) ( $method['method'] ?? $entity_key );

				// Resolve logo from the live method catalog (has real SmallImageUrl).
				$catalog_entry = $keyed_catalog[ $entity_key ] ?? [];
				$logo_url      = $catalog_entry['small_image_url'] ?? '';
				if ( $logo_url === '' ) {
					$logo_url = 'https://gateway.ifthenpay.com/plugins/logotipos/small/' . strtolower( $entity_key ) . '.png';
				}

				// IsVisible from the live method catalog — reflects the latest API data.
				$is_visible   = $catalog_entry !== [] ? (bool) ( $catalog_entry['is_visible'] ?? true ) : true;
				$is_wide_logo = $entity_key === 'CCARD';

				// Initialise both sets so the template is always defined.
				$entity_accounts = [];
				$has_accounts    = false;
				$account         = '';
				$has_account     = false;

				if ( $is_dynamic ) {
					$entity_accounts = (array) ( $dynamic_accounts[ $entity_key ] ?? [] );
					$has_accounts    = ! empty( $entity_accounts );
				} else {
					$account     = (string) ( $method['account'] ?? '' );
					$has_account = $account !== '';
				}

				// A method is "ready" only when it has an account (static) or has
				// at least one selectable account (dynamic). Unready methods get the
				// Activate UX path instead of an enable-toggle.
				$is_ready     = $is_dynamic ? $has_accounts : $has_account;
				$item_classes = 'iftp-gf-method-item';
				if ( $is_enabled )    { $item_classes .= ' is-enabled'; }
				if ( ! $is_visible )  { $item_classes .= ' is-unavailable'; }
				if ( ! $is_ready )    { $item_classes .= ' is-unactivated'; }
				?>
				<div class="<?php echo esc_attr( $item_classes ); ?>" data-entity="<?php echo esc_attr( $entity_key ); ?>">

					<label class="iftp-gf-method-item-label">
						<input
							type="checkbox"
							name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled && $is_ready ); ?>
							<?php disabled( ! $is_visible || ! $is_ready ); ?>
							class="iftp-gf-method-toggle"
							data-entity="<?php echo esc_attr( $entity_key ); ?>"
						/>
						<?php if ( ! $is_dynamic ) : ?>
						<input
							type="hidden"
							name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][account]"
							value="<?php echo esc_attr( $account ?? '' ); ?>"
						/>
						<?php endif; ?>
						<span class="iftp-gf-method-icon-wrap<?php echo $is_wide_logo ? ' is-wide' : ''; ?>">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $method_label ); ?>" loading="lazy" />
						</span>
						<span class="iftp-gf-method-name"><?php echo esc_html( $method_label ); ?></span>
						<?php if ( ! $is_visible ) : ?>
							<span class="iftp-gf-unavailable-badge"><?php esc_html_e( 'Unavailable', 'ifthenpay-payments-for-gravityforms' ); ?></span>
						<?php endif; ?>
					</label>

					<div class="iftp-gf-method-right">
						<?php if ( $is_dynamic ) : ?>

							<?php if ( $has_accounts ) : ?>
								<select
									name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][account]"
									class="iftp-gf-method-account-select"
								>
									<?php foreach ( $entity_accounts as $acct ) : ?>
										<option
											value="<?php echo esc_attr( $acct['account'] ); ?>"
											<?php selected( $saved_account, $acct['account'] ); ?>
										><?php echo esc_html( $acct['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<em class="iftp-gf-no-account"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-gravityforms' ); ?></em>
								<button
									type="button"
									class="button button-small iftp-gf-activate-method"
									data-entity="<?php echo esc_attr( $entity_key ); ?>"
									data-gateway-key="<?php echo esc_attr( $gateway_key ); ?>"
								>
									<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ); ?>
								</button>
							<?php endif; ?>

						<?php else : ?>

							<?php if ( $has_account ) : ?>
								<code class="iftp-gf-account-code"><?php echo esc_html( $account ); ?></code>
								<span class="iftp-gf-active-badge" title="<?php esc_attr_e( 'Activated', 'ifthenpay-payments-for-gravityforms' ); ?>">&#10003;</span>
							<?php else : ?>
								<em class="iftp-gf-no-account"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-gravityforms' ); ?></em>
								<button
									type="button"
									class="button button-small iftp-gf-activate-method"
									data-entity="<?php echo esc_attr( $entity_key ); ?>"
									data-gateway-key="<?php echo esc_attr( $gateway_key ); ?>"
								>
									<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ); ?>
								</button>
							<?php endif; ?>

						<?php endif; ?>
					</div>

				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Custom settings field renderer for the global gateways overview table (plugin settings page).
	 */
	public function settings_iftp_gf_payment_methods_table( mixed $_field, bool $echo = true ): string {
		$catalog = self::get_gateway_catalog();

		ob_start();

		if ( empty( $catalog ) ) {
			echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No gateways loaded. Connect your Backoffice Key above to load your gateways and payment methods.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
		} else {
			?>
			<h4 style="margin:16px 0 8px;"><?php esc_html_e( 'Loaded Gateways', 'ifthenpay-payments-for-gravityforms' ); ?></h4>
			<table class="iftp-gf-methods-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-gravityforms' ); ?></th>
						<th><?php esc_html_e( 'Alias', 'ifthenpay-payments-for-gravityforms' ); ?></th>
						<th><?php esc_html_e( 'Payment Methods', 'ifthenpay-payments-for-gravityforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $catalog as $key => $gateway ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $gateway['alias'] ?? '' ) ) ); ?></td>
							<td>
								<?php
								$methods = array_keys( (array) ( $gateway['methods'] ?? [] ) );
								if ( ! empty( $methods ) ) {
									echo esc_html( implode( ', ', array_map( 'strtoupper', $methods ) ) );
								} else {
									echo '<em>' . esc_html__( 'None or Dynamic Gateway', 'ifthenpay-payments-for-gravityforms' ) . '</em>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		$html = ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo $this->kses_admin_html( $html );
		}

		return $html;
	}

	/**
	 * Return a non-`false` value here only when the feeds list should be replaced
	 * by a setup message (e.g. backoffice key not connected yet). Returning anything
	 * other than `false` — including null — causes GFAddOnFeedsTable to hide the
	 * feeds rows, so we must explicitly return false when we want feeds to render.
	 *
	 * @return string|false
	 */
	public function feed_list_message() {
		if ( self::get_backoffice_key() === '' ) {
			return sprintf(
				/* translators: %s: link to plugin settings page */
				__( 'To get started, %s.', 'ifthenpay-payments-for-gravityforms' ),
				'<a href="' . esc_url( $this->get_plugin_settings_url() ) . '">'
				. esc_html__( 'connect your ifthenpay Backoffice Key', 'ifthenpay-payments-for-gravityforms' )
				. '</a>'
			);
		}

		// Conflict warnings are surfaced via admin_notices (see render_admin_notices)
		// because anything we return here REPLACES the feed list rows.
		return false;
	}

	/**
	 * Renders an admin-level warning notice when another payment add-on also
	 * has an active feed on the form currently being viewed.
	 * Hooked from init_admin() so it fires above the feed list, never inside it.
	 */
	public function render_admin_notices(): void {
		if ( rgget( 'page' ) !== 'gf_edit_forms' || rgget( 'view' ) !== 'settings' ) {
			return;
		}
		if ( rgget( 'subview' ) !== $this->get_slug() ) {
			return;
		}

		$form_id = absint( rgget( 'id' ) );
		if ( $form_id <= 0 ) {
			return;
		}

		$competing = $this->get_competing_payment_addon_slugs( $form_id );
		if ( empty( $competing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: %s: comma-separated list of other payment add-on slugs */
				esc_html__( '⚠ This form already has an active payment feed from another add-on (%s). Only one payment gateway is allowed per form — customers will be blocked from submitting until the conflict is resolved.', 'ifthenpay-payments-for-gravityforms' ),
				esc_html( implode( ', ', $competing ) )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Per-form payment info — saved on every feed save, read by the field
	// -------------------------------------------------------------------------

	/**
	 * GF hook: called after the feed settings form is saved.
	 * We piggy-back to write a denormalised snapshot the frontend field can
	 * read directly, without having to reconstruct data from multiple sources.
	 *
	 * @param int   $feed_id
	 * @param int   $form_id
	 * @param array $settings  The posted settings values.
	 * @return int  The feed ID (pass-through).
	 */
	public function save_feed_settings( $feed_id, $form_id, $settings ) {
		// GFPaymentAddOn reads $feed['meta']['transactionType'] in validation() and
		// entry_post_save(); we don't expose subscriptions, so force 'product'
		// (= one-time payment) on every save. Eliminates the "Undefined array key"
		// PHP warning and ensures GF takes the redirect_url branch.
		if ( empty( $settings['transactionType'] ) ) {
			$settings['transactionType'] = 'product';
		}

		$result = parent::save_feed_settings( $feed_id, $form_id, $settings );
		if ( $result ) {
			$this->sync_form_payment_info( (int) $form_id, $settings );
		}
		return $result;
	}

	/**
	 * Resolves and persists enabled-methods data for a specific form.
	 * Stored as a plain WP option (no transient expiry) so data survives
	 * indefinitely until the admin saves the feed again or disconnects.
	 *
	 * Option key: ifthenpay_gf_form_{$form_id}
	 */
	public function sync_form_payment_info( int $form_id, array $settings ): void {
		$gateway_key = (string) ( $settings['gateway_key'] ?? '' );

		if ( $gateway_key === '' ) {
			delete_option( self::OPTION_FORM_INFO_PREFIX . $form_id );
			return;
		}

		$catalog       = self::get_gateway_catalog();
		$keyed_catalog = self::get_keyed_method_catalog();
		$is_dynamic    = self::is_dynamic_gateway( $gateway_key );

		$methods_config_raw = $settings['methods_config'] ?? [];
		$methods_config     = is_string( $methods_config_raw )
			? (array) json_decode( $methods_config_raw, true )
			: (array) $methods_config_raw;

		$enabled = [];

		if ( $is_dynamic ) {
			foreach ( $methods_config as $entity => $cfg ) {
				$entity_key = strtoupper( (string) $entity );
				if ( empty( $cfg['enabled'] ) || empty( $cfg['account'] ) ) {
					continue;
				}
				$cat_entry          = $keyed_catalog[ $entity_key ] ?? [];
				$enabled[ $entity_key ] = [
					'label'     => (string) ( $cat_entry['label'] ?? $entity_key ),
					'account'   => (string) $cfg['account'],
					'image_url' => (string) ( $cat_entry['small_image_url'] ?? '' ),
				];
			}
		} else {
			// Iterate methods_config directly — for static gateways the admin's
			// methods_config already stores the account in a hidden input on save,
			// so we don't need the global catalog to be populated to resolve this.
			$catalog_methods = (array) ( $catalog[ $gateway_key ]['methods'] ?? [] );
			foreach ( $methods_config as $entity => $cfg ) {
				if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
					continue;
				}
				$entity_key = strtoupper( (string) $entity );

				// Account priority: methods_config (admin's hidden input) → catalog → empty.
				$account = (string) ( $cfg['account'] ?? '' );
				if ( $account === '' ) {
					$account = (string) ( $catalog_methods[ $entity ]['account']
						?? $catalog_methods[ $entity_key ]['account']
						?? $catalog_methods[ strtolower( $entity ) ]['account']
						?? '' );
				}
				if ( $account === '' ) {
					continue;
				}

				$cat_entry           = $keyed_catalog[ $entity_key ] ?? [];
				$catalog_method      = $catalog_methods[ $entity ]
					?? $catalog_methods[ $entity_key ]
					?? $catalog_methods[ strtolower( $entity ) ]
					?? [];
				$label = (string) ( $catalog_method['method'] ?? $cat_entry['label'] ?? $entity_key );

				$enabled[ $entity_key ] = [
					'label'     => $label,
					'account'   => $account,
					'image_url' => (string) ( $cat_entry['small_image_url'] ?? '' ),
				];
			}
		}

		update_option(
			self::OPTION_FORM_INFO_PREFIX . $form_id,
			[
				'gateway_key'           => $gateway_key,
				'is_dynamic'            => $is_dynamic,
				'enabled_methods'       => $enabled,
				'default_method'        => strtoupper( (string) ( $settings['default_method'] ?? '' ) ),
				'description'           => sanitize_text_field( (string) ( $settings['description'] ?? '' ) ),
				'payment_amount_source' => sanitize_text_field( (string) ( $settings['payment_amount_source'] ?? 'form_total' ) ),
				'payment_amount_field'  => sanitize_text_field( (string) ( $settings['payment_amount_field'] ?? '' ) ),
				'payment_amount_fixed'  => sanitize_text_field( (string) ( $settings['payment_amount_fixed'] ?? '' ) ),
			],
			false
		);
	}

	/**
	 * Returns the stored per-form payment info, or an empty array if not set.
	 *
	 * Lazy-migrates: if the option doesn't exist but an active feed does,
	 * we run sync_form_payment_info() from the feed meta and return the result.
	 * That way feeds saved before this refactor "just work" on first access.
	 */
	public static function get_form_payment_info( int $form_id ): array {
		$data = get_option( self::OPTION_FORM_INFO_PREFIX . $form_id, [] );
		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		// Lazy migration from existing feed meta.
		$feeds = \GFAPI::get_feeds( null, $form_id, 'iftp_gf' );
		if ( ! is_array( $feeds ) ) {
			return [];
		}

		foreach ( $feeds as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}
			$meta = is_array( $feed['meta'] ?? null ) ? $feed['meta'] : [];
			if ( empty( $meta['gateway_key'] ?? '' ) ) {
				continue;
			}
			self::get_instance()->sync_form_payment_info( $form_id, $meta );
			$data = get_option( self::OPTION_FORM_INFO_PREFIX . $form_id, [] );
			return is_array( $data ) ? $data : [];
		}

		return [];
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	public function get_submission_data( $feed, $form, $entry ): array {
		$data = parent::get_submission_data( $feed, $form, $entry );

		$source = (string) rgars( $feed, 'meta/payment_amount_source' );

		// Feed setting takes priority over auto-detected form total when set explicitly.
		if ( $source === 'fixed' ) {
			$fixed = (float) str_replace( ',', '.', (string) rgars( $feed, 'meta/payment_amount_fixed' ) );
			if ( $fixed > 0 ) {
				$data['payment_amount'] = $fixed;
			}
		} elseif ( $source === 'field' ) {
			$field_id = (string) rgars( $feed, 'meta/payment_amount_field' );
			if ( $field_id !== '' ) {
				$raw    = (string) rgar( $entry, $field_id );
				// Strip currency symbols, thousands separators, normalize decimal comma to dot.
				$clean  = preg_replace( '/[^\d,.\-]/', '', $raw );
				if ( preg_match( '/\d{1,3}(\.\d{3})*(,\d+)?$/', (string) $clean ) ) {
					$clean = str_replace( '.', '', (string) $clean );
					$clean = str_replace( ',', '.', (string) $clean );
				}
				$amount = (float) $clean;
				if ( $amount > 0 ) {
					$data['payment_amount'] = $amount;
				}
			}
		}

		// Final fallback: GF pricing fields (form total).
		if ( empty( $data['payment_amount'] ) || (float) $data['payment_amount'] <= 0 ) {
			$amount = GFFormData::resolve_amount( $form, $entry );
			if ( $amount > 0 ) {
				$data['payment_amount'] = $amount;
			}
		}

		return $data;
	}

	public function redirect_url( $feed, $submission_data, $form, $entry ): string {
		$form_id  = (int) rgar( $form, 'id' );
		$entry_id = (int) rgar( $entry, 'id' );

		// Refuse to process if another payment add-on also has an active feed on this form.
		$competing = $this->get_competing_payment_addon_slugs( $form_id );
		if ( ! empty( $competing ) ) {
			$this->log_error( sprintf(
				'%s(): Refusing to process — competing payment add-on feed(s) on form #%d: %s',
				__METHOD__,
				$form_id,
				implode( ', ', $competing )
			) );
			return '';
		}

		$backoffice_key = self::get_backoffice_key();
		if ( $backoffice_key === '' ) {
			$this->log_error( __METHOD__ . '(): No backoffice key configured.' );
			return '';
		}

		$form_info = self::get_form_payment_info( $form_id );

		$gateway_key = (string) ( $form_info['gateway_key'] ?? '' );
		if ( $gateway_key === '' ) {
			$this->log_error( __METHOD__ . '(): No gateway key in form info.' );
			return '';
		}

		$amount = (float) ( $submission_data['payment_amount'] ?? 0 );
		if ( $amount <= 0 ) {
			$this->log_error( __METHOD__ . '(): Payment amount is 0.' );
			return '';
		}

		$enabled_methods = (array) ( $form_info['enabled_methods'] ?? [] );
		if ( empty( $enabled_methods ) ) {
			$this->log_error( __METHOD__ . '(): No enabled methods for form #' . $form_id );
			return '';
		}

		// Build accounts string from the per-form snapshot.
		$account_parts = [];
		foreach ( $enabled_methods as $method ) {
			$acct = trim( (string) ( $method['account'] ?? '' ) );
			if ( $acct !== '' ) {
				$account_parts[] = preg_replace( '/\s*\|\s*/', '|', $acct );
			}
		}
		$accounts = implode( ';', $account_parts );

		if ( $accounts === '' ) {
			$this->log_error( __METHOD__ . '(): Empty accounts string for form #' . $form_id );
			return '';
		}

		$methods_config = [];
		foreach ( $enabled_methods as $entity => $method ) {
			$methods_config[ $entity ] = [ 'enabled' => '1', 'account' => $method['account'] ?? '' ];
		}

		$source_url = (string) rgar( $entry, 'source_url' );
		$base_url   = $source_url !== '' ? $source_url : home_url( '/' );

		$urls = IfthenpayPayload::build_gateway_urls( $entry_id, $base_url );

		$customer_email = GFFormData::get_customer_email( $form, $entry );
		$customer_name  = GFFormData::get_customer_name( $form, $entry );

		$description    = sanitize_text_field( (string) ( $form_info['description'] ?? '' ) );
		$default_method = strtoupper( (string) ( $form_info['default_method'] ?? '' ) );

		$payload = IfthenpayPayload::build_pay_by_link_payload( [
			'id'              => (string) $entry_id,
			'amount'          => $amount,
			'description'     => $description !== '' ? $description : get_bloginfo( 'name' ),
			'accounts'        => $accounts,
			'success_url'     => $urls['success_url'],
			'error_url'       => $urls['error_url'],
			'cancel_url'      => $urls['cancel_url'],
			'selected_method' => IfthenpayPayload::get_selected_method_code(
				[ 'default_method' => $default_method ],
				$methods_config
			),
			'email'           => $customer_email,
			'name'            => $customer_name,
		] );

		$this->log_debug( sprintf(
			'%s(): Entry #%d → calling create_payment_link gateway=%s amount=%.2f accounts=%s',
			__METHOD__,
			$entry_id,
			$gateway_key,
			$amount,
			$accounts
		) );

		try {
			$response = IfthenpayClient::create_payment_link( $gateway_key, $payload );
		} catch ( \RuntimeException $e ) {
			$this->log_error( __METHOD__ . '(): API error — ' . $e->getMessage() );
			return '';
		}

		// Pay-By-Link / pinpay endpoint returns: PinpayUrl (hosted payment page),
		// RedirectUrl (alternate redirect), PinCode (transaction reference).
		$redirect_url   = (string) (
			$response['PinpayUrl']
			?? $response['RedirectUrl']
			?? $response['url']
			?? $response['PaymentUrl']
			?? $response['paymentUrl']
			?? ''
		);
		$transaction_id = (string) (
			$response['PinCode']
			?? $response['RequestId']
			?? $response['requestId']
			?? $response['transactionId']
			?? ''
		);

		$this->log_debug( sprintf(
			'%s(): Entry #%d ← API response url=%s tx=%s',
			__METHOD__,
			$entry_id,
			$redirect_url !== '' ? 'set' : 'EMPTY',
			$transaction_id
		) );

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
		// Persist the resolved amount so handle_gateway_return() / webhook handler
		// can feed it back into GFPaymentAddOn::complete_payment(). GF only sets
		// payment_amount on the entry inside complete_payment(), not before.
		gform_update_meta( $entry_id, 'iftp_gf_payment_amount', $amount );
		\GFAPI::update_entry_property( $entry_id, 'payment_amount', $amount );

		$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} → redirect to ifthenpay. TxID: {$transaction_id}" );

		return $redirect_url;
	}

	// -------------------------------------------------------------------------
	// Strict single-payment-gateway enforcement
	// -------------------------------------------------------------------------

	/**
	 * Returns slugs of OTHER active payment add-ons that also have an active
	 * feed on the given form. Empty array means we're the only payment processor.
	 *
	 * @return string[]
	 */
	public function get_competing_payment_addon_slugs( int $form_id ): array {
		if ( $form_id <= 0 || ! class_exists( '\GFPaymentAddOn' ) ) {
			return [];
		}

		$competing = [];

		foreach ( \GFAddOn::get_registered_addons( true ) as $addon ) {
			if ( ! $addon instanceof \GFPaymentAddOn ) {
				continue;
			}
			if ( $addon === $this || $addon->get_slug() === $this->get_slug() ) {
				continue;
			}
			$feeds = $addon->get_active_feeds( $form_id );
			if ( ! empty( $feeds ) ) {
				$competing[] = $addon->get_slug();
			}
		}

		return $competing;
	}

	/**
	 * GF validation hook: refuses to submit the form when more than one
	 * payment add-on (ours + any other) has an active feed on this form.
	 *
	 * @param array $validation_result
	 * @return array
	 */
	public function enforce_single_payment_gateway( $validation_result ): array {
		$form    = $validation_result['form'] ?? [];
		$form_id = (int) rgar( $form, 'id' );

		if ( $form_id <= 0 ) {
			return $validation_result;
		}

		// Only act if this form actually uses ifthenpay (has an active feed of ours).
		$our_feeds = $this->get_active_feeds( $form_id );
		if ( empty( $our_feeds ) ) {
			return $validation_result;
		}

		// (1) Competing payment add-on check.
		$competing = $this->get_competing_payment_addon_slugs( $form_id );
		if ( ! empty( $competing ) ) {
			return $this->fail_validation_on_field(
				$validation_result,
				$form,
				sprintf(
					/* translators: %s: comma-separated list of other payment add-on slugs */
					__( 'This form has more than one active payment add-on configured (ifthenpay + %s). Only one payment gateway is allowed per form — please remove the extra feeds before customers can pay.', 'ifthenpay-payments-for-gravityforms' ),
					implode( ', ', $competing )
				)
			);
		}

		// (2) Amount sanity check — GF's GFPaymentAddOn::validation() will silently
		// skip our redirect_url() if the resolved amount is zero. Catch that here
		// so the admin/customer sees a real error instead of a no-op submit.
		$entry           = \GFFormsModel::get_current_lead( $form );
		$active_feed     = $our_feeds[0];
		$submission_data = $this->get_submission_data( $active_feed, $form, $entry );
		$amount          = (float) ( $submission_data['payment_amount'] ?? 0 );

		if ( $amount <= 0 ) {
			$this->log_error( __METHOD__ . sprintf( '(): Form #%d submission blocked — resolved payment amount is %.2f. Check the feed\'s Payment Amount Source setting.', $form_id, $amount ) );
			return $this->fail_validation_on_field(
				$validation_result,
				$form,
				__( 'Payment amount could not be determined. The site administrator needs to configure a Payment Amount Source on the ifthenpay feed (a pricing field, a specific form field, or a fixed amount).', 'ifthenpay-payments-for-gravityforms' )
			);
		}

		return $validation_result;
	}

	/**
	 * Marks the validation result as invalid and surfaces the message on our
	 * iftp_pbl field (so the customer sees it inline above the submit button).
	 */
	private function fail_validation_on_field( array $validation_result, array $form, string $message ): array {
		$validation_result['is_valid'] = false;

		foreach ( $form['fields'] ?? [] as &$field ) {
			if ( isset( $field->type ) && $field->type === 'iftp_pbl' ) {
				$field->failed_validation  = true;
				$field->validation_message = $message;
				break;
			}
		}
		unset( $field );

		$validation_result['form'] = $form;
		return $validation_result;
	}

	/**
	 * Filter: gform_get_form_filter
	 *
	 * After the gateway return handler redirects the customer back to the form
	 * page with ?iftp_gf_status=paid|failed|cancelled, this prepends a styled
	 * notice above the form HTML — same UX pattern as GF Stripe / WooCommerce
	 * showing a "Payment received" / "Payment failed" banner after checkout.
	 *
	 * Only renders on forms that actually have an active ifthenpay feed, so we
	 * don't pollute unrelated forms when multiple forms share a page.
	 *
	 * @param string $form_string The form's rendered HTML.
	 * @param array  $form        The GF form object.
	 * @return string
	 */
	public function inject_payment_status_banner( $form_string, $form ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- gateway return flash; not processing form data
		if ( empty( $_GET['iftp_gf_status'] ) ) {
			return $form_string;
		}

		$form_id = (int) rgar( $form, 'id' );
		if ( $form_id <= 0 || empty( $this->get_active_feeds( $form_id ) ) ) {
			return $form_string;
		}

		$status = sanitize_key( wp_unslash( (string) $_GET['iftp_gf_status'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Ensure the banner CSS loads even when the form has no iftp_pbl field
		// (the field's render hook is what normally enqueues frontend.css).
		if ( ! wp_style_is( 'ifthenpay-gf-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'ifthenpay-gf-frontend',
				IFTP_GF_URL . 'assets/css/frontend.css',
				[],
				IFTP_GF_VERSION
			);
		}

		$banners = [
			'paid'      => [
				'class' => 'is-success',
				'title' => __( 'Payment received', 'ifthenpay-payments-for-gravityforms' ),
				'body'  => __( 'Thank you — your payment has been confirmed and the form was submitted successfully.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'pending'   => [
				'class' => 'is-info',
				'title' => __( 'Awaiting payment confirmation', 'ifthenpay-payments-for-gravityforms' ),
				'body'  => __( 'Your payment reference has been generated. The form will be confirmed automatically once ifthenpay receives the payment — you can close this page in the meantime.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'failed'    => [
				'class' => 'is-error',
				'title' => __( 'Payment failed', 'ifthenpay-payments-for-gravityforms' ),
				'body'  => __( 'Something went wrong at the payment gateway and your payment was not processed. Please submit the form again to retry.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'cancelled' => [
				'class' => 'is-cancel',
				'title' => __( 'Payment cancelled', 'ifthenpay-payments-for-gravityforms' ),
				'body'  => __( 'You cancelled the payment before it was completed. Submit the form again if you want to try a different method.', 'ifthenpay-payments-for-gravityforms' ),
			],
		];

		if ( ! isset( $banners[ $status ] ) ) {
			return $form_string;
		}

		$b = $banners[ $status ];

		$banner = sprintf(
			'<div class="iftp-gf-payment-banner gform-theme__no-reset--children %1$s" role="status" aria-live="polite"><div class="iftp-gf-payment-banner__icon" aria-hidden="true"></div><div class="iftp-gf-payment-banner__text"><strong>%2$s</strong><span>%3$s</span></div></div>',
			esc_attr( $b['class'] ),
			esc_html( $b['title'] ),
			esc_html( $b['body'] )
		);

		return $banner . $form_string;
	}

	// -------------------------------------------------------------------------
	// Server-to-server webhook handler (MemberPress pattern)
	//
	// Trigger param: ?iftp_gf_callback=1
	// SUCCESS payload:  ?iftp_gf_callback=1&ref={entry_id}&apk={base64(gateway_key)}&val={amount}&mtd={MB|MBWAY|...}&req={request_id}
	// FAILURE payload:  ?iftp_gf_callback=1&ref={entry_id}&status={cancelled|error}
	//
	// Validates the call against entry meta written by redirect_url() and only
	// then flips the entry through complete_payment() / fail_payment().
	// Always exits with a JSON-ish status code — no HTML, no redirect.
	// -------------------------------------------------------------------------

	public function handle_gateway_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server callback validated by entry-meta crosscheck
		$_GET = wp_unslash($_GET);
		if ( empty( $_GET['iftp_gf_callback'] ) ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
			status_header( 405 );
			exit( 'Method Not Allowed' );
		}

		$ref = isset( $_GET['ref'] ) ? absint( wp_unslash( $_GET['ref'] ) ) : 0;
		if ( $ref <= 0 ) {
			status_header( 400 );
			exit( 'Missing ref' );
		}

		$entry = \GFAPI::get_entry( $ref );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
			status_header( 404 );
			exit( 'Entry not found' );
		}

		// Failure callback: ?status=cancelled|error
		if ( ! empty( $_GET['status'] ) ) {
			$status = sanitize_text_field( wp_unslash( (string) $_GET['status'] ) );
			if ( ! in_array( $status, [ 'cancelled', 'error' ], true ) ) {
				status_header( 400 );
				exit( 'Invalid status' );
			}

			$existing = (string) gform_get_meta( $ref, 'iftp_gf_payment_status' );
			if ( in_array( $existing, [ 'failed', 'cancelled', 'paid' ], true ) ) {
				status_header( 200 );
				exit( 'OK (idempotent)' );
			}

			if ( $status === 'cancelled' ) {
				\GFAPI::update_entry_property( $ref, 'payment_status', 'Cancelled' );
				$this->add_note( $ref, esc_html__( 'Payment cancelled at the ifthenpay gateway (server-side callback).', 'ifthenpay-payments-for-gravityforms' ), 'error' );
				gform_update_meta( $ref, 'iftp_gf_payment_status', 'cancelled' );
			} else {
				$amount = (float) gform_get_meta( $ref, 'iftp_gf_payment_amount' );
				$this->fail_payment( $entry, [
					'transaction_id' => (string) gform_get_meta( $ref, 'iftp_gf_transaction_id' ),
					'amount'         => $amount,
					'type'           => 'fail_payment',
				] );
				gform_update_meta( $ref, 'iftp_gf_payment_status', 'failed' );
			}

			status_header( 200 );
			exit( 'OK' );
		}

		// Success callback: ?ref=&apk=&val=&mtd=&req=
		foreach ( [ 'apk', 'val', 'mtd', 'req' ] as $required ) {
			if ( ! isset( $_GET[ $required ] ) ) {
				status_header( 400 );
				exit( 'Missing ' . esc_html($required) );
			}
		}

		$apk = sanitize_text_field( wp_unslash( (string) ( $_GET['apk'] ?? '' ) ) );
		$val = sanitize_text_field( wp_unslash( (string) ( $_GET['val'] ?? '' ) ) );
		$mtd = sanitize_text_field( wp_unslash( (string) ( $_GET['mtd'] ?? '' ) ) );
		$req = sanitize_text_field( wp_unslash( (string) ( $_GET['req'] ?? '' ) ) );

		// Validate gateway key — apk = base64(gateway_key) (MemberPress convention).
		$decoded_apk      = trim( (string) base64_decode( $apk, true ) );
		$expected_gateway = (string) gform_get_meta( $ref, 'iftp_gf_gateway_key' );
		if ( $decoded_apk === '' || $decoded_apk !== $expected_gateway ) {
			status_header( 403 );
			exit( 'apk mismatch' );
		}

		// Validate amount.
		$expected_amount = (float) gform_get_meta( $ref, 'iftp_gf_payment_amount' );
		if ( $expected_amount <= 0 || (float) $val !== $expected_amount ) {
			status_header( 403 );
			exit( 'amount mismatch' );
		}

		// Validate method against the live keyed catalog.
		$catalog = self::get_keyed_method_catalog();
		if ( ! isset( $catalog[ strtoupper( $mtd ) ] ) && ! is_numeric( $mtd ) ) {
			status_header( 403 );
			exit( 'method invalid' );
		}

		// Idempotency.
		$existing = (string) gform_get_meta( $ref, 'iftp_gf_payment_status' );
		if ( $existing === 'paid' ) {
			status_header( 200 );
			exit( 'OK (already paid)' );
		}

		$this->complete_payment( $entry, [
			'transaction_id'   => $req,
			'amount'           => $expected_amount,
			'payment_method'   => $mtd,
			'payment_date'     => gmdate( 'y-m-d H:i:s' ),
			'transaction_type' => 'payment',
			'type'             => 'complete_payment',
		] );
		gform_update_meta( $ref, 'iftp_gf_payment_status', 'paid' );

		status_header( 200 );
		exit( 'OK' );
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
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
			return;
		}

		// Idempotency guard — gateway sometimes hits the return URL twice.
		$existing = (string) gform_get_meta( $entry_id, 'iftp_gf_payment_status' );
		if ( in_array( $existing, [ 'paid', 'failed', 'cancelled' ], true ) ) {
			$this->redirect_after_return( $entry, $existing );
			return;
		}

		$context = IfthenpayReturn::resolve_return_context( $return_data );
		// Resolve amount: entry property first, then our redirect_url snapshot meta.
		$amount = (float) rgar( $entry, 'payment_amount' );
		if ( $amount <= 0 ) {
			$amount = (float) gform_get_meta( $entry_id, 'iftp_gf_payment_amount' );
		}

		$flash = '';

		if ( $status === 'success' ) {
			$tx = (string) ( $context['transaction_id'] ?? '' );

			// Authoritative payment confirmation: the success redirect alone is not
			// reliable for asynchronous methods (Multibanco/Payshop generate a
			// reference and redirect back without the payment being received yet).
			// /gateway/transaction/status/get returns 200 only when the payment has
			// actually been received by ifthenpay; 404 means "reference exists but
			// not paid". We only call complete_payment() on a true 200.
			$verified = null;
			if ( $tx !== '' ) {
				try {
					$verified = IfthenpayClient::verify_transaction_paid( $tx );
				} catch ( \RuntimeException $e ) {
					$verified = null;
				}
			}

			if ( is_array( $verified ) ) {
				// Trust the API's PaymentMethod over the (often empty) URL param.
				$api_method = (string) ( $verified['PaymentMethod'] ?? $verified['paymentMethod'] ?? '' );
				$method     = $api_method !== '' ? $api_method : (string) ( $context['payment_method'] ?? '' );

				$this->complete_payment( $entry, [
					'transaction_id'   => (string) ( $verified['TransactionId'] ?? $verified['transactionId'] ?? $tx ),
					'amount'           => $amount,
					'payment_method'   => $method,
					'payment_date'     => gmdate( 'y-m-d H:i:s' ),
					'transaction_type' => 'payment',
					'type'             => 'complete_payment',
				] );
				gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'paid' );
				$flash = 'paid';
			} else {
				// 404 from the API — payment reference exists but not yet paid.
				// Common with Multibanco/Payshop. Leave the entry in Processing and
				// add a note so the merchant knows we're awaiting the webhook.
				$this->add_note(
					$entry_id,
					sprintf(
						/* translators: %s: transaction id */
						esc_html__( 'Customer returned from the ifthenpay gateway but payment is not yet received (transaction %s). Awaiting webhook callback.', 'ifthenpay-payments-for-gravityforms' ),
						$tx !== '' ? $tx : '—'
					),
					''
				);
				gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'pending_verification' );
				$flash = 'pending';
			}
		} elseif ( in_array( $status, [ 'cancel', 'cancelled', 'canceled' ], true ) ) {
			\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Cancelled' );
			$this->add_note( $entry_id, esc_html__( 'Payment cancelled by the customer at the ifthenpay gateway.', 'ifthenpay-payments-for-gravityforms' ), 'error' );
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'cancelled' );
			$flash = 'cancelled';
			$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} marked Cancelled." );
		} elseif ( $status === 'error' ) {
			$this->fail_payment( $entry, [
				'transaction_id' => $context['transaction_id'] ?? '',
				'amount'         => $amount,
				'type'           => 'fail_payment',
			] );
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'failed' );
			$flash = 'failed';
			$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} → fail_payment()." );
		}

		$this->redirect_after_return( $entry, $flash );
	}

	/**
	 * Clean-redirect after a gateway return: strip the iftp_gf_* gateway args
	 * from the original source URL so a refresh doesn't re-trigger the handler,
	 * then re-append a single `iftp_gf_status` flash param so the next page render
	 * can display a Stripe-style payment-result banner above the form.
	 */
	private function redirect_after_return( array $entry, string $flash = '' ): void {
		$source_url = (string) rgar( $entry, 'source_url' );
		if ( $source_url === '' ) {
			$source_url = home_url( '/' );
		}

		$clean = remove_query_arg( [ 'iftp_gf_pay', 'id', 'transaction_id', 'iftp_gateway' ], $source_url );
		if ( in_array( $flash, [ 'paid', 'failed', 'cancelled', 'pending' ], true ) ) {
			$clean = add_query_arg( 'iftp_gf_status', $flash, $clean );
		}

		wp_safe_redirect( esc_url_raw( $clean ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: connect / disconnect backoffice key
	// -------------------------------------------------------------------------

	public function ajax_connect_backoffice(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

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

		// Only verify the key is valid — catalog data is fetched lazily when feed settings open.
		try {
			$gateway_rows = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_keys( '' );
		} catch ( \Throwable ) {
			$gateway_rows = [];
		}

		if ( empty( $gateway_rows ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: 1: support email link, 2: sign-up link */
					__( 'No gateway found for this backoffice key. If you are a client, %1$s to request one. Otherwise, %2$s.', 'ifthenpay-payments-for-gravityforms' ),
					'<a href="mailto:suporte@ifthenpay.com">' . esc_html__( 'contact support', 'ifthenpay-payments-for-gravityforms' ) . '</a>',
					'<a href="' . esc_url( self::SIGNUP_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'sign up here', 'ifthenpay-payments-for-gravityforms' ) . '</a>'
				),
			], 400 );
		}

		// Clear any previously cached catalog so the next feed-settings load fetches fresh data.
		delete_option( self::OPTION_GATEWAY_CATALOG );
		delete_option( self::OPTION_METHOD_CATALOG );
		delete_transient( self::TRANSIENT_CATALOG_FRESH );
		IfthenpayClient::bust_available_methods_cache();

		update_option( self::OPTION_BACKOFFICE_KEY, $backoffice_key, false );

		wp_send_json_success( [
			'message'     => __( 'Backoffice Key connected successfully.', 'ifthenpay-payments-for-gravityforms' ),
			'masked_key'  => str_repeat( '*', 18 ),
			'status_html' => $this->render_connection_card_html(),
		] );
	}

	public function ajax_disconnect_backoffice(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-gravityforms' ) ], 403 );
		}

		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_GATEWAY_CATALOG );
		delete_option( self::OPTION_METHOD_CATALOG );
		delete_transient( self::TRANSIENT_CATALOG_FRESH );
		IfthenpayClient::bust_available_methods_cache();

		// Remove all per-form payment info options.
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_FORM_INFO_PREFIX ) . '%'
			)
		);

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

		[ $available_methods, $is_dynamic, $dynamic_accounts ] =
			$this->resolve_available_methods( $gateway_key, $catalog );

		ob_start();
		if ( empty( $available_methods ) ) {
			if ( $is_dynamic ) {
				echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No accounts found for this gateway. Please contact ifthenpay support.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
			} else {
				echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No payment methods found for this gateway key.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
			}
		} else {
			$this->render_methods_list( $available_methods, [], $gateway_key, $is_dynamic, $dynamic_accounts );
		}
		$html = ob_get_clean();

		wp_send_json_success( [
			'html'                  => $html,
			'default_method_options' => $this->build_default_method_options_html(
				$gateway_key, $is_dynamic, $dynamic_accounts, $available_methods
			),
		] );
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

	/**
	 * Returns the method catalog keyed by uppercase entity code.
	 * Each entry now carries full data: label, small_image_url, is_visible, etc.
	 */
	public static function get_keyed_method_catalog(): array {
		// The stored catalog is already entity-keyed in the new format.
		$catalog = self::get_method_catalog();

		// Safety: if legacy list format is stored, re-key it on the fly.
		if ( ! empty( $catalog ) && isset( $catalog[0] ) ) {
			$keyed = [];
			foreach ( $catalog as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$entity = strtoupper( (string) ( $m['entity'] ?? '' ) );
				if ( $entity !== '' ) {
					$keyed[ $entity ] = $m;
				}
			}
			return $keyed;
		}

		return $catalog;
	}

	public static function is_dynamic_gateway( string $gateway_key ): bool {
		$catalog = self::get_gateway_catalog();
		$tipo    = mb_strtolower( (string) ( $catalog[ $gateway_key ]['tipo'] ?? '' ) );
		return $tipo === 'dinâmicas' || $tipo === 'dinamicas';
	}

	public static function get_methods_config(): array {
		return [];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolves available methods, dynamic flag and dynamic accounts for a gateway key.
	 *
	 * @return array{ 0: array, 1: bool, 2: array }
	 */
	private function resolve_available_methods( string $gateway_key, array $catalog ): array {
		$available_methods = [];
		$is_dynamic        = false;
		$dynamic_accounts  = [];

		if ( $gateway_key === '' ) {
			return [ $available_methods, $is_dynamic, $dynamic_accounts ];
		}

		$is_dynamic = self::is_dynamic_gateway( $gateway_key );

		$keyed_catalog = self::get_keyed_method_catalog();

		if ( $is_dynamic ) {
			$dynamic_accounts = $this->fetch_cached_dynamic_accounts( $gateway_key );
		}

		// Always emit one row per visible method from the keyed catalog so unactivated
		// methods can render an "Activate" link (LatePoint / MemberPress / WPForms UX).
		// For dynamic gateways the account is empty until the admin picks one from the
		// dropdown; for static gateways it comes from the gateway-keys row (may be empty
		// if not yet provisioned). Methods marked IsVisible=false in the API response
		// are dropped entirely — the gateway does not support them for new payments.
		foreach ( $keyed_catalog as $entity_key => $cat_entry ) {
			if ( empty( $cat_entry['is_visible'] ) ) {
				continue;
			}
			$entity_key = strtoupper( (string) $entity_key );

			if ( $is_dynamic ) {
				// Dynamic accounts are sourced from $dynamic_accounts at render time;
				// the 'account' here is unused (render_methods_list looks it up by entity).
				$available_methods[ $entity_key ] = [
					'method'  => (string) ( $cat_entry['label'] ?? $entity_key ),
					'account' => '',
				];
			} else {
				$static_method                    = (array) ( $catalog[ $gateway_key ]['methods'][ $entity_key ]
					?? $catalog[ $gateway_key ]['methods'][ strtolower( $entity_key ) ]
					?? [] );
				$available_methods[ $entity_key ] = [
					'method'  => (string) ( $static_method['method'] ?? $cat_entry['label'] ?? $entity_key ),
					'account' => (string) ( $static_method['account'] ?? '' ),
				];
			}
		}

		return [ $available_methods, $is_dynamic, $dynamic_accounts ];
	}

	/**
	 * Builds the HTML for the default_method <select> options for a given gateway.
	 * Only includes methods with AllowSelectedMethod=true.
	 * The "Auto" placeholder is always first.
	 */
	private function build_default_method_options_html(
		string $gateway_key,
		bool $is_dynamic,
		array $dynamic_accounts,
		array $available_methods
	): string {
		$keyed_catalog = self::get_keyed_method_catalog();

		$html  = '<option value="">' . esc_html__( '— Auto (first enabled method) —', 'ifthenpay-payments-for-gravityforms' ) . '</option>';

		foreach ( $available_methods as $entity => $method ) {
			$entity_key = strtoupper( (string) $entity );
			$catalog_entry = $keyed_catalog[ $entity_key ] ?? [];

			// Skip methods the gateway doesn't allow as a pre-selection.
			$allow = $catalog_entry['allow_selected_method'] ?? true;
			if ( ! $allow ) {
				continue;
			}

			$label = (string) ( $method['method'] ?? $entity_key );
			$html .= sprintf(
				'<option value="%s">%s</option>',
				esc_attr( $entity_key ),
				esc_html( $label )
			);
		}

		return $html;
	}

	private function get_default_method_choices(): array {
		$catalog     = self::get_gateway_catalog();
		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		$choices     = [ [ 'label' => __( '— Auto (first enabled method) —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		if ( $gateway_key === '' ) {
			return $choices;
		}

		$keyed_catalog = self::get_keyed_method_catalog();
		$is_dynamic    = self::is_dynamic_gateway( $gateway_key );

		if ( $is_dynamic ) {
			// For dynamic gateways build choices from the cached dynamic accounts.
			$dynamic_accounts = $this->fetch_cached_dynamic_accounts( $gateway_key );
			foreach ( array_keys( $dynamic_accounts ) as $entity ) {
				$entity_key    = strtoupper( (string) $entity );
				$catalog_entry = $keyed_catalog[ $entity_key ] ?? [];

				if ( ! ( $catalog_entry['allow_selected_method'] ?? true ) ) {
					continue;
				}

				$label     = $catalog_entry['label'] ?? $entity_key;
				$choices[] = [ 'label' => $label, 'value' => $entity_key ];
			}
		} else {
			foreach ( $catalog[ $gateway_key ]['methods'] ?? [] as $entity => $method ) {
				$entity_key    = strtoupper( (string) $entity );
				$catalog_entry = $keyed_catalog[ $entity_key ] ?? [];

				if ( ! ( $catalog_entry['allow_selected_method'] ?? true ) ) {
					continue;
				}

				$label     = sanitize_text_field( (string) ( $method['method'] ?? $entity_key ) );
				$choices[] = [ 'label' => $label, 'value' => $entity_key ];
			}
		}

		return $choices;
	}

	private function get_gateway_key_choices(): array {
		$catalog = self::get_gateway_catalog();
		$choices = [ [ 'label' => __( '— Select a gateway key —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		foreach ( $catalog as $key => $gateway ) {
			// Alias-only label (drops the technical key); value stays the gateway key.
			$label     = sanitize_text_field( (string) ( $gateway['label'] ?? $gateway['alias'] ?? $key ) );
			$choices[] = [ 'label' => $label, 'value' => $key ];
		}

		return $choices;
	}

	private function fetch_cached_dynamic_accounts( string $gateway_key ): array {
		$cache_key = 'iftp_gf_dyn_' . md5( $gateway_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$backoffice_key = self::get_backoffice_key();
		if ( $backoffice_key === '' ) {
			return [];
		}

		try {
			$raw      = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_accounts( $gateway_key );
			$accounts = IfthenpayClient::parse_dynamic_accounts( $raw );
			set_transient( $cache_key, $accounts, 6 * HOUR_IN_SECONDS );
			return $accounts;
		} catch ( \Throwable ) {
			return [];
		}
	}

	private function get_active_feed_for_settings(): ?array {
		$feed_id = absint( rgget( 'fid' ) );
		if ( $feed_id <= 0 ) {
			return null;
		}

		$feed = \GFAPI::get_feed( $feed_id );
		return is_array( $feed ) ? $feed : null;
	}

	/**
	 * Sanitizes admin-settings HTML through wp_kses(), preserving form elements.
	 * Used instead of a bare echo so PHPCS EscapeOutput is satisfied.
	 */
	private static function kses_admin_html( string $html ): string {
		static $allowed = null;

		if ( $allowed === null ) {
			$allowed = array_merge(
				wp_kses_allowed_html( 'post' ),
				[
					'input'  => [
						'type'         => true,
						'id'           => true,
						'class'        => true,
						'name'         => true,
						'value'        => true,
						'placeholder'  => true,
						'checked'      => true,
						'disabled'     => true,
						'data-entity'  => true,
						'aria-label'   => true,
						'autocomplete' => true,
						'aria-live'    => true,
					],
					'select' => [
						'id'           => true,
						'class'        => true,
						'name'         => true,
						'data-nonce'   => true,
						'data-entity'  => true,
					],
					'option' => [
						'value'    => true,
						'selected' => true,
						'disabled' => true,
					],
					'button' => [
						'type'             => true,
						'id'               => true,
						'class'            => true,
						'data-entity'      => true,
						'data-gateway-key' => true,
						'disabled'         => true,
						'aria-label'       => true,
					],
					'label'  => [
						'class' => true,
						'for'   => true,
					],
					'img'    => [
						'src'     => true,
						'alt'     => true,
						'loading' => true,
						'class'   => true,
					],
					'table'  => [ 'class' => true ],
					'thead'  => [],
					'tbody'  => [],
					'tr'     => [],
					'th'     => [],
					'td'     => [],
					'h4'     => [ 'style' => true ],
					'div'    => [ 'id' => true, 'class' => true, 'style' => true, 'data-nonce' => true ],
					'span'   => [ 'id' => true, 'class' => true, 'style' => true, 'title' => true, 'aria-live' => true ],
					'p'      => [ 'class' => true, 'style' => true ],
					'em'     => [ 'class' => true ],
					'code'   => [ 'class' => true ],
					'a'      => [ 'href' => true, 'class' => true, 'target' => true, 'rel' => true, 'id' => true ],
				]
			);
		}

		return wp_kses( $html, $allowed );
	}
}
