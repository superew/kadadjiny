<?php

namespace Wpo\Services;

use Wpo\Core\Extensions_Helpers;
use Wpo\Core\Url_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Options_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Notifications_Service' ) ) {

	class Notifications_Service {


		/**
		 * Shows admin notices when the plugin is not configured correctly
		 *
		 * @since 2.3
		 *
		 * @return void
		 */
		public static function show_admin_notices() {

			if ( ! is_admin() && ! is_network_admin() ) {
				return;
			}

			if ( is_super_admin() && ( ! is_multisite() || Options_Service::mu_use_subsite_options() || ( ! Options_Service::mu_use_subsite_options() && is_network_admin() ) ) ) {

				if ( ! empty( $_REQUEST['send-to-azure'] ) ) { // phpcs:ignore
					$users_sent  = (int) $_REQUEST['send-to-azure']; // phpcs:ignore
					$target_ciam = Options_Service::get_global_boolean_var( 'use_b2c' ) ? 'Azure AD B2C' : ( Options_Service::get_global_boolean_var( 'use_ciam' ) ? 'Entra External ID' : '' );

					$notification = sprintf(
						/* translators: 1: Number of users 2: Target tenant type e.g. AAD B2C or Entra Ext. ID */
						'<div id="message" class="updated notice is-dismissable"><p>' . __( 'Created / updated %1$d users in %2$s', 'wpo365-login' ) . '</p></div>',
						$users_sent,
						$target_ciam
					);

					echo wp_kses( $notification, WordPress_Helpers::get_allowed_html() );
				}

				if ( ! empty( $_REQUEST['re-activate-users'] ) ) { // phpcs:ignore
					$users_reactivated = (int) $_REQUEST['re-activate-users']; // phpcs:ignore

					$notification = sprintf(
						/* translators: Number of users */
						'<div id="message" class="updated notice is-dismissable"><p>' . __( 'Reactivated %d users', 'wpo365-login' ) . '</p></div>',
						$users_reactivated
					);

					echo wp_kses( $notification, WordPress_Helpers::get_allowed_html() );
				}

				if ( Options_Service::get_global_boolean_var( 'hide_error_notice' ) === false ) {
					$cached_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );

					if ( is_array( $cached_errors ) && ! ( isset( $_GET['page'] ) && WordPress_Helpers::trim( $_GET['page'] ) === 'wpo365-wizard' ) ) { // phpcs:ignore

						$title          = __( 'WPO365 health status', 'wpo365-login' );
						$dismiss_button = sprintf(
							'</p><p><a class="button button-primary" href="#" onclick="javascript:window.location.href = window.location.href.replace(\'page=wpo365-wizard\', \'page=wpo365-wizard&wpo365_errors_dismissed=true\')">%s</a>',
							__( 'Dismiss', 'wpo365-login' )
						);
						$footer         = '- Marco van Wieren | Downloads by van Wieren | <a href="https://www.wpo365.com/">https://www.wpo365.com/</a>';
						$notice_type    = 'error';
						$hide_image     = true;
						$message        = '';

						$message = sprintf(
							/* translators:  1: Name of the plugin in question - do not translate 2: Clickable link - do not translate */
							__( 'The %1$s plugin detected errors that you should address. Please %2$s to review and address these errors.', 'wpo365-login' ),
							'<strong>WPO365 | LOGIN</strong>',
							sprintf(
								'<a href="admin.php?page=wpo365-wizard">%s</a>',
								/* translators: Link caption e.g. "click here" */
								__( 'click here', 'wpo365-login' )
							)
						);

						ob_start();
						include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/admin-notifications.php';
						$content = ob_get_clean();
						echo '' . wp_kses( $content, WordPress_Helpers::get_allowed_html() );
					}
				}

				if ( isset( $_GET['page'] ) && WordPress_Helpers::trim( sanitize_text_field( $_GET['page'] ) ) === 'wpo365-wizard' ) { // phpcs:ignore

					if ( $GLOBALS['WPO_CONFIG']['plugin'] === 'wpo365-login/wpo365-login.php' ) {

						// Getting started
						if ( empty( Options_Service::is_wpo365_configured() ) ) { // Getting started

							if ( empty( Options_Service::get_global_boolean_var( 'no_sso', false ) ) ) {
								$title   = __( 'Getting started', 'wpo365-login' );
								$message = sprintf(
									/* translators: 1: Clickable link - do not translate 2: WPO365.com website name - do not translate 3: List with options - Do not translate */
									__( 'Check out our %1$s documentation to start integrating your WordPress website with %2$s. For example:%3$s', 'wpo365-login' ),
									sprintf(
										'<a href="https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow" target="_blank">%s</a>',
										__( 'Getting started' ),
										'wpo365-login'
									),
									'<strong>Microsoft 365 / Azure AD</strong>',
									sprintf(
										'<ul style="list-style: initial; padding-left: 20px;"><li><a href="https://docs.wpo365.com/article/154-aad-single-sign-for-wordpress-using-auth-code-flow" target="_blank">%s</a></li><li><a href="https://docs.wpo365.com/article/100-single-sign-on-with-saml-2-0-for-wordpress" target="_blank">%s</a></li><li><a href="https://docs.wpo365.com/article/108-sending-wordpress-emails-using-microsoft-graph" target="_blank">%s</a></li></ul>',
										__( 'OpenID based Single Sign-on', 'wpo365-login' ),
										__( 'SAML 2.0 based Single Sign-on', 'wpo365-login' ),
										__( 'Sending WordPress mail using Microsoft Graph', 'wpo365-login' )
									)
								);
								$footer      = '- Marco van Wieren | Downloads by van Wieren | <a href="https://www.wpo365.com/">https://www.wpo365.com/</a>';
								$notice_type = 'info';

								ob_start();
								include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/admin-notifications.php';
								$content = ob_get_clean();
								echo '' . wp_kses( $content, WordPress_Helpers::get_allowed_html() );
							}
						} elseif ( empty( Options_Service::get_global_boolean_var( 'review_stop', false ) ) && empty( Wpmu_Helpers::mu_get_transient( 'wpo365_review_dismissed' ) ) && empty( Extensions_Helpers::get_active_extensions() ) ) {
							$title   = __( 'Sharing is caring', 'wpo365-login' );
							$buttons = sprintf(
								'<a class="button button-primary" href="https://wordpress.org/support/view/plugin-reviews/wpo365-login?filter=5#postform" target="_blank">%s</a> <a class="button" href="./?wpo365_review_dismissed">%s</a> <a class="button" href="./?wpo365_review_stop">%s</a></p>',
								__( 'Yes, here we go!', 'wpo365-login' ),
								__( 'Remind me later', 'wpo365-login' ),
								__( 'No thanks', 'wpo365-login' )
							);
							$message = sprintf(
								/* translators: 1: Plugin name - do not translate 2: Placeholder - Do not translate 3: Buttons - Do not translate */
								__( 'Many thanks for using the %1$s plugin! Could you please spare a minute and give it a review over at WordPress.org?%2$s%3$s', 'wpo365-login' ),
								'<strong>WPO365 | LOGIN</strong>',
								'</p><p>',
								$buttons
							);
							$footer      = '- Marco van Wieren | Downloads by van Wieren | <a href="https://www.wpo365.com/">https://www.wpo365.com/</a>';
							$notice_type = 'info';

							ob_start();
							include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/admin-notifications.php';
							$content = ob_get_clean();
							echo '' . wp_kses( $content, WordPress_Helpers::get_allowed_html() );
						}
					}

					if ( $GLOBALS['WPO_CONFIG']['plugin'] === 'wpo365-msgraphmailer/wpo365-msgraphmailer.php' ) {

						if ( empty( Options_Service::get_global_boolean_var( 'review_stop', false ) ) && empty( Wpmu_Helpers::mu_get_transient( 'wpo365_review_dismissed' ) ) && empty( Extensions_Helpers::get_active_extensions() ) ) {
							$title   = __( 'Sharing is caring', 'wpo365-login' );
							$buttons = sprintf(
								'<a class="button button-primary" href="https://wordpress.org/support/plugin/wpo365-msgraphmailer/reviews/?filter=5#postform" target="_blank">%s</a> <a class="button" href="./?wpo365_review_dismissed">%s</a> <a class="button" href="./?wpo365_review_stop">%s</a></p>',
								__( 'Yes, here we go!', 'wpo365-login' ),
								__( 'Remind me later', 'wpo365-login' ),
								__( 'No thanks', 'wpo365-login' )
							);
							$message = sprintf(
								/* translators: 1: Plugin name - Do not translate 2: Placeholder - Do not translate 3: Buttons - Do not translate */
								__( 'Many thanks for using the %1$s plugin! Could you please spare a minute and give it a review over at WordPress.org?%2$s%3$s', 'wpo365-login' ),
								'<strong>WPO365 | MICROSOFT GRAPH MAILER</strong>',
								'</p><p>',
								$buttons
							);
							$footer      = '- Marco van Wieren | Downloads by van Wieren | <a href="https://www.wpo365.com/">https://www.wpo365.com/</a>';
							$notice_type = 'info';

							ob_start();
							include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/admin-notifications.php';
							$content = ob_get_clean();
							echo '' . wp_kses( $content, WordPress_Helpers::get_allowed_html() );
						}
					}
				}
			}
		}

		/**
		 * Helper to configure a transient to surpress admoin notices when the user clicked dismiss.
		 *
		 * @since 7.18
		 *
		 * @return void
		 */
		public static function dismiss_admin_notices() {

			if ( isset( $_GET['wpo365_errors_dismissed'] ) ) { // phpcs:ignore
				Wpmu_Helpers::mu_delete_transient( 'wpo365_errors' );
				Url_Helpers::force_redirect( remove_query_arg( 'wpo365_errors_dismissed' ) );
			}

			if ( isset( $_GET['wpo365_review_dismissed'] ) ) { // phpcs:ignore
				Wpmu_Helpers::mu_set_transient( 'wpo365_review_dismissed', gmdate( 'd' ), 1209600 );
				Url_Helpers::force_redirect( remove_query_arg( 'wpo365_review_dismissed' ) );
			}

			if ( isset( $_GET['wpo365_review_stop'] ) ) { // phpcs:ignore
				Wpmu_Helpers::mu_delete_transient( 'wpo365_review_dismissed' );
				Options_Service::add_update_option( 'review_stop', true );
				Url_Helpers::force_redirect( remove_query_arg( 'wpo365_review_stop' ) );
			}

			if ( isset( $_GET['wpo365_upgrade_dismissed'] ) ) { // phpcs:ignore
				Wpmu_Helpers::mu_delete_transient( 'wpo365_user_created' );
				Wpmu_Helpers::mu_set_transient( 'wpo365_upgrade_dismissed', gmdate( 'd' ), 1209600 );
				Url_Helpers::force_redirect( remove_query_arg( 'wpo365_upgrade_dismissed' ) );
			}
		}
	}
}
