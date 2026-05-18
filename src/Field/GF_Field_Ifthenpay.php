<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Field;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\GravityForms\Addon;

class GF_Field_Ifthenpay extends \GF_Field {

	public $type = 'iftp_pbl';

	public function get_form_editor_field_type(): string {
		return 'iftp_pbl';
	}

	public function get_form_editor_field_title(): string {
		return esc_attr__( 'ifthenpay', 'ifthenpay-payments-for-gravityforms' );
	}

	public function get_form_editor_field_icon(): string {
		return IFTP_GF_URL . 'assets/images/ifthenpay-icon.svg';
	}

	public function get_form_editor_button(): array {
		return [
			'group' => 'pricing_fields',
			'text'  => $this->get_form_editor_field_title(),
		];
	}

	public function get_form_editor_field_settings(): array {
		return [
			'label_setting',
			'description_setting',
			'css_class_setting',
		];
	}

	public function is_conditional_logic_supported(): bool {
		return false;
	}

	// -------------------------------------------------------------------------
	// Field rendering
	// -------------------------------------------------------------------------

	public function get_field_input( $form, $value = '', $entry = null ): string {
		if ( $this->is_form_editor() ) {
			return $this->get_editor_preview();
		}

		if ( $this->is_entry_detail() ) {
			return $this->get_entry_detail_display( (string) $value );
		}

		$form_id = (int) rgar( $form, 'id' );
		$feed    = $this->get_form_feed( $form_id );

		if ( ! $feed ) {
			return '<p class="gfield_description">'
				. esc_html__( 'No ifthenpay feed configured for this form. Go to Form Settings → ifthenpay to add one.', 'ifthenpay-payments-for-gravityforms' )
				. '</p>';
		}

		$gateway_key    = (string) rgar( $feed['meta'], 'gateway_key' );
		$default_method = strtoupper( (string) rgar( $feed['meta'], 'default_method' ) );

		$catalog        = Addon::get_gateway_catalog();
		$global_config  = Addon::get_methods_config();
		$gateway_config = $global_config[ $gateway_key ] ?? [];
		$available      = (array) ( $catalog[ $gateway_key ]['methods'] ?? [] );

		$enabled_methods = [];
		foreach ( $available as $entity => $method ) {
			$entity_key = strtoupper( (string) $entity );
			if ( ! empty( $gateway_config[ $entity_key ]['enabled'] ) ) {
				$enabled_methods[ $entity_key ] = $method;
			}
		}

		if ( $gateway_key === '' || empty( $enabled_methods ) ) {
			return '<p class="gfield_description">'
				. esc_html__( 'Payment is not available at this time. Please contact the site administrator.', 'ifthenpay-payments-for-gravityforms' )
				. '</p>';
		}

		$field_id              = (int) $this->id;
		$nonce                 = wp_create_nonce( 'iftp_gf_payment_' . $form_id );
		$amount_field_selector = $this->resolve_amount_field_selector( $form );

		ob_start();
		?>
		<div class="ginput_container iftp-pbl-gf-field iftp-pbl-is-active"
			 id="iftp_pbl_field_<?php echo esc_attr( $form_id . '_' . $field_id ); ?>"
			 data-form-id="<?php echo esc_attr( $form_id ); ?>"
			 data-field-id="<?php echo esc_attr( $field_id ); ?>"
			 data-iftp-gateway-key="<?php echo esc_attr( $gateway_key ); ?>"
			 data-iftp-currency="EUR"
			 data-iftp-amount-selector="<?php echo esc_attr( $amount_field_selector ); ?>">

			<!-- Hidden inputs submitted with the GF form -->
			<input type="hidden" class="iftp-pbl-paid-input"       name="iftp_pbl_paid_<?php echo esc_attr( $form_id ); ?>"       value="0">
			<input type="hidden" class="iftp-pbl-payment-id-input"  name="iftp_pbl_payment_id_<?php echo esc_attr( $form_id ); ?>"  value="">
			<input type="hidden" class="iftp-pbl-session-input"     name="iftp_pbl_session_<?php echo esc_attr( $form_id ); ?>"     value="">
			<input type="hidden" class="iftp-pbl-nonce-input"       value="<?php echo esc_attr( $nonce ); ?>">

			<div class="iftp-pbl-public-box">

				<!-- Header -->
				<div class="iftp-pbl-header">
					<span class="iftp-pbl-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="22" height="22">
							<rect x="2" y="5" width="20" height="14" rx="2" fill="#204ce5"/>
							<rect x="2" y="9" width="20" height="2" fill="#fff"/>
							<rect x="5" y="14" width="6" height="2" rx="1" fill="#fff"/>
						</svg>
					</span>
					<div class="iftp-pbl-header-text">
						<div class="iftp-pbl-header-title">ifthenpay</div>
						<div class="iftp-pbl-header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					</div>
				</div>

				<!-- Payment methods -->
				<div class="iftp-pbl-methods-section">
					<div class="iftp-pbl-methods-title"><?php esc_html_e( 'Payment methods:', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					<div class="iftp-pbl-preview-methods">
						<?php foreach ( $enabled_methods as $entity_key => $method ) :
							$is_default = ( $entity_key === $default_method );
							$logo_url   = 'https://gateway.ifthenpay.com/plugins/logotipos/small/' . strtolower( $entity_key ) . '.png';
							$label      = esc_attr( (string) ( $method['method'] ?? $entity_key ) );
						?>
						<span class="iftp-pbl-method-item<?php echo $is_default ? ' is-default' : ''; ?>" data-entity="<?php echo esc_attr( $entity_key ); ?>">
							<span class="iftp-pbl-logo">
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo $label; ?>" title="<?php echo $label; ?>" loading="lazy">
							</span>
						</span>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Info -->
				<div class="iftp-pbl-info">
					<strong><?php esc_html_e( 'How it works:', 'ifthenpay-payments-for-gravityforms' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'Click "Pay Now" to open the secure payment window.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
						<li><?php esc_html_e( 'Closing the payment window before completing will cancel the request.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
						<li><?php esc_html_e( 'ifthenpay only accepts EUR as the payment currency.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
					</ul>
				</div>

			</div>

			<!-- Pay button -->
			<div class="iftp-pbl-pay-row">
				<button type="button"
						class="iftp-pbl-pay-now-button gform-theme-button"
						title="<?php esc_attr_e( 'Pay Now', 'ifthenpay-payments-for-gravityforms' ); ?>">
					<span class="iftp-pbl-spinner" aria-hidden="true"></span>
					<span class="iftp-pbl-pay-now-label"><?php esc_html_e( 'Pay Now', 'ifthenpay-payments-for-gravityforms' ); ?></span>
				</button>
			</div>

			<!-- Status / warning slots -->
			<div class="iftp-pbl-status"          role="status" aria-live="polite"></div>
			<div class="iftp-pbl-runtime-warning"  role="alert"></div>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Validation — block form submit if payment not completed
	// -------------------------------------------------------------------------

	public function validate( $value, $form ): void {
		$form_id  = (int) rgar( $form, 'id' );
		$paid_key = 'iftp_pbl_paid_' . $form_id;

		if ( empty( $_POST[ $paid_key ] ) || (string) $_POST[ $paid_key ] !== '1' ) {
			$this->failed_validation  = true;
			$this->validation_message = esc_html__( 'Please complete the payment before submitting the form.', 'ifthenpay-payments-for-gravityforms' );
		}
	}

	// -------------------------------------------------------------------------
	// Entry value — save the payment / transaction ID
	// -------------------------------------------------------------------------

	public function get_value_save_entry( $value, $form, $input_name, $lead_id, $lead ) {
		$form_id = (int) rgar( $form, 'id' );
		return sanitize_text_field( (string) ( $_POST[ 'iftp_pbl_payment_id_' . $form_id ] ?? '' ) );
	}

	public function get_value_entry_detail( $value, $entry = [], $use_text = false, $format = 'html', $media = 'screen' ) {
		return esc_html( (string) $value );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function get_form_feed( int $form_id ): ?array {
		$feeds = \GFAPI::get_feeds( null, $form_id, 'iftp_gf' );
		if ( ! is_array( $feeds ) || empty( $feeds ) ) {
			return null;
		}
		foreach ( $feeds as $feed ) {
			if ( ! empty( $feed['is_active'] ) ) {
				return $feed;
			}
		}
		return null;
	}

	private function resolve_amount_field_selector( array $form ): string {
		foreach ( rgar( $form, 'fields', [] ) as $field ) {
			if ( $field->type === 'total' ) {
				return '#input_' . $form['id'] . '_' . $field->id;
			}
		}
		foreach ( rgar( $form, 'fields', [] ) as $field ) {
			if ( $field->type === 'product' ) {
				return '#input_' . $form['id'] . '_' . $field->id . '_1';
			}
		}
		return '';
	}

	private function get_editor_preview(): string {
		ob_start();
		?>
		<div class="ginput_container iftp-pbl-gf-field">
			<div class="iftp-pbl-public-box">
				<div class="iftp-pbl-header">
					<span class="iftp-pbl-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="22" height="22">
							<rect x="2" y="5" width="20" height="14" rx="2" fill="#204ce5"/>
							<rect x="2" y="9" width="20" height="2" fill="#fff"/>
							<rect x="5" y="14" width="6" height="2" rx="1" fill="#fff"/>
						</svg>
					</span>
					<div class="iftp-pbl-header-text">
						<div class="iftp-pbl-header-title">ifthenpay</div>
						<div class="iftp-pbl-header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					</div>
				</div>
				<p style="color:#585e6a;font-size:13px;margin:8px 0 0;">
					<?php esc_html_e( 'Payment methods are loaded from the form\'s ifthenpay feed at runtime.', 'ifthenpay-payments-for-gravityforms' ); ?>
				</p>
			</div>
			<div class="iftp-pbl-pay-row">
				<button type="button" class="iftp-pbl-pay-now-button gform-theme-button" disabled>
					<span class="iftp-pbl-pay-now-label"><?php esc_html_e( 'Pay Now', 'ifthenpay-payments-for-gravityforms' ); ?></span>
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function get_entry_detail_display( string $value ): string {
		if ( $value === '' ) {
			return '<span style="color:#8c8f94;">' . esc_html__( 'No payment data.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';
		}
		return '<code>' . esc_html( $value ) . '</code>';
	}
}
