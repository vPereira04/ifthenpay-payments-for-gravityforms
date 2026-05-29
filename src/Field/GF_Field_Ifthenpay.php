<?php

declare(strict_types=1);

namespace Ifthenpay\GravityForms\Field;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\GravityForms\Addon;
use Ifthenpay\GravityForms\Api\IfthenpayPayload;
use Ifthenpay\GravityForms\Repository\FormPaymentInfo;

class GF_Field_Ifthenpay extends \GF_Field {

	public $type = 'iftp_pbl';

	public function get_form_editor_field_type(): string {
		return 'iftp_pbl';
	}

	public function get_form_editor_field_title(): string {
		return esc_attr__( 'Ifthenpay', 'ifthenpay-payments-for-gravityforms' );
	}

	public function get_form_editor_field_icon(): string {
		return 'gform-icon--ifthenpay';
	}

	public function get_form_editor_button(): array {
		return array(
			'group' => 'pricing_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	public function get_form_editor_field_settings(): array {
		return array(
			'label_setting',
			'description_setting',
			'css_class_setting',
			'conditional_logic_field_setting',
		);
	}

	public function is_conditional_logic_supported(): bool {
		return false;
	}

	public function get_form_editor_inline_script_on_page_render(): string {
		return sprintf(
			"function SetDefaultValues_iftp_pbl(field) { field.label = '%s'; return field; }",
			esc_js( __( 'Pay By Link', 'ifthenpay-payments-for-gravityforms' ) )
		);
	}



	public function get_field_input( $form, $value = '', $entry = null ): string {
		if ( $this->is_form_editor() ) {
			return $this->get_editor_preview();
		}

		if ( $this->is_entry_detail() ) {
			$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			return $this->get_entry_detail_display( $entry_id );
		}


		if ( ! wp_style_is( 'ifthenpay-gf-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'ifthenpay-gf-frontend',
				\IFTP_GF_URL . 'assets/css/frontend.css',
				array(),
				\IFTP_GF_VERSION
			);
		}

		$form_id   = (int) rgar( $form, 'id' );
		$form_info = FormPaymentInfo::get( $form_id );

		$default_method = strtoupper( (string) ( $form_info['default_method'] ?? '' ) );
		$active_methods = array_values(
			array_filter(
				(array) ( $form_info['pay_methods'] ?? array() ),
				static fn( array $method ): bool => ! empty( $method['is_active'] )
			)
		);

		if ( empty( $active_methods ) ) {
			if ( Addon::get_backoffice_key() === '' ) {
				return '<p class="gfield_description">'
					. esc_html__( 'Payment is not available: ifthenpay is not connected. Please contact the site administrator.', 'ifthenpay-payments-for-gravityforms' )
					. '</p>';
			}

			$feeds           = \GFAPI::get_feeds( null, $form_id, 'iftp_gf' );
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
				. esc_html__( 'No payment methods are enabled for this form. Go to Form Settings → ifthenpay to enable at least one payment method.', 'ifthenpay-payments-for-gravityforms' )
				. '</p>';
		}

		ob_start();
		?>
		<div class="ginput_container iftp-gf-field iftp-gf-field--preview gform-theme__no-reset--children"
			data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">

			<div class="iftp-gf-box">

				<!-- Header -->
				<div class="iftp-gf-box__header">
					<span class="iftp-gf-box__header-icon" aria-hidden="true">
						<i class="iftp-gf-field-icon"></i>
					</span>
					<div class="iftp-gf-box__header-text">
						<div class="iftp-gf-box__header-title">ifthenpay</div>
						<div class="iftp-gf-box__header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					</div>
				</div>

				<!-- Payment methods preview -->
				<div class="iftp-gf-box__methods">
					<div class="iftp-gf-box__methods-title"><?php esc_html_e( 'Payment methods:', 'ifthenpay-payments-for-gravityforms' ); ?></div>
					<div class="iftp-gf-box__methods-list">
						<?php
						foreach ( $active_methods as $method ) :
							$entity_key = strtoupper( (string) ( $method['entity'] ?? '' ) );
							$is_default = ( $entity_key === $default_method );
							$logo_url   = (string) ( $method['img_url'] ?? '' );
							if ( $logo_url === '' ) {
								$logo_url = IfthenpayPayload::fallback_logo_url( $entity_key );
							}
							$logo_url_dark = (string) ( $method['img_url_dark'] ?? '' );
							$method_label  = $entity_key;
							?>
						<span class="iftp-gf-box__method<?php echo $is_default ? ' iftp-gf-box__method--default' : ''; ?>" data-entity="<?php echo esc_attr( $entity_key ); ?>">
							<span class="iftp-gf-box__method-logo">
								<img src="<?php echo esc_url( $logo_url ); ?>"
									<?php
									if ( $logo_url_dark !== '' ) :
										?>
										data-src-dark="<?php echo esc_url( $logo_url_dark ); ?>"<?php endif; ?>
									alt="<?php echo esc_attr( $method_label ); ?>" title="<?php echo esc_attr( $method_label ); ?>" loading="lazy">
							</span>
						</span>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Info -->
				<div class="iftp-gf-box__info">
					<strong><?php esc_html_e( 'How it works:', 'ifthenpay-payments-for-gravityforms' ); ?></strong>
					<ul class="iftp-gf-box__info-list">
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



	public function validate( $value, $form ): void {

	}

	public function get_value_save_entry( $value, $form, $input_name, $lead_id, $lead ) {

		return '';
	}

	public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {
		$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		if ( $entry_id <= 0 ) {
			return '';
		}

		$status = (string) gform_get_meta( $entry_id, 'iftp_gf_payment_status' );

		switch ( $status ) {
			case 'paid':
				return esc_html__( 'User Paid with Success!', 'ifthenpay-payments-for-gravityforms' );
			case 'cancelled':
				return esc_html__( 'User Cancelled the Payment', 'ifthenpay-payments-for-gravityforms' );
			case 'failed':
				$error = (string) gform_get_meta( $entry_id, 'iftp_gf_error_message' );
				return $error !== '' ? esc_html( $error ) : esc_html__( 'User Payment Failed', 'ifthenpay-payments-for-gravityforms' );
			default:
				return '';
		}
	}

	public function get_value_entry_detail( $value, $entry = array(), $use_text = false, $format = 'html', $media = 'screen' ) {
		return esc_html( (string) $value );
	}



	private function get_editor_preview(): string {
		ob_start();
		?>
		<div class="ginput_container iftp-gf-field gform-theme__no-reset--children">
			<div class="iftp-gf-box">
				<div class="iftp-gf-box__header">
					<span class="iftp-gf-box__header-icon" aria-hidden="true">
						<i class="iftp-gf-field-icon"></i>
					</span>
					<div class="iftp-gf-box__header-text">
						<div class="iftp-gf-box__header-title">ifthenpay</div>
						<div class="iftp-gf-box__header-subtitle"><?php esc_html_e( 'Payment Gateway', 'ifthenpay-payments-for-gravityforms' ); ?></div>
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

	private function get_entry_detail_display( int $entry_id ): string {
		if ( $entry_id <= 0 ) {
			return '<span style="color:#8c8f94;">' . esc_html__( 'No payment data.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';
		}

		$status = (string) gform_get_meta( $entry_id, 'iftp_gf_payment_status' );

		switch ( $status ) {
			case 'paid':
				return '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'User paid successfully.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';

			case 'pending':
				return '<span style="color:#dba617;">&#8987; ' . esc_html__( 'Payment pending.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';

			case 'cancelled':
				return '<span style="color:#d63638;">&#10007; ' . esc_html__( 'User cancelled transaction.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';

			case 'failed':
				$error = (string) gform_get_meta( $entry_id, 'iftp_gf_error_message' );
				if ( $error !== '' ) {
					return '<span style="color:#d63638;">&#10007; ' . esc_html__( 'Payment failed:', 'ifthenpay-payments-for-gravityforms' ) . ' ' . esc_html( $error ) . '</span>';
				}
				return '<span style="color:#d63638;">&#10007; ' . esc_html__( 'Payment failed.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';

			default:
				return '<span style="color:#8c8f94;">' . esc_html__( 'No payment data.', 'ifthenpay-payments-for-gravityforms' ) . '</span>';
		}
	}
}
