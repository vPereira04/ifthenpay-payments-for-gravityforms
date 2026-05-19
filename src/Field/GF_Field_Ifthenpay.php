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
		return IFTP_GF_URL . 'assets/images/ifthenpay-small-icon.svg';
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
	// Field rendering — passive preview only (no payment UI).
	// After form submit, GFPaymentAddOn::redirect_url() sends the user to the
	// ifthenpay payment page. Same flow as MemberPress / GF Stripe checkout.
	// -------------------------------------------------------------------------

	public function get_field_input( $form, $value = '', $entry = null ): string {
		if ( $this->is_form_editor() ) {
			return $this->get_editor_preview();
		}

		if ( $this->is_entry_detail() ) {
			return $this->get_entry_detail_display( (string) $value );
		}

		// Belt-and-suspenders CSS enqueue: the GF addon framework's field_types
		// rule should cover this, but some themes / page-builders render forms
		// outside the standard enqueue chain. Registering at render time is safe
		// (wp_enqueue_style is idempotent) and guarantees the styles load.
		if ( ! wp_style_is( 'ifthenpay-gf-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'ifthenpay-gf-frontend',
				IFTP_GF_URL . 'assets/css/frontend.css',
				[],
				IFTP_GF_VERSION
			);
		}

		$form_id   = (int) rgar( $form, 'id' );
		$form_info = Addon::get_form_payment_info( $form_id );

		$enabled_methods = (array) ( $form_info['enabled_methods'] ?? [] );
		$default_method  = strtoupper( (string) ( $form_info['default_method'] ?? '' ) );

		if ( empty( $enabled_methods ) ) {
			$feeds = \GFAPI::get_feeds( null, $form_id, 'iftp_gf' );
			$has_active_feed = false;
			if ( is_array( $feeds ) ) {
				foreach ( $feeds as $feed ) {
					if ( ! empty( $feed['is_active'] ) ) {
						$has_active_feed = true;
						break;
					}
				}
			}
			if ( ! $has_active_feed ) {
				return '<p class="gfield_description">'
					. esc_html__( 'No ifthenpay feed configured for this form. Go to Form Settings → ifthenpay to add one.', 'ifthenpay-payments-for-gravityforms' )
					. '</p>';
			}
			return '<p class="gfield_description">'
				. esc_html__( 'Payment is not available at this time. Please contact the site administrator.', 'ifthenpay-payments-for-gravityforms' )
				. '</p>';
		}

		ob_start();
		?>
		<div class="ginput_container iftp-pbl-gf-field iftp-pbl-is-preview gform-theme__no-reset--children"
			 data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">

			<div class="iftp-pbl-public-box">

				<!-- Header -->
				<div class="iftp-pbl-header">
					<span class="iftp-pbl-header-icon" aria-hidden="true">
						<img src="<?php echo esc_url( IFTP_GF_URL . 'assets/images/ifthenpay-field-icon.svg' ); ?>" alt="Ifthenpay" width="50" height="50">
					</span>
					<div class="iftp-pbl-header-text">
						<div class="iftp-pbl-header-title">ifthenpay</div>
						<div class="iftp-pbl-header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					</div>
				</div>

				<!-- Payment methods preview -->
				<div class="iftp-pbl-methods-section">
					<div class="iftp-pbl-methods-title"><?php esc_html_e( 'Payment methods:', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					<div class="iftp-pbl-preview-methods">
						<?php foreach ( $enabled_methods as $entity_key => $method ) :
							$is_default   = ( $entity_key === $default_method );
							$logo_url     = (string) ( $method['image_url'] ?? '' );
							if ( $logo_url === '' ) {
								$logo_url = 'https://gateway.ifthenpay.com/plugins/logotipos/small/' . strtolower( $entity_key ) . '.png';
							}
							$method_label = (string) ( $method['label'] ?? $entity_key );
						?>
						<span class="iftp-pbl-method-item<?php echo $is_default ? ' is-default' : ''; ?>" data-entity="<?php echo esc_attr( $entity_key ); ?>">
							<span class="iftp-pbl-logo">
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $method_label ); ?>" title="<?php echo esc_attr( $method_label ); ?>" loading="lazy">
							</span>
						</span>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Info -->
				<div class="iftp-pbl-info">
					<strong><?php esc_html_e( 'How it works:', 'ifthenpay-payments-for-gravityforms' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'After submitting the form, you will be redirected to the secure ifthenpay payment page.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
						<li><?php esc_html_e( 'Pick your preferred payment method on that page and complete the payment.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
						<li><?php esc_html_e( 'ifthenpay only accepts EUR as the payment currency.', 'ifthenpay-payments-for-gravityforms' ); ?></li>
					</ul>
				</div>

			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// No client-side validation — GFPaymentAddOn handles payment status via
	// gateway callback after the redirect.
	// -------------------------------------------------------------------------

	public function validate( $value, $form ): void {
		// Intentionally empty; payment is processed off-site after submission.
	}

	public function get_value_save_entry( $value, $form, $input_name, $lead_id, $lead ) {
		// Transaction ID is written into entry meta by redirect_url() / gateway return handler.
		return '';
	}

	public function get_value_entry_detail( $value, $entry = [], $use_text = false, $format = 'html', $media = 'screen' ) {
		return esc_html( (string) $value );
	}

	// -------------------------------------------------------------------------
	// Editor preview
	// -------------------------------------------------------------------------

	private function get_editor_preview(): string {
		ob_start();
		?>
		<div class="ginput_container iftp-pbl-gf-field gform-theme__no-reset--children">
			<div class="iftp-pbl-public-box">
				<div class="iftp-pbl-header">
					<span class="iftp-pbl-header-icon" aria-hidden="true">
						<img src="<?php echo esc_url( IFTP_GF_URL . 'assets/images/ifthenpay-field-icon.svg' ); ?>" alt="Ifthenpay" width="22" height="22">
					</span>
					<div class="iftp-pbl-header-text">
						<div class="iftp-pbl-header-title">ifthenpay</div>
						<div class="iftp-pbl-header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					</div>
				</div>
				<p style="color:#585e6a;font-size:13px;margin:8px 0 0;">
					<?php esc_html_e( 'Payment methods are loaded from the form\'s ifthenpay feed at runtime. After form submission the customer is redirected to the ifthenpay payment page.', 'ifthenpay-payments-for-gravityforms' ); ?>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_entry_detail_display( string $value ): string {
		if ( $value === '' ) {
			return '<span style="color:#8c8f94;">' . esc_html__( 'No payment data.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';
		}
		return '<code>' . esc_html( $value ) . '</code>';
	}
}
