<?php
/**
 * Shield silentCAPTCHA integration helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cntctfrm_get_shield_silent_captcha' ) ) {
	/**
	 * Get Shield silentCAPTCHA integration instance.
	 *
	 * @return Cntctfrm_Shield_Silent_Captcha
	 */
	function cntctfrm_get_shield_silent_captcha() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new Cntctfrm_Shield_Silent_Captcha();
		}

		return $instance;
	}
}

if ( ! class_exists( 'Cntctfrm_Shield_Silent_Captcha' ) ) {
	/**
	 * Shield silentCAPTCHA integration.
	 */
	class Cntctfrm_Shield_Silent_Captcha {
		const OPTION_KEY         = 'shield_silent_captcha';
		const GLOBAL_POST_KEY    = 'cntctfrm_shield_silent_captcha_global';
		const ERROR_KEY          = 'error_shield_silent_captcha';
		const HELP_URL           = 'https://clk.shldscrty.com/silentcaptchaintegrationhelp';

		/**
		 * Cached Shield availability.
		 *
		 * @var bool|null
		 */
		private $shield_available = null;

		/**
		 * Cached threshold-zero state.
		 *
		 * @var bool|null
		 */
		private $shield_threshold_zero = null;

		/**
		 * Pending shared multi-form value.
		 *
		 * @var int|null
		 */
		private $pending_multi_global_value = null;

		public function __construct() {
			add_filter( 'cntctfrm_get_additional_options_default', array( $this, 'add_option_defaults' ) );
			add_filter( 'cntctfrm_save_additional_options', array( $this, 'capture_settings' ) );
			add_filter( 'cntctfrm_check_fields', array( $this, 'filter_submission_errors' ) );
		}

		/**
		 * Add Shield option defaults.
		 *
		 * @param array $defaults Current defaults.
		 * @return array
		 */
		public function add_option_defaults( $defaults ) {
			$defaults[ self::OPTION_KEY ] = 0;

			return $defaults;
		}

		/**
		 * Capture posted Shield values into the current options object.
		 *
		 * @param array $options Current options.
		 * @return array
		 */
		public function capture_settings( $options ) {
			$options[ self::OPTION_KEY ] = $this->get_posted_value( self::OPTION_KEY );

			if ( cntctfrm_check_cf_multi_active() ) {
				$this->pending_multi_global_value = $this->get_posted_value( self::GLOBAL_POST_KEY );
			} else {
				$this->pending_multi_global_value = null;
			}

			return $options;
		}

		/**
		 * Persist shared multi-form Shield setting after a successful save.
		 *
		 * @param mixed $contact_form_multi_active Current multi-form state.
		 * @return void
		 */
		public function commit_settings_after_success( $contact_form_multi_active ) {
			if ( ! $contact_form_multi_active || null === $this->pending_multi_global_value ) {
				return;
			}

			$options = get_option( 'cntctfrmmlt_options' );
			if ( ! is_array( $options ) ) {
				$options = cntctfrm_get_option_defaults();
			}

			$options[ self::OPTION_KEY ] = $this->pending_multi_global_value;
			update_option( 'cntctfrmmlt_options', $options );

			$this->pending_multi_global_value = null;
		}

		/**
		 * Filter form errors with Shield bot detection.
		 *
		 * @param array $error_messages Current error messages.
		 * @return array
		 */
		public function filter_submission_errors( $error_messages ) {
			global $cntctfrm_options;

			if ( ! is_array( $error_messages ) || ! is_array( $cntctfrm_options ) ) {
				return $error_messages;
			}

			if ( $this->has_prior_blocking_errors( $error_messages ) ) {
				return $error_messages;
			}

			$contact_form_multi_active = cntctfrm_check_cf_multi_active();
			if ( $contact_form_multi_active ) {
				if ( ! $this->get_multi_global_setting_value() || ! $this->get_current_setting_value( $cntctfrm_options ) ) {
					return $error_messages;
				}
			} elseif ( ! $this->get_current_setting_value( $cntctfrm_options ) ) {
				return $error_messages;
			}

			$verdict = $this->get_shield_bot_verdict();
			if ( true === $verdict ) {
				$error_messages[ self::ERROR_KEY ] = true;
			}

			return $error_messages;
		}

		/**
		 * Get normalized Shield settings UI state.
		 *
		 * @param array $options Current options.
		 * @param mixed $contact_form_multi_active Current multi-form state.
		 * @return array
		 */
		public function get_settings_ui_state( $options, $contact_form_multi_active ) {
			$shield_current_value = $this->get_current_setting_value( $options );
			$state                = array(
				'available' => false,
				'notice'    => '',
				'current'   => array(
					'name'         => self::OPTION_KEY,
					'label'        => $contact_form_multi_active ? __( 'Shield silentCAPTCHA (current form)', 'contact-form-plugin' ) : __( 'Shield silentCAPTCHA', 'contact-form-plugin' ),
					'enabled'      => $shield_current_value,
					'hidden_value' => $this->get_posted_checkbox_value( $shield_current_value ),
				),
			);

			if ( $contact_form_multi_active ) {
				$shield_global_value = $this->get_multi_global_setting_value();
				$state['global']     = array(
					'name'         => self::GLOBAL_POST_KEY,
					'label'        => __( 'Shield silentCAPTCHA (all forms)', 'contact-form-plugin' ),
					'description'  => __( 'Shared setting for Contact Form Multi.', 'contact-form-plugin' ),
					'enabled'      => $shield_global_value,
					'hidden_value' => $this->get_posted_checkbox_value( $shield_global_value ),
				);
			}

			$state['available'] = $this->is_shield_available();

			if ( ! $state['available'] ) {
				$state['notice'] = $this->get_unavailable_notice_html();
			} elseif ( $this->is_shield_threshold_zero() ) {
				$state['notice'] = $this->get_threshold_notice_html();
			}

			return $state;
		}

		/**
		 * Check whether Shield is available.
		 *
		 * @return bool
		 */
		private function is_shield_available() {
			if ( ! did_action( 'plugins_loaded' ) ) {
				return false;
			}

			if ( null !== $this->shield_available ) {
				return $this->shield_available;
			}

			$this->shield_available = false;
			foreach ( $this->get_verdict_callables() as $callable ) {
				if ( is_callable( $callable ) ) {
					$this->shield_available = true;
					break;
				}
			}

			return $this->shield_available;
		}

		/**
		 * Check whether Shield threshold resolves to zero.
		 *
		 * @return bool
		 */
		private function is_shield_threshold_zero() {
			if ( ! did_action( 'plugins_loaded' ) ) {
				return false;
			}

			if ( null !== $this->shield_threshold_zero ) {
				return $this->shield_threshold_zero;
			}

			$this->shield_threshold_zero = false;
			if ( ! $this->is_shield_available() ) {
				return $this->shield_threshold_zero;
			}

			foreach ( $this->get_threshold_callables() as $callable ) {
				if ( is_callable( $callable ) ) {
					try {
						$this->shield_threshold_zero = 0 === call_user_func( $callable );
						break;
					} catch ( \Exception $e ) {
					}
				}
			}

			return $this->shield_threshold_zero;
		}

		/**
		 * Get unavailable notice HTML.
		 *
		 * @return string
		 */
		private function get_unavailable_notice_html() {
			return $this->get_notice_with_help_link(
				__(
					'Shield Security is not installed or not active, so this setting has no effect right now. Install and activate Shield Security to enable silentCAPTCHA bot checks.',
					'contact-form-plugin'
				)
			);
		}

		/**
		 * Get threshold warning HTML.
		 *
		 * @return string
		 */
		private function get_threshold_notice_html() {
			return $this->get_notice_with_help_link(
				__(
					'Shield is active and running, but your Shield silentCAPTCHA bot threshold is set to zero.',
					'contact-form-plugin'
				)
			);
		}

		/**
		 * Get shared Shield setting value for multi-form mode.
		 *
		 * @return bool
		 */
		private function get_multi_global_setting_value() {
			$options = get_option( 'cntctfrmmlt_options' );

			return $this->is_option_enabled( is_array( $options ) && isset( $options[ self::OPTION_KEY ] ) ? $options[ self::OPTION_KEY ] : 0 );
		}

		/**
		 * Get current options Shield setting value.
		 *
		 * @param array $options Current options.
		 * @return bool
		 */
		private function get_current_setting_value( $options ) {
			return $this->is_option_enabled( isset( $options[ self::OPTION_KEY ] ) ? $options[ self::OPTION_KEY ] : 0 );
		}

		/**
		 * Get Shield bot verdict.
		 *
		 * @return bool|null
		 */
		private function get_shield_bot_verdict() {
			foreach ( $this->get_verdict_callables() as $callable ) {
				if ( ! is_callable( $callable ) ) {
					continue;
				}

				try {
					$verdict = call_user_func( $callable );
				} catch ( \Exception $e ) {
					continue;
				}

				$normalized = $this->normalize_verdict( $verdict );
				if ( null !== $normalized ) {
					return $normalized;
				}
			}

			return null;
		}

		/**
		 * Check whether earlier validation already blocked the form.
		 *
		 * @param array $error_messages Current errors.
		 * @return bool
		 */
		private function has_prior_blocking_errors( $error_messages ) {
			foreach ( array_keys( $error_messages ) as $key ) {
				if ( 'error_form' !== $key ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Normalize Shield verdict into a strict tri-state.
		 *
		 * @param mixed $verdict Raw Shield verdict.
		 * @return bool|null
		 */
		private function normalize_verdict( $verdict ) {
			if ( true === $verdict ) {
				return true;
			}

			if ( false === $verdict ) {
				return false;
			}

			return null;
		}

		/**
		 * Get a posted Shield toggle value.
		 *
		 * @param string $key Request key.
		 * @return int
		 */
		private function get_posted_value( $key ) {
			return isset( $_POST[ $key ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ? 1 : 0;
		}

		/**
		 * Check whether a saved option value is enabled.
		 *
		 * @param mixed $value Saved value.
		 * @return bool
		 */
		private function is_option_enabled( $value ) {
			return 1 === absint( $value );
		}

		/**
		 * Get the posted checkbox value for a normalized bool.
		 *
		 * @param bool $value Checkbox state.
		 * @return int
		 */
		private function get_posted_checkbox_value( $value ) {
			return $value ? 1 : 0;
		}

		/**
		 * Get Shield verdict callable list.
		 *
		 * @return array
		 */
		private function get_verdict_callables() {
			return array(
				'\\FernleafSystems\\Wordpress\\Plugin\\Shield\\Functions\\test_ip_is_bot',
				'shield_test_ip_is_bot',
			);
		}

		/**
		 * Get Shield threshold callable list.
		 *
		 * @return array
		 */
		private function get_threshold_callables() {
			return array(
				'\\FernleafSystems\\Wordpress\\Plugin\\Shield\\Functions\\get_silentcaptcha_bot_threshold',
				'shield_get_silentcaptcha_bot_threshold',
			);
		}

		/**
		 * Get notice HTML with a Learn More link.
		 *
		 * @param string $notice_text Notice text.
		 * @return string
		 */
		private function get_notice_with_help_link( $notice_text ) {
			return sprintf(
				/* translators: 1: notice text, 2: Shield help URL. */
				__( '%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">Learn More</a>.', 'contact-form-plugin' ),
				esc_html( $notice_text ),
				esc_url( self::HELP_URL )
			);
		}
	}
}
