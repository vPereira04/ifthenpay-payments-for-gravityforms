<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\GravityForms\Api\GFFormData;
use Ifthenpay\GravityForms\Api\IfthenpayClient;
use Ifthenpay\GravityForms\Api\IfthenpayPayload;
use Ifthenpay\GravityForms\Api\IfthenpayReturn;
use Ifthenpay\GravityForms\Mail\IfthenpayEmailHelper;
use Ifthenpay\GravityForms\Repository\FormPaymentInfo;

require_once \IFTP_GF_DIR . 'src/Api/IfthenpayClient.php';
require_once \IFTP_GF_DIR . 'src/Api/IfthenpayPayload.php';
require_once \IFTP_GF_DIR . 'src/Api/IfthenpayReturn.php';
require_once \IFTP_GF_DIR . 'src/Api/GFFormData.php';
require_once \IFTP_GF_DIR . 'src/Mail/IfthenpayEmailHelper.php';
require_once \IFTP_GF_DIR . 'src/Repository/FormPaymentInfo.php';
require_once \IFTP_GF_DIR . 'src/Field/GF_Field_Ifthenpay.php';

class Addon extends \GFPaymentAddOn {



	protected $_version                  = \IFTP_GF_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'iftp_gf';
	protected $_path                     = 'ifthenpay-payments-for-gravityforms/ifthenpay-payments-for-gravityforms.php';
	protected $_full_path                = IFTP_GF_FILE;
	protected $_title                    = 'Ifthenpay | Payments for GravityForms';
	protected $_short_title              = 'ifthenpay';
	protected $_supports_callbacks       = true;
	protected $_requires_credit_card     = false;

	private static ?self $_instance = null;

	private const OPTION_BACKOFFICE_KEY = 'iftp_gf_backofficekey';

	/** Restrict gateway-key listings to the "GravityForms" type only. */
	private const GATEWAY_TYPE = 'GravityForms';

	private const SIGNUP_URL = 'https://ifthenpay.com';



	public static function get_instance(): self {
		if ( self::$_instance === null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}



	public function get_menu_icon(): string {
		return $this->is_gravityforms_supported( '2.5' ) ? 'gform-icon--ifthenpay' : 'dashicons-admin-generic';
	}



	public function init(): void {
		parent::init();

		\GF_Fields::register( new \Ifthenpay\GravityForms\Field\GF_Field_Ifthenpay() );
		add_action( 'template_redirect', [ $this, 'handle_gateway_return' ], 5 );


		add_filter( 'gform_validation', [ $this, 'enforce_single_payment_gateway' ] );


		add_filter( 'gform_get_form_filter', [ $this, 'inject_payment_status_banner' ], 10, 2 );
	}

	public function init_admin(): void {
		parent::init_admin();
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );


		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_styles' ] );
	}

	/**
	 * Loads the field stylesheet and dark-logo script into the Gutenberg
	 * block-editor iframe so the GF form-block preview matches the front-end look.
	 */
	public function enqueue_block_editor_styles(): void {
		wp_enqueue_style(
			'ifthenpay-gf-frontend',
			\IFTP_GF_URL . 'assets/css/frontend.css',
			[],
			\IFTP_GF_VERSION
		);
		wp_enqueue_script(
			'ifthenpay_gf_frontend',
			\IFTP_GF_URL . 'assets/js/frontend.min.js',
			[],
			\IFTP_GF_VERSION,
			true
		);
	}

	public function init_ajax(): void {
		parent::init_ajax();

		add_action( 'wp_ajax_iftp_gf_connect_backoffice',    [ $this, 'ajax_connect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_disconnect_backoffice', [ $this, 'ajax_disconnect_backoffice' ] );
		add_action( 'wp_ajax_iftp_gf_activate_method',       [ $this, 'ajax_activate_payment_method' ] );
		add_action( 'wp_ajax_iftp_gf_get_methods_table',     [ $this, 'ajax_get_methods_table' ] );
	}



	public function scripts(): array {
		$scripts = [
			[
				'handle'  => 'ifthenpay_gf_frontend',
				'src'     => \IFTP_GF_URL . 'assets/js/frontend.js',
				'version' => \IFTP_GF_VERSION,
				'deps'    => [],
				'enqueue' => [
					[ 'field_types' => [ 'iftp_pbl' ] ],
				],
			],
			[
				'handle'  => 'ifthenpay_gf_admin',
				'src'     => \IFTP_GF_URL . 'assets/js/admin.min.js',
				'version' => \IFTP_GF_VERSION,
				'deps'    => [ 'jquery' ],
				'strings' => [
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'iftp_gf_admin' ),
					'connecting'          => __( 'Connecting...', 'ifthenpay-payments-for-gravityforms' ),
					'disconnecting'       => __( 'Disconnecting...', 'ifthenpay-payments-for-gravityforms' ),
					'connect'             => __( 'Connect', 'ifthenpay-payments-for-gravityforms' ),
					'disconnect'          => __( 'Disconnect', 'ifthenpay-payments-for-gravityforms' ),
					'generic_error'       => __( 'Request failed. Please try again.', 'ifthenpay-payments-for-gravityforms' ),
					'activation_button'   => __( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ),
					'activation_sending'  => __( 'Sending...', 'ifthenpay-payments-for-gravityforms' ),
					'activation_sent'     => __( 'Activation request sent.', 'ifthenpay-payments-for-gravityforms' ),
					'activation_cooldown'  => __( 'Request already sent. Please wait 24 hours.', 'ifthenpay-payments-for-gravityforms' ),
					'methods_loading'      => __( 'Loading payment methods…', 'ifthenpay-payments-for-gravityforms' ),
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
				'src'     => \IFTP_GF_URL . 'assets/css/admin.css',
				'version' => \IFTP_GF_VERSION,
				'enqueue' => [
					[ 'admin_page' => [ 'plugin_settings' ] ],
					[ 'admin_page' => [ 'form_settings' ] ],
					[ 'admin_page' => [ 'form_editor' ] ],
				],
			],
			[
				'handle'  => 'ifthenpay-gf-frontend',
				'src'     => \IFTP_GF_URL . 'assets/css/frontend.css',
				'version' => \IFTP_GF_VERSION,
				'enqueue' => [
					[ 'field_types' => [ 'iftp_pbl' ] ],
				],
			],
		];

		return array_merge( parent::styles(), $styles );
	}



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

		<div class="iftp-gf-key-row<?php echo $is_connected ? ' iftp-gf-key-row--hidden' : ''; ?>">
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

		<?php echo $this->render_connection_card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized inside render_connection_card_html() ?>

		<p><span class="iftp-gf-message" aria-live="polite"></span></p>

		<input type="hidden" id="iftp-gf-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php
		$html = (string) ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo $this->kses_admin_html( $html );
		}

		return $html;
	}



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
						'tooltip' => __( 'Select the ifthenpay gateway key for this form. The payment methods table updates automatically when you change the selection.', 'ifthenpay-payments-for-gravityforms' ),
						'choices' => $this->get_gateway_key_choices(),
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
	 * Custom settings field renderer for the payment methods list (feed settings page).
	 */
	public function settings_iftp_gf_methods_table( mixed $_field, bool $echo = true ): string {
		$gateway_key    = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		$methods_config = $this->read_methods_config_from_active_feed();

		ob_start();
		?>
		<div id="iftp-gf-methods-table-wrapper">
			<?php
			echo $this->render_methods_table_inner( $gateway_key, $methods_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-sanitized below
			?>
		</div>
		<?php
		$html = (string) ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo self::kses_admin_html( $html );
		}

		return $html;
	}

	/**
	 * Inner content of the methods table — without the #iftp-gf-methods-table-wrapper div.
	 * Used by both the page-render path (settings_iftp_gf_methods_table) and the
	 * AJAX gateway-key change handler (ajax_get_methods_table) so the markup is
	 * defined once and cannot drift.
	 *
	 * @param array<string, array<string, mixed>> $methods_config Previously-saved checkbox state.
	 */
	private function render_methods_table_inner( string $gateway_key, array $methods_config ): string {
		$methods = $this->build_method_rows_for_gateway( $gateway_key );

		ob_start();
		if ( $gateway_key === '' ) {
			echo '<p class="iftp-gf-no-methods">' . esc_html__( 'Select a gateway key above to load available payment methods.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
		} elseif ( empty( $methods ) ) {
			echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No payment methods are provisioned on this gateway.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
		} else {
			$this->render_methods_list( $methods, $methods_config );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Reads the methods_config sub-array from the currently-edited feed (if any).
	 * That's what carries the admin's checkbox state across renders.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read_methods_config_from_active_feed(): array {
		$feed = $this->get_active_feed_for_settings();
		if ( $feed === null || empty( $feed['meta']['methods_config'] ) ) {
			return [];
		}
		$raw = $feed['meta']['methods_config'];
		return is_string( $raw ) ? (array) json_decode( $raw, true ) : (array) $raw;
	}

	/**
	 * Builds the renderable list of payment methods for a given gateway key by
	 * joining the fresh available-methods catalog (visible only) against the
	 * gateway's row (which carries the per-method account values).
	 *
	 * @return array<int, array<string, string>>
	 */
	private function build_method_rows_for_gateway( string $gateway_key ): array {
		if ( $gateway_key === '' ) {
			return [];
		}

		$row = $this->find_gateway_row( $gateway_key );
		if ( $row === null ) {
			return [];
		}

		$rows = [];
		foreach ( $this->fetch_visible_methods_keyed() as $entity => $cat_entry ) {
			$rows[] = [
				'entity'         => $entity,
				'label'          => (string) ( $cat_entry['label'] ?? $entity ),

				'account'        => $this->resolve_account_in_row( $row, $entity, (string) ( $cat_entry['label'] ?? '' ) ),

				'image_url'      => (string) ( $cat_entry['small_image_url'] ?? $cat_entry['image_url'] ?? '' ),
				'image_url_dark' => (string) ( $cat_entry['small_image_url_dark'] ?? '' ),

				'position'       => (int) ( $cat_entry['position'] ?? 0 ),
			];
		}

		return $rows;
	}

	/**
	 * Renders the methods table inside the feed-settings page. Static-only,
	 * one row per provisioned method, checkbox bound to is_active.
	 *
	 * @param array<int, array<string, string>>           $methods
	 * @param array<string, array<string, mixed>>         $methods_config
	 */
	private function render_methods_list( array $methods, array $methods_config ): void {

		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		?>
		<div class="iftp-gf-methods-list">
			<?php foreach ( $methods as $method ) :
				$entity_key    = $method['entity'];
				$method_label  = $method['label'];
				$account       = $method['account'];
				$is_provisioned = ( $account !== '' );
				$logo_url      = $method['image_url'] !== ''
					? $method['image_url']
					: IfthenpayPayload::fallback_logo_url( $entity_key );

				$is_enabled    = $is_provisioned && ! empty( $methods_config[ $entity_key ]['enabled'] );
				$is_wide_logo  = $entity_key === 'CCARD';

				$item_classes = 'iftp-gf-method-item';
				if ( $is_enabled )         { $item_classes .= ' iftp-gf-method-item--enabled'; }
				if ( ! $is_provisioned )   { $item_classes .= ' iftp-gf-method-item--unactivated'; }
			?>
				<div class="<?php echo esc_attr( $item_classes ); ?>" data-entity="<?php echo esc_attr( $entity_key ); ?>">
					<label class="iftp-gf-method-item__label">
						<input
							type="checkbox"
							name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled ); ?>
							<?php disabled( ! $is_provisioned ); ?>
							class="iftp-gf-method-item__toggle"
							data-entity="<?php echo esc_attr( $entity_key ); ?>"
						/>
						<input
							type="hidden"
							name="_gform_setting_methods_config[<?php echo esc_attr( $entity_key ); ?>][account]"
							value="<?php echo esc_attr( $account ); ?>"
						/>
						<span class="iftp-gf-method-item__icon-wrap<?php echo $is_wide_logo ? ' iftp-gf-method-item__icon-wrap--wide' : ''; ?>">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $method_label ); ?>" loading="lazy" />
						</span>
						<span class="iftp-gf-method-item__name"><?php echo esc_html( $method_label ); ?></span>
					</label>

					<div class="iftp-gf-method-item__right">
						<?php if ( $is_provisioned ) : ?>
							<code class="iftp-gf-method-item__account"><?php echo esc_html( $account ); ?></code>
							<span class="iftp-gf-method-item__active-badge" title="<?php esc_attr_e( 'Provisioned', 'ifthenpay-payments-for-gravityforms' ); ?>">&#10003;</span>
						<?php else : ?>
							<em class="iftp-gf-method-item__no-account"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-gravityforms' ); ?></em>
							<button
								type="button"
								class="button button-small iftp-gf-method-item__activate-btn"
								data-entity="<?php echo esc_attr( $entity_key ); ?>"
								data-gateway-key="<?php echo esc_attr( $gateway_key ); ?>"
							>
								<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-gravityforms' ); ?>
							</button>
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

		$gateways          = $this->fetch_gravityforms_gateways();
		$visible_methods   = $this->fetch_visible_methods_keyed();

		ob_start();

		if ( empty( $gateways ) ) {
			echo '<p class="iftp-gf-no-methods">' . esc_html__( 'No GravityForms-type gateways found for this backoffice key. Contact ifthenpay support if you expect one.', 'ifthenpay-payments-for-gravityforms' ) . '</p>';
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
					<?php foreach ( $gateways as $row ) :
						$key   = (string) ( $row['GatewayKey'] ?? '' );
						$alias = (string) ( $row['Alias'] ?? '' );
						if ( $key === '' ) {
							continue;
						}
						$provisioned = [];
						foreach ( $visible_methods as $entity => $cat_entry ) {
							if ( $this->resolve_account_in_row( $row, $entity, (string) ( $cat_entry['label'] ?? '' ) ) !== '' ) {
								$provisioned[] = $entity;
							}
						}
					?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( $alias ); ?></td>
							<td>
								<?php
								if ( ! empty( $provisioned ) ) {
									echo esc_html( implode( ', ', $provisioned ) );
								} else {
									echo '<em>' . esc_html__( 'No provisioned methods on this gateway.', 'ifthenpay-payments-for-gravityforms' ) . '</em>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		$html = (string) ob_get_clean();

		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized via static kses_admin_html().
			echo self::kses_admin_html( $html );
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

		if ( empty( $settings['transactionType'] ) ) {
			$settings['transactionType'] = 'product';
		}


		if ( ! empty( $settings['gateway_key'] ) ) {
			$raw            = $settings['methods_config'] ?? [];
			$methods_config = is_string( $raw ) ? (array) json_decode( $raw, true ) : (array) $raw;
			$any_enabled    = false;
			foreach ( $methods_config as $cfg ) {
				if ( ! empty( $cfg['enabled'] ) ) {
					$any_enabled = true;
					break;
				}
			}
			if ( ! $any_enabled ) {
				\GFCommon::add_error_message(
					__( 'Please enable at least one payment method before saving.', 'ifthenpay-payments-for-gravityforms' )
				);
				return false;
			}
		}

		$result = parent::save_feed_settings( $feed_id, $form_id, $settings );
		if ( $result ) {
			$saved_feed_id   = (int) $result;
			$forced_inactive = false;


			if ( $this->has_other_active_feed( (int) $form_id, $saved_feed_id ) ) {
				$this->update_feed_active( $saved_feed_id, 0 );
				$forced_inactive = true;
				\GFCommon::add_error_message(
					__( 'Another ifthenpay feed is already active on this form. Deactivate it first before activating this one.', 'ifthenpay-payments-for-gravityforms' )
				);
			}


			if ( ! $forced_inactive ) {
				$this->sync_form_payment_info( (int) $form_id, $settings, $saved_feed_id );

				$gateway_key = (string) ( $settings['gateway_key'] ?? '' );
				if ( $gateway_key !== '' ) {
					$this->activate_callback_for_gateway( $gateway_key );
				}
			}
		}
		return $result;
	}

	/**
	 * Intercepts the activate/deactivate toggle in the feeds list. Blocks
	 * activation when another ifthenpay feed on the same form is already
	 * active and surfaces an admin error message; deactivation is always
	 * allowed.
	 *
	 * @param int|string $feed_id
	 * @param int|bool   $is_active
	 * @return bool
	 */
	public function update_feed_active( $feed_id, $is_active ) {
		$feed_id_int = (int) $feed_id;
		$activating  = ! empty( $is_active );
		$feed        = null;

		if ( $activating && $feed_id_int > 0 ) {
			$feed = $this->get_feed( $feed_id_int );
			if ( $feed ) {
				$form_id = (int) ( $feed['form_id'] ?? 0 );
				if ( $form_id > 0 && $this->has_other_active_feed( $form_id, $feed_id_int ) ) {
					\GFCommon::add_error_message(
						__( 'Another ifthenpay feed is already active on this form. Deactivate it first before activating this one.', 'ifthenpay-payments-for-gravityforms' )
					);
					return false;
				}
			}
		}

		$result = parent::update_feed_active( $feed_id, $is_active );


		if ( $result && $activating && $feed !== null ) {
			$form_id = (int) ( $feed['form_id'] ?? 0 );
			if ( $form_id > 0 ) {
				$per_feed_snapshot = FormPaymentInfo::get_for_feed( $feed_id_int );
				if ( ! empty( $per_feed_snapshot ) ) {
					FormPaymentInfo::save( $form_id, $per_feed_snapshot );
				} else {

					$this->sync_form_payment_info( $form_id, (array) ( $feed['meta'] ?? [] ) );
				}
			}
		}

		return $result;
	}

	/**
	 * Clean up the per-feed snapshot when a feed is deleted.
	 *
	 * @param int $id Feed ID.
	 */
	public function delete_feed( $id ): void {
		FormPaymentInfo::delete_for_feed( (int) $id );
		parent::delete_feed( $id );
	}

	/**
	 * @return bool true when any *other* ifthenpay feed on `$form_id` is currently active.
	 */
	private function has_other_active_feed( int $form_id, int $exclude_feed_id ): bool {
		$feeds = \GFAPI::get_feeds( null, $form_id, $this->get_slug() );
		if ( ! is_array( $feeds ) ) {
			return false;
		}
		foreach ( $feeds as $feed ) {
			$fid = (int) ( $feed['id'] ?? 0 );
			if ( $fid > 0 && $fid !== $exclude_feed_id && ! empty( $feed['is_active'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds and persists the per-form payment snapshot (and, when $feed_id > 0,
	 * also a per-feed snapshot so the config can be restored without an API call).
	 * Option keys: `ifthenpay_gf_form_{$form_id}` / `ifthenpay_gf_feed_{$feed_id}`.
	 */
	public function sync_form_payment_info( int $form_id, array $settings, int $feed_id = 0 ): void {
		$gateway_key = (string) ( $settings['gateway_key'] ?? '' );
		if ( $gateway_key === '' ) {
			FormPaymentInfo::delete( $form_id );
			return;
		}


		$method_rows         = $this->build_method_rows_for_gateway( $gateway_key );
		$methods_config_raw  = $settings['methods_config'] ?? [];
		$methods_config      = is_string( $methods_config_raw )
			? (array) json_decode( $methods_config_raw, true )
			: (array) $methods_config_raw;

		$pay_methods = [];
		foreach ( $method_rows as $row ) {

			if ( (string) ( $row['account'] ?? '' ) === '' ) {
				continue;
			}
			$position      = $row['position'];
			$entity        = $row['entity'];
			$is_active     = ! empty( $methods_config[ $entity ]['enabled'] );
			$pay_methods[] = [
				'entity'       => $entity,
				'account'      => $row['account'],
				'is_active'    => $is_active,
				'position'     => $position,
				'img_url'      => $row['image_url'],
				'img_url_dark' => (string) ( $row['image_url_dark'] ?? '' ),
			];
		}

		$snapshot = [
			'gateway_key'     => $gateway_key,
			'default_method'  => strtoupper( (string) ( $settings['default_method'] ?? '' ) ),
			'pay_description' => sanitize_text_field( (string) ( $settings['description'] ?? '' ) ),
			'pay_methods'     => $pay_methods,
		];

		FormPaymentInfo::save( $form_id, $snapshot );


		if ( $feed_id > 0 ) {
			FormPaymentInfo::save_for_feed( $feed_id, $snapshot );
		}
	}

	/**
	 * Returns the stored per-form snapshot, or an empty array if none exists.
	 * No lazy migration: the snapshot is written every time `save_feed_settings`
	 * runs, so if it's missing it means the admin has never saved the feed.
	 */
	public static function get_form_payment_info( int $form_id ): array {
		return FormPaymentInfo::get( $form_id );
	}

	/**
	 * Registers the webhook callback URL for the given gateway key with ifthenpay.
	 *
	 * Called every time a feed is saved with a gateway key. Re-activation is
	 * idempotent — ifthenpay simply updates the registered URL. Failures are
	 * logged but do not block the feed save; async payments will still resolve
	 * via the expiry safety-net check if the webhook never fires.
	 */
	private function activate_callback_for_gateway( string $gateway_key ): void {
		$base_url  = add_query_arg( 'callback', $this->get_slug(), home_url( '/' ) );
		$activated = IfthenpayClient::activate_callback( $gateway_key, $base_url );

		if ( $activated ) {
			$this->log_debug( sprintf(
				'%s(): Callback activated for gateway key %s.',
				__METHOD__,
				$gateway_key
			) );
		} else {
			$this->log_error( sprintf(
				'%s(): Failed to activate callback for gateway key %s. Async payments will fall back to the expiry safety-net check.',
				__METHOD__,
				$gateway_key
			) );
		}
	}



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
		$form_id  = (int) rgar( $form, 'id' );
		$entry_id = (int) rgar( $entry, 'id' );


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


		$pay_methods = (array) ( $form_info['pay_methods'] ?? [] );
		if ( empty( $pay_methods ) ) {
			$this->log_error( __METHOD__ . '(): No pay_methods in form info for form #' . $form_id );
			return '';
		}

		$account_parts        = [];
		$active_method_config = [];
		$selected_position    = 0;
		$default_method = strtoupper( (string) ( $form_info['default_method'] ?? '' ) );

		foreach ( $pay_methods as $method ) {
			if ( empty( $method['is_active'] ) ) {
				continue;
			}
			$entity = strtoupper( (string) ( $method['entity'] ?? '' ) );
			$acct   = trim( (string) ( $method['account'] ?? '' ) );
			$position = abs((int) ($method['position'] ?? 0));

			if ( $entity === '' || $acct === '' || $position === 0) {
				continue;
			}
			$account_parts[]              = preg_replace( '/\s*\|\s*/', '|', $acct );

			if ( $entity === $default_method ) {
				$selected_position = $position;
			}

			$active_method_config[ $entity ] = [ 'enabled' => '1', 'account' => $acct, 'position' => $position];
		}
		$accounts = implode( ';', $account_parts );

		if ( $accounts === '' ) {
			$this->log_error( __METHOD__ . '(): No methods flagged is_active for form #' . $form_id );
			return '';
		}

		$source_url = (string) rgar( $entry, 'source_url' );
		$base_url   = $source_url !== '' ? $source_url : home_url( '/' );

		$urls = IfthenpayPayload::build_gateway_urls( $entry_id, $base_url );

		$customer_email = GFFormData::get_customer_email( $form, $entry );
		$customer_name  = GFFormData::get_customer_name( $form, $entry );

		$description    = sanitize_text_field( (string) ( $form_info['pay_description'] ?? '' ) );

		$payload = IfthenpayPayload::build_pay_by_link_payload( [
			'id'              => (string) $entry_id,
			'amount'          => $amount,
			'description'     => $description !== '' ? $description : get_bloginfo( 'name' ),
			'accounts'        => $accounts,
			'success_url'     => $urls['success_url'],
			'error_url'       => $urls['error_url'],
			'cancel_url'      => $urls['cancel_url'],

			'selected_method' => $selected_position > 0 ? (string) $selected_position : '',
			'email'           => $customer_email,
			'name'            => $customer_name,
		] );

		$this->log_debug( sprintf(
			'%s(): Entry #%d → calling create_payment_link gateway=%s amount=%.2f accounts=%s selected=%d',
			__METHOD__,
			$entry_id,
			$gateway_key,
			$amount,
			$accounts,
			$selected_position
		) );

		try {
			$response = IfthenpayClient::create_payment_link( $gateway_key, $payload );
		} catch ( \RuntimeException $e ) {
			$this->log_error( __METHOD__ . '(): API error — ' . $e->getMessage() );
			return '';
		}


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

		gform_update_meta( $entry_id, 'iftp_gf_payment_amount', $amount );
		\GFAPI::update_entry_property( $entry_id, 'payment_amount', $amount );

		\GFAPI::update_entry_property( $entry_id, 'payment_status', 'Processing' );

		$this->log_debug( __METHOD__ . "(): Entry #{$entry_id} → redirect to ifthenpay. TxID: {$transaction_id}" );

		return $redirect_url;
	}



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


		$our_feeds = $this->get_active_feeds( $form_id );
		if ( empty( $our_feeds ) ) {
			return $validation_result;
		}


		if ( self::get_backoffice_key() === '' ) {
			return $this->fail_validation_on_field(
				$validation_result,
				$form,
				__( 'Payment is not available: ifthenpay is not connected. Please contact the site administrator.', 'ifthenpay-payments-for-gravityforms' )
			);
		}


		$form_info      = self::get_form_payment_info( $form_id );
		$active_methods = array_filter(
			(array) ( $form_info['pay_methods'] ?? [] ),
			static fn( array $m ): bool => ! empty( $m['is_active'] )
		);
		if ( empty( $active_methods ) ) {
			return $this->fail_validation_on_field(
				$validation_result,
				$form,
				__( 'Payment is not available: no payment methods are enabled for this form. Please contact the site administrator.', 'ifthenpay-payments-for-gravityforms' )
			);
		}


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


		$entry           = \GFFormsModel::get_current_lead( $form );
		$active_feed     = $our_feeds[0];
		$submission_data = $this->get_submission_data( $active_feed, $form, $entry );
		$amount          = (float) ( $submission_data['payment_amount'] ?? 0 );

		if ( $amount <= 0 ) {
			$this->log_error( __METHOD__ . sprintf( '(): Form #%d submission blocked — resolved payment amount is %.2f. Add a pricing field to the form.', $form_id, $amount ) );
			return $this->fail_validation_on_field(
				$validation_result,
				$form,
				__( 'Payment amount could not be determined. The form needs at least one pricing field (Product, Total, Shipping, or Quantity) for the amount to be calculated.', 'ifthenpay-payments-for-gravityforms' )
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


		if ( ! wp_style_is( 'ifthenpay-gf-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'ifthenpay-gf-frontend',
				\IFTP_GF_URL . 'assets/css/frontend.css',
				[],
				\IFTP_GF_VERSION
			);
		}


		$banners = [
			'paid'      => [
				'variant' => 'success',
				'svg'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="20" height="20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>',
				'title'   => __( 'Payment received', 'ifthenpay-payments-for-gravityforms' ),
				'body'    => __( 'Thank you — your payment has been confirmed and the form was submitted successfully.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'pending'   => [
				'variant' => 'info',
				'svg'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="20" height="20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>',
				'title'   => __( 'Awaiting payment confirmation', 'ifthenpay-payments-for-gravityforms' ),
				'body'    => __( 'Your payment reference has been generated. The form will be confirmed automatically once ifthenpay receives the payment — you can close this page in the meantime.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'failed'    => [
				'variant' => 'error',
				'svg'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="20" height="20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>',
				'title'   => __( 'Payment failed', 'ifthenpay-payments-for-gravityforms' ),
				'body'    => __( 'Something went wrong! Your payment was not processed. Please submit the form again to retry.', 'ifthenpay-payments-for-gravityforms' ),
			],
			'cancelled' => [
				'variant' => 'cancel',
				'svg'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="20" height="20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
				'title'   => __( 'Payment cancelled', 'ifthenpay-payments-for-gravityforms' ),
				'body'    => __( 'You cancelled the payment before it was completed. Submit the form again if you want to try a different method.', 'ifthenpay-payments-for-gravityforms' ),
			],
		];

		if ( ! isset( $banners[ $status ] ) ) {
			return $form_string;
		}

		$b         = $banners[ $status ];
		$icon_span = '<span class="iftp-gf-return-notice__icon" aria-hidden="true">' . $b['svg'] . '</span>';

		$role = in_array( $b['variant'], [ 'error', 'cancel' ], true ) ? 'alert' : 'status';
		$live = in_array( $b['variant'], [ 'error', 'cancel' ], true ) ? 'assertive' : 'polite';

		$banner = sprintf(
			'<div class="iftp-gf-return-notice iftp-gf-return-notice--%1$s" role="%5$s" aria-live="%6$s" tabindex="-1">'
			. '%2$s'
			. '<div class="iftp-gf-return-notice__text"><strong class="iftp-gf-return-notice__title">%3$s</strong><p class="iftp-gf-return-notice__body">%4$s</p></div>'
			. '</div>',
			esc_attr( $b['variant'] ),
			$icon_span,
			esc_html( $b['title'] ),
			esc_html( $b['body'] ),
			$role,
			$live
		);

		return $banner . $form_string;
	}



	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server callback validated by entry-meta crosscheck
	public function callback() {
		$_GET = wp_unslash( $_GET );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( $request_method !== 'GET' ) {
			status_header( 405 );
			exit( 'Method Not Allowed' );
		}

		$ref = isset( $_GET['ref'] ) ? absint( $_GET['ref'] ) : 0;
		if ( $ref <= 0 ) {
			status_header( 400 );
			exit( 'Missing ref' );
		}

		$entry = \GFAPI::get_entry( $ref );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
			status_header( 404 );
			exit( 'Entry not found' );
		}

		/* TEST STARTS HERE
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['force'] ) && current_user_can( 'manage_options' ) ) {
			$existing = (string) gform_get_meta( $ref, 'iftp_gf_payment_status' );
			if ( in_array( $existing, [ 'paid', 'failed', 'cancelled' ], true ) ) {
				status_header( 200 );
				exit( 'Payment already processed (Payed, Failed or Cancelled)!' );
			}

			$amount = (float) gform_get_meta( $ref, 'iftp_gf_payment_amount' );
			$fail   = isset( $_GET['fail'] ) ? sanitize_text_field( (string) $_GET['fail'] ) : '';

			if ( in_array( $fail, [ 'cancelled', 'error' ], true ) ) {
				return [
					'type'           => 'fail_payment',
					'entry_id'       => $ref,
					'amount'         => $amount,
					'transaction_id' => '',
					'payment_status' => $fail === 'cancelled' ? 'Cancelled' : 'Failed',
					'note'           => '[TEST] ' . ( $fail === 'cancelled' ? 'Cancelled' : 'Failed' ) . ' by force param.',
				];
			}

			return [
				'type'             => 'complete_payment',
				'entry_id'         => $ref,
				'amount'           => $amount,
				'transaction_id'   => 'TEST_' . time(),
				'payment_method'   => sanitize_text_field( (string) ( $_GET['method'] ?? 'MB' ) ),
				'payment_date'     => gmdate( 'Y-m-d H:i:s' ),
				'transaction_type' => 'payment',
			];
		}
		/* TEST ENDS HERE */


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

			$amount = (float) gform_get_meta( $ref, 'iftp_gf_payment_amount' );
			$txid   = (string) gform_get_meta( $ref, 'iftp_gf_transaction_id' );

			return [
				'type'           => 'fail_payment',
				'entry_id'       => $ref,
				'amount'         => $amount,
				'transaction_id' => $txid,
				'payment_status' => $status === 'cancelled' ? 'Cancelled' : 'Failed',
				'note'           => $status === 'cancelled'
					? esc_html__( 'Payment cancelled at the ifthenpay gateway (server-side callback).', 'ifthenpay-payments-for-gravityforms' )
					: esc_html__( 'Payment failed at the ifthenpay gateway (server-side callback).', 'ifthenpay-payments-for-gravityforms' ),
			];
		}


		foreach ( [ 'apk', 'val', 'mtd', 'req' ] as $required ) {
			if ( ! isset( $_GET[ $required ] ) ) {
				status_header( 400 );
				exit( 'Missing ' . esc_html( $required ) );
			}
		}
		// phpcs:Disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by entry-meta crosscheck and strict format checks below + validated again in complete_payment() before marking paid
		$apk = sanitize_text_field( (string) wp_unslash($_GET['apk'] ) );
		$val = sanitize_text_field( (string) wp_unslash($_GET['val'] ) );
		$mtd = sanitize_text_field( (string) wp_unslash($_GET['mtd'] ) );
		$req = sanitize_text_field( (string) wp_unslash($_GET['req'] ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by entry-meta crosscheck and strict format checks below + validated again in complete_payment() before marking paid


		$decoded_apk      = trim( (string) base64_decode( $apk, true ) );
		$expected_gateway = (string) gform_get_meta( $ref, 'iftp_gf_gateway_key' );
		if ( $decoded_apk === '' || $decoded_apk !== $expected_gateway ) {
			status_header( 403 );
			exit( 'apk mismatch' );
		}


		$expected_amount = (float) gform_get_meta( $ref, 'iftp_gf_payment_amount' );
		if ( $expected_amount <= 0 || (float) $val !== $expected_amount ) {
			status_header( 403 );
			exit( 'amount mismatch' );
		}


		$catalog = $this->fetch_visible_methods_keyed();
		if ( ! isset( $catalog[ strtoupper( $mtd ) ] ) && ! is_numeric( $mtd ) ) {
			status_header( 403 );
			exit( 'method invalid' );
		}


		$existing = (string) gform_get_meta( $ref, 'iftp_gf_payment_status' );
		if ( $existing === 'paid' ) {
			status_header( 200 );
			exit( 'OK (already paid)' );
		}

		return [
			'type'             => 'complete_payment',
			'entry_id'         => $ref,
			'amount'           => $expected_amount,
			'transaction_id'   => $req,
			'payment_method'   => $mtd,
			'payment_date'     => gmdate( 'Y-m-d H:i:s' ),
			'transaction_type' => 'payment',
		];
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function post_callback( $callback_action, $result ): void {
		if ( ! is_array( $callback_action ) || ! $result ) {
			return;
		}

		$entry_id = (int) rgar( $callback_action, 'entry_id' );
		if ( $entry_id <= 0 ) {
			return;
		}

		$type = (string) rgar( $callback_action, 'type' );
		if ( $type === 'complete_payment' ) {
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', 'paid' );
		} elseif ( $type === 'fail_payment' ) {
			$ps = strtolower( (string) rgar( $callback_action, 'payment_status', 'Failed' ) );
			gform_update_meta( $entry_id, 'iftp_gf_payment_status', $ps === 'cancelled' ? 'cancelled' : 'failed' );
		}
	}



	public function handle_gateway_return(): void {
		$return_data = IfthenpayReturn::get_return_data_from_request();
		if ( empty( $return_data ) ) {
			return;
		}

		$entry_id = (int) ( $return_data['entry_id'] ?? 0 );
		if ( $entry_id <= 0 ) {
			return;
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
			return;
		}


		$existing = (string) gform_get_meta( $entry_id, 'iftp_gf_payment_status' );
		if ( in_array( $existing, [ 'paid', 'failed', 'cancelled' ], true ) ) {
			$this->redirect_after_return( $entry, $existing );
			return;
		}

		$status = IfthenpayReturn::get_return_status( $return_data );


		$flash = match ( true ) {
			$status === 'success'                                              => 'pending',
			in_array( $status, [ 'cancel', 'cancelled', 'canceled' ], true ) => 'cancelled',
			$status === 'error'                                                => 'failed',
			default                                                            => '',
		};

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


		try {
			$gateway_rows = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_keys();
		} catch ( \Throwable ) {
			$gateway_rows = [];
		}

		if ( empty( $gateway_rows ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					__( 'No gateway found for this backoffice key. If you are a client, request one. Otherwise, contact Ifthenpay to become one.', 'ifthenpay-payments-for-gravityforms' ),
				),
			], 400 );
		}

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
		FormPaymentInfo::delete_all();

		wp_send_json_success( [
			'message'     => __( 'Backoffice Key disconnected.', 'ifthenpay-payments-for-gravityforms' ),
			'status_html' => $this->render_connection_card_html(),
		] );
	}



	public function ajax_get_methods_table(): void {
		check_ajax_referer( 'iftp_gf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-gravityforms' ) ], 403 );
		}

		$gateway_key = sanitize_text_field( wp_unslash( (string) ( $_POST['gateway_key'] ?? '' ) ) );

		$table_html = $this->render_methods_table_inner( $gateway_key, [] );


		$default_options = '<option value="">' . esc_html__( '— Auto (first enabled method) —', 'ifthenpay-payments-for-gravityforms' ) . '</option>';
		if ( $gateway_key !== '' ) {
			$row = $this->find_gateway_row( $gateway_key );
			if ( $row !== null ) {
				foreach ( $this->fetch_visible_methods_keyed() as $entity => $cat_entry ) {
					$account = $this->resolve_account_in_row( $row, $entity, (string) ( $cat_entry['label'] ?? '' ) );
					if ( $account === '' ) {
						continue;
					}
					$default_options .= '<option value="' . esc_attr( $entity ) . '">'
						. esc_html( (string) ( $cat_entry['label'] ?? $entity ) )
						. '</option>';
				}
			}
		}

		wp_send_json_success( [
			'table_html'      => $table_html,
			'default_options' => $default_options,
		] );
	}



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
			'plugin_version' => \IFTP_GF_VERSION,
		] );

		set_transient( $cooldown_key, 1, DAY_IN_SECONDS );

		wp_send_json_success( [ 'message' => __( 'Activation request sent to ifthenpay support.', 'ifthenpay-payments-for-gravityforms' ) ] );
	}



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
		return (string) ob_get_clean();
	}



	public static function get_backoffice_key(): string {
		return trim( (string) get_option( self::OPTION_BACKOFFICE_KEY, '' ) );
	}



	/**
	 * Calls GET /gateway/get?type=GravityForms and returns the raw row list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_gravityforms_gateways(): array {
		static $rows = null;
		if ( $rows !== null ) {
			return $rows;
		}

		$backoffice_key = self::get_backoffice_key();
		if ( $backoffice_key === '' ) {
			$rows = [];
			return $rows;
		}

		try {
			$rows = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_keys();
		} catch ( \Throwable $e ) {
			$this->log_error( __METHOD__ . '(): ' . $e->getMessage() );
			$rows = [];
		}

		return $rows;
	}

	/**
	 * Calls GET /gateway/methods/available, drops anything with IsVisible=false,
	 * and returns the rest keyed by uppercase Entity. Each entry retains
	 * Method, ImageUrl, SmallImageUrl, Position, AllowSelectedMethod.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fetch_visible_methods_keyed(): array {
		static $keyed = null;
		if ( $keyed !== null ) {
			return $keyed;
		}

		try {
			$raw = IfthenpayClient::get_available_methods();
		} catch ( \Throwable $e ) {
			$this->log_error( __METHOD__ . '(): ' . $e->getMessage() );
			$keyed = [];
			return $keyed;
		}

		$keyed = [];
		foreach ( $raw as $method ) {
			if ( empty( $method['Entity'] ) || empty( $method['IsVisible'] ) ) {
				continue;
			}
			$entity           = strtoupper( (string) $method['Entity'] );
			$keyed[ $entity ] = [
				'entity'                => $entity,
				'label'                 => (string) ( $method['Method'] ?? $entity ),
				'image_url'             => (string) ( $method['ImageUrl'] ?? '' ),
				'small_image_url'       => (string) ( $method['SmallImageUrl'] ?? '' ),
				'small_image_url_dark'  => (string) ( $method['SmallImageUrlDark'] ?? '' ),
				'position'              => (int) ( $method['Position'] ?? 0 ),
				'allow_selected_method' => (bool) ( $method['AllowSelectedMethod'] ?? true ),
			];
		}

		uasort( $keyed, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

		return $keyed;
	}

	/**
	 * Finds a single gateway row in the fresh API result by GatewayKey.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_gateway_row( string $gateway_key ): ?array {
		if ( $gateway_key === '' ) {
			return null;
		}
		foreach ( $this->fetch_gravityforms_gateways() as $row ) {
			if ( ! empty( $row['GatewayKey'] ) && (string) $row['GatewayKey'] === $gateway_key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Returns the account string for a method in a gateway row by trying every
	 * casing variant of the Entity / Method names.
	 */
	private function resolve_account_in_row( array $row, string $entity, string $method_label = '' ): string {
		$candidates = array_unique( array_filter( [
			$entity,
			strtoupper( $entity ),
			strtolower( $entity ),
			$method_label,
			strtoupper( $method_label ),
			strtolower( $method_label ),
		] ) );


		if ( strtoupper( $entity ) === 'MB' || strtoupper( $method_label ) === 'MULTIBANCO' ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ( $candidates as $key ) {
			if ( $key === '' || ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $row[ $key ] );
			if ( $value !== '' ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Builds the Gateway-Key <select> choices from a fresh API call.
	 * Label = alias (or key as fallback); value = the technical key.
	 */
	private function get_gateway_key_choices(): array {
		$choices = [ [ 'label' => __( '— Select a gateway key —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		foreach ( $this->fetch_gravityforms_gateways() as $row ) {
			$key = sanitize_text_field( (string) ( $row['GatewayKey'] ?? '' ) );
			if ( $key === '' ) {
				continue;
			}
			$alias     = sanitize_text_field( (string) ( $row['Alias'] ?? '' ) );
			$choices[] = [
				'label' => $alias !== '' ? $alias : $key,
				'value' => $key,
			];
		}

		return $choices;
	}

	/**
	 * Builds the Default-Method <select> choices for the gateway currently
	 * selected in the form-settings POST/state. Lists every visible method
	 * that is provisioned on the gateway row — `allow_selected_method` is
	 * NOT filtered here, so the admin can see all methods alongside the
	 * methods table; the client-side `syncDefaultMethodDropdown()` then
	 * disables the options whose entity isn't currently is_active=true.
	 */
	private function get_default_method_choices(): array {
		$choices = [ [ 'label' => __( '— Auto (first enabled method) —', 'ifthenpay-payments-for-gravityforms' ), 'value' => '' ] ];

		$gateway_key = (string) ( $this->get_setting( 'gateway_key' ) ?? '' );
		if ( $gateway_key === '' ) {
			return $choices;
		}

		$row = $this->find_gateway_row( $gateway_key );
		if ( $row === null ) {
			return $choices;
		}

		foreach ( $this->fetch_visible_methods_keyed() as $entity => $cat_entry ) {
			$account = $this->resolve_account_in_row( $row, $entity, (string) ( $cat_entry['label'] ?? '' ) );
			if ( $account === '' ) {
				continue;
			}
			$choices[] = [
				'label' => (string) ( $cat_entry['label'] ?? $entity ),
				'value' => $entity,
			];
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
