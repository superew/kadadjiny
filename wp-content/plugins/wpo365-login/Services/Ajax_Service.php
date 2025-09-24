<?php

namespace Wpo\Services;

use Wpo\Core\Permissions_Helpers;
use Wpo\Core\WordPress_Helpers;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Insights\Event_Service;
use Wpo\Mail\Mail_Authorization_Helpers;
use Wpo\Mail\Mailer;
use Wpo\Services\Access_Token_Service;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use Wpo\Services\Saml2_Service;
use Wpo\Services\Wp_Config_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Services\Ajax_Service' ) ) {

	class Ajax_Service {


		/**
		 * Gets the tokencache with all available bearer tokens
		 *
		 * @since 5.0
		 *
		 * @return void
		 */
		public static function get_tokencache() {
			if ( Options_Service::get_global_boolean_var( 'enable_token_service' ) === false ) {
				wp_die();
			}

			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the tokencache for a user' );

			self::verify_posted_data( array( 'action', 'scope' ) ); // -> wp_die

			$access_token = Access_Token_Service::get_access_token( esc_url_raw( $_POST['scope'] ) ); // phpcs:ignore

			if ( is_wp_error( $access_token ) ) {
				self::ajax_response( 'NOK', $access_token->get_error_code(), $access_token->get_error_message(), null );
			}

			$result              = new \stdClass();
			$result->accessToken = $access_token->access_token; // phpcs:ignore

			if ( property_exists( $access_token, 'expiry' ) ) {
				$result->expiry = $access_token->expiry;
			}

			self::ajax_response( 'OK', '', '', wp_json_encode( $result ) );
		}

		/**
		 * Delete all access and refresh tokens.
		 *
		 * @since xxx
		 */
		public static function delete_tokens() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to delete access and refresh tokens' );

			if ( Access_Token_Service::delete_tokens( $current_user ) === false ) {
				self::ajax_response( 'NOK', '', '', null );
			} else {
				delete_site_option( 'wpo365_msft_key' );
				self::ajax_response( 'OK', '', '', null );
			}
		}

		/**
		 * Gets the tokencache with all available bearer tokens
		 *
		 * @since 6.0
		 *
		 * @return void
		 */
		public static function get_settings() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the wpo365-login settings' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to get wpo365_options from AJAX service' );
				wp_die();
			}

			$camel_case_options = Options_Service::get_options();

			if ( array_key_exists( 'curlProxy', $camel_case_options ) ) {
				unset( $camel_case_options['curlProxy'] );
			}

			if ( array_key_exists( 'graphAllowGetToken', $camel_case_options ) ) {

				if ( ! array_key_exists( 'graphAllowTokenRetrieval', $camel_case_options ) ) {
					$camel_case_options['graphAllowTokenRetrieval'] = $camel_case_options['graphAllowGetToken'];
				}

				unset( $camel_case_options['graphAllowGetToken'] );
			}

			$options_as_json = wp_json_encode( $camel_case_options );
			self::ajax_response( 'OK', '', '', $options_as_json );
		}

		/**
		 * Gets the tokencache with all available bearer tokens
		 *
		 * @since 9.6
		 *
		 * @return void
		 */
		public static function get_self_test_results() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the wpo365-login self-test results' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to get self-test results from AJAX service' );
				wp_die();
			}

			$self_test_results = Wpmu_Helpers::mu_get_transient( 'wpo365_self_test_results' );

			if ( ! empty( $self_test_results ) ) {
				self::ajax_response( 'OK', '', '', wp_json_encode( $self_test_results ) );
			} else {
				self::ajax_response( 'OK', '', '', wp_json_encode( array() ) );
			}
		}

		/**
		 * Gets the tokencache with all available bearer tokens
		 *
		 * @since 6.0
		 *
		 * @return void
		 */
		public static function update_settings() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to update the wpo365-login settings' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to get wpo365_options from AJAX service' );
				wp_die();
			}

			self::verify_posted_data( array( 'settings' ) ); // -> wp_die
			$reset   = isset( $_POST['reset'] ) && $_POST['reset'] == 'true' ? true : false; // phpcs:ignore
			$updated = Options_Service::update_options( $_POST['settings'], false, $reset ); // phpcs:ignore
			self::ajax_response( $updated === true ? 'OK' : 'NOK', '', '', null );
		}

		/**
		 * Gets the tokencache with all available bearer tokens
		 *
		 * @since 11.18
		 *
		 * @return void
		 */
		public static function delete_settings() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to delete the wpo365-login settings' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to delete wpo365_options from AJAX service' );
				wp_die();
			}

			Options_Service::delete_options();
			Access_Token_Service::delete_tokens( $current_user );

			delete_site_option( 'wpo365_msft_key' );
			delete_site_option( 'wpo365_active_extensions' );
			delete_site_option( 'wpo365_x509_certificates' );

			if ( Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin() ) {
				delete_option( 'wpo365_mail_authorization' );
			} else {
				delete_site_option( 'wpo365_mail_authorization' );
			}

			Wpmu_Helpers::mu_delete_transient( 'wpo365_debug_log' );
			Wpmu_Helpers::mu_delete_transient( 'wpo365_lic_notices' );
			Wpmu_Helpers::mu_delete_transient( 'wpo365_nonce' );
			Wpmu_Helpers::mu_delete_transient( 'wpo365_self_test_results' );
			Wpmu_Helpers::mu_delete_transient( 'wpo365_user_created' );
			Wpmu_Helpers::mu_delete_transient( 'wpo365_review_dismissed' );

			delete_site_transient( 'wpo365_plugins_updated' );
			delete_site_transient( 'wpo365_secrets_expiration_hook_ensured' );

			delete_option( 'wpo_sync_v2_users_unscheduled' );

			$camel_case_options = Options_Service::get_options();

			self::ajax_response( 'OK', '', '', wp_json_encode( $camel_case_options ) );
		}

		/**
		 * Gets the debug log
		 *
		 * @since 7.11
		 *
		 * @return void
		 */
		public static function get_log() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the wpo365-login debug log' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to get wpo365_log from AJAX service' );
				wp_die();
			}

			$log = Wpmu_Helpers::mu_get_transient( 'wpo365_debug_log' );

			if ( empty( $log ) ) {
				$log = array();
			}

			$log = array_reverse( $log );
			self::ajax_response( 'OK', '', '', wp_json_encode( $log ) );
		}

		/**
		 * Gets the current WPO365 Health messages
		 *
		 * @return void
		 */
		public static function get_wpo_health_messages() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the latest WPO365 Health Messages' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission get the latest WPO365 Health Messages from the AJAX service' );
				wp_die();
			}

			$wpo365_errors = Wpmu_Helpers::mu_get_transient( 'wpo365_errors' );
			self::ajax_response( 'OK', '', '', wp_json_encode( $wpo365_errors ) );
		}

		/**
		 * Dismisses all WPO365 Health Messages
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function dismiss_wpo_health_messages() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to dismiss WPO365 Health Messages' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to dismiss WPO365 Health Messages from AJAX service' );
				wp_die();
			}

			Wpmu_Helpers::mu_delete_transient( 'wpo365_errors' );

			self::ajax_response( 'OK', '', '', null );
		}

		/**
		 * Retrieve a summary of WPO365 Insights collected for the given period.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function get_insights_summary() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to view summarized WPO365 Insights' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to view summarized WPO365 Insights from AJAX service' );
				wp_die();
			}

			self::verify_posted_data( array( 'period', 'group' ) ); // -> wp_die
			$period   = sanitize_text_field( $_POST['period'] ); // phpcs:ignore
			$group    = sanitize_text_field( $_POST['group'] ); // phpcs:ignore
			$insights = Event_Service::get_insights_summary( $period, $group );

			if ( is_wp_error( $insights ) ) {
				self::ajax_response( 'NOK', $insights->get_error_code(), $insights->get_error_message(), null );
			}

			self::ajax_response( 'OK', '', '', $insights );
		}

		/**
		 * Retrieve WPO365 Insights collected for the given period.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function get_insights() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to view WPO365 Insights' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to view WPO365 Insights from AJAX service' );
				wp_die();
			}

			self::verify_posted_data( array( 'period', 'event_action', 'event_category', 'event_status', 'start_row' ) ); // -> wp_die
			$period    = sanitize_text_field( $_POST['period'] ); // phpcs:ignore
			$action 	 = sanitize_text_field( $_POST['event_action'] ); // phpcs:ignore
			$category  = sanitize_text_field( $_POST['event_category'] ); // phpcs:ignore
			$status 	 = sanitize_text_field( $_POST['event_status'] ); // phpcs:ignore
			$start_row = intval( sanitize_text_field( $_POST['start_row'] ) ); // phpcs:ignore
			$insights  = Event_Service::get_insights( $action, $category, $status, $period, $start_row );

			if ( is_wp_error( $insights ) ) {
				self::ajax_response( 'NOK', $insights->get_error_code(), $insights->get_error_message(), null );
			}

			self::ajax_response( 'OK', '', '', $insights );
		}

		/**
		 * Export the options as a parseable string that can be used in wp-config.php.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function get_parseable_options() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get the WPO365 options as a parseable string' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to get the WPO365 options as a parseable string from AJAX service' );
				wp_die();
			}

			self::verify_posted_data( array( 'aad_options_only' ) ); // -> wp_die
			$aad_options_only  = filter_var( $_POST['aad_options_only'], FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore
			$parseable_options = Wp_Config_Service::get_parseable_options( $aad_options_only );

			if ( is_wp_error( $parseable_options ) ) {
				self::ajax_response( 'NOK', $parseable_options->get_error_code(), $parseable_options->get_error_message(), null );
			}

			self::ajax_response( 'OK', '', '', $parseable_options );
		}

		/**
		 * Export the options as a parseable string that can be used in wp-config.php.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function is_wpo365_configured() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to validate that WPO365 is configured' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to validate that WPO365 is configured from AJAX service' );
				wp_die();
			}

			$is_configured = Options_Service::is_wpo365_configured();

			if ( $is_configured ) {
				self::ajax_response( 'OK', '', '', null );
			}

			self::ajax_response( 'NOK', '', 'WPO365 configuration is incomplete', null );
		}

		/**
		 * Export the title and id of IdPs defined in the wp-config.php.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function get_multiple_idps() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to get a list of IdPs' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to to get a list of IdPs from AJAX service' );
				wp_die();
			}

			$idps   = Wp_Config_Service::get_multiple_idps();
			$result = array();

			if ( ! empty( $idps ) ) {
				array_map(
					function ( $idp ) use ( &$result ) {

						if ( ! empty( $idp['id'] ) && ! empty( $idp['title'] ) ) {
							$result[] = array(
								'id'    => $idp['id'],
								'title' => $idp['title'],
							);
						}
					},
					$idps
				);
			}

			self::ajax_response( 'OK', '', '', wp_json_encode( $result ) );
		}

		/**
		 * Truncates the insights data.
		 *
		 * @since 27.0
		 *
		 * @return void
		 */
		public static function truncate_insights_data() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to truncate the insights data' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to truncate the insights data from AJAX service' );
				wp_die();
			}

			$truncate_result = Event_Service::truncate_insights_data();

			if ( is_wp_error( $truncate_result ) ) {
				self::ajax_response( 'NOK', $truncate_result->get_error_code(), $truncate_result->get_error_message(), null );
			}

			self::ajax_response( 'OK', '', '', null );
		}

		/**
		 * Sends a test alert to insights.wpo365.com.
		 *
		 * @since 38.0
		 *
		 * @return void
		 */
		public static function send_test_alert() {

			if ( ! class_exists( '\Wpo\Insights\Event_Notify_Service' ) ) {
				Log_Service::write_log( 'WARN', __METHOD__ . ' -> Cannot send test alert because a premium WPO365 plugin - version 38.0 or later - is not installed' );
				self::ajax_response( 'NOK', '', 'To send alerts, make sure you are using a premium WPO365 plugin - version 38.0 or later.', null );
			}

			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to send a test alert' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to send a test alert from AJAX service' );
				wp_die();
			}

			$message = array(
				'You are receiving this "WPO365 Alert" in response to your request to test this functionality.',
				'To ensure successful delivery of future alerts, please add "insights@wpo365.com" to your allow list and verify that messages from this address are not marked as spam.',
			);

			$notifications = new \Wpo\Insights\Event_Notify_Service();
			$notifications->notify( implode( ' ', $message ), 'ALERT' );

			self::ajax_response( 'OK', '', '', null );
		}

		/**
		 * Used to proxy a request from the client-side to another O365 service e.g. yammer
		 * to circumvent CORS issues.
		 *
		 * @since 10.0
		 *
		 * @return void
		 */
		public static function cors_proxy() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to proxy a request' );

			self::verify_posted_data( array( 'url', 'method', 'bearer', 'accept', 'binary' ) ); // -> wp_die
			$url     = \esc_url_raw( $_POST['url'] ); // phpcs:ignore
			$method  = sanitize_text_field( $_POST['method'] ); // phpcs:ignore
			$headers = array(
				'Authorization' => sprintf( 'Bearer %s', $_POST['bearer'] ), // phpcs:ignore
				'Expect'        => '',
			);
			$binary  = filter_var( $_POST['binary'], FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Fetching from ' . $url );

			if ( WordPress_Helpers::stripos( $method, 'GET' ) === 0 ) {
				$response = wp_remote_get(
					$url,
					array(
						'method'    => 'GET',
						'headers'   => $headers,
						'sslverify' => $skip_ssl_verify,
					)
				);
			} elseif ( WordPress_Helpers::stripos( $method, 'POST' ) === 0 && array_key_exists( 'post_fields', $_POST ) ) { // phpcs:ignore
				$response = wp_remote_post(
					$url,
					array(
						'body'      => $_POST['post_fields'], // phpcs:ignore
						'headers'   => $headers,
						'sslverify' => $skip_ssl_verify,
					)
				);
			} else {
				$warning = sprintf( '%s -> Error occured whilst fetching from %s:  Method %s not implemented', __METHOD__, $url, $method );
				Log_Service::write_log( 'WARN', $warning );
				self::ajax_response( 'NOK', '', $warning, null ); // -> wp_die
			}

			if ( is_wp_error( $response ) ) {
				$warning = sprintf( '%s -> Error occured whilst fetching from %s: %s', __METHOD__, $url, $response->get_error_message() );
				Log_Service::write_log( 'WARN', $warning );
				self::ajax_response( 'NOK', '', $warning, null ); // -> wp_die
			}

			$body = wp_remote_retrieve_body( $response );

			if ( $binary ) {
				self::ajax_response( 'OK', '', '', base64_encode( $body ) );  // phpcs:ignore
				// -> wp_die
			}

			json_decode( $body );
			$json_error = json_last_error();

			if ( $json_error === JSON_ERROR_NONE ) {
				self::ajax_response( 'OK', '', '', $body );
			}

			self::ajax_response( 'NOK', '', $json_error, null );
		}

		/**
		 * Send an email to test the Microsoft Graph Mailer configuration.
		 *
		 * @since 11.7
		 *
		 * @return void
		 */
		public static function send_test_mail() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to send a test mail to a user' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to send test mail to a user from AJAX service' );
				wp_die();
			}

			self::verify_posted_data( array( 'to', 'cc', 'bcc', 'attachment' ) ); // -> wp_die
			$attachment = filter_var( $_POST['attachment'], FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore
			$sent       = Mailer::send_test_mail( $_POST['to'], $_POST['cc'], $_POST['bcc'], $attachment ); // phpcs:ignore

			if ( ! $sent ) {
				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$mail_error      = $request->get_item( 'mail_error' );

				if ( empty( $mail_error ) ) {
					$mail_error = 'Check log for errors';
				}

				self::ajax_response( 'NOK', '', $mail_error, null );
			}

			self::ajax_response( 'OK', '', '', null );
		}

		/**
		 * Gets the URL where to redirect the current user to authorize sending email using his account (delegated).
		 *
		 * @since 19.0
		 *
		 * @return void
		 */
		public static function get_mail_authorization_url() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to authorize sending email using Microsoft Graph' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to authorize sending email using Microsoft Graph' );
				wp_die();
			}

			$auth_url      = Mail_Authorization_Helpers::get_mail_authorization_url();
			$is_error      = is_wp_error( $auth_url );
			$error_message = $is_error ? $auth_url->get_error_message() : null;
			self::ajax_response( $is_error ? 'NOK' : 'OK', '', $error_message, $is_error ? null : $auth_url );
		}

		/**
		 * Gets the URL where to redirect the current user to authorize sending email using his account (delegated).
		 *
		 * @since 19.0
		 *
		 * @return void
		 */
		public static function get_mail_auth_configuration() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to retrieve the current mail configuration' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to retrieve the current mail configuration' );
				wp_die();
			}

			self::verify_posted_data( array( 'deleteDelegated', 'deleteAppOnly' ) ); // -> wp_die
			$delete_delegated = filter_var( $_POST['deleteDelegated'], FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore
			$delete_app_only  = filter_var( $_POST['deleteAppOnly'], FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore
			$mail_config      = Mail_Authorization_Helpers::get_mail_auth_configuration( $delete_delegated, $delete_app_only );
			self::ajax_response( 'OK', '', '', $mail_config );
		}

		/**
		 * Will copy the App principal info from the mail-integration-365 plugin to initialize the WPO365
		 * mail configuration. If found, the AJAX response will return OK otherwise NOK.
		 *
		 * @since 22.3
		 *
		 * @return void
		 */
		public static function try_migrate_mail_app_principal_info() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to migrate the App principal info used for sending mail' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to migrate the App principal info used for sending mail' );
				wp_die();
			}

			self::verify_posted_data( array( 'copyMode' ) ); // -> wp_die
			$result = Mail_Authorization_Helpers::try_migrate_mail_app_principal_info( $_POST['copyMode'] === 'copyDelete' ); // phpcs:ignore

			if ( is_wp_error( $result ) ) {
				self::ajax_response( 'NOK', $result->get_error_code(), $result->get_error_message(), null );
			} else {
				self::ajax_response( 'OK', '', '', null );
			}
		}

		/**
		 * Tries to read the SAML IdP configuration from the App Federation Metadata URL
		 *
		 * @since 24.4
		 *
		 * @return void
		 */
		public static function import_idp_meta() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to import the SAML 2.0 IdP metadata' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to import the SAML 2.0 IdP metadata' );
				wp_die();
			}

			$result = Saml2_Service::import_idp_meta();

			if ( is_wp_error( $result ) ) {
				self::ajax_response( 'NOK', '', $result->get_error_message(), null );
			}

			$camel_case_options = Options_Service::get_options();
			self::ajax_response( 'OK', '', '', wp_json_encode( $camel_case_options ) );
		}

		/**
		 * Tries to read the SAML IdP configuration from the App Federation Metadata URL
		 *
		 * @since 24.4
		 *
		 * @return void
		 */
		public static function export_sp_meta() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to export the SAML 2.0 service provider metadata' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to export the SAML 2.0 service provider metadata' );
				wp_die();
			}

			$result = Saml2_Service::export_sp_meta();

			if ( is_wp_error( $result ) ) {
				self::ajax_response( 'NOK', '', $result->get_error_message(), null );
			}

			$camel_case_options = Options_Service::get_options();

			$result = array(
				'xml'      => base64_encode( $result ), // phpcs:ignore
				'settings' => wp_json_encode( $camel_case_options ),
			);

			self::ajax_response( 'OK', '', '', $result );
		}

		/**
		 * Loads the WPO365 main site config and saves it as a new configuration for the subsite
		 *
		 * @since 24.4
		 *
		 * @return void
		 */
		public static function copy_main_site_options() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to copy the main site WPO365 configuration to a subsite' );

			if ( Permissions_Helpers::user_is_admin( $current_user ) === false ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to copy the main site WPO365 configuration to a subsite' );
				wp_die();
			}

			if ( ! is_multisite() || ! Options_Service::mu_use_subsite_options() || $GLOBALS['WPO_CONFIG']['ina'] === true ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Prerequisites to copy the main site WPO365 configuration to a subsite are not met' );
				wp_die();
			}

			$site_options                      = get_site_option( 'wpo365_options', array() );
			$redirect_url                      = get_home_url();
			$redirect_url                      = trailingslashit( $redirect_url );
			$site_options['redirect_url']      = $redirect_url;
			$site_options['mail_redirect_url'] = $redirect_url;
			$site_options['saml_base_url']     = $redirect_url;

			Options_Service::update_options( $site_options, true, true );
			self::ajax_response( 'OK', '', '', null );
		}

		/**
		 * Toggles the WPO365 support mode for WPMU.
		 *
		 * @since 24.4
		 *
		 * @return void
		 */
		public static function switch_wpmu_mode() {
			// Verify AJAX request
			$current_user = self::verify_ajax_request( 'to switch the WPMU support mode' );

			if ( ! user_can( $current_user->ID, 'delete_users' ) ) { // Allow only network super admins.
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> User has no permission to switch WPMU support mode' );
				wp_die();
			}

			if ( ! is_multisite() || $GLOBALS['WPO_CONFIG']['ina'] !== true ) {
				Log_Service::write_log( 'ERROR', __METHOD__ . ' -> Prerequisites to switch WPMU support mode are not met' );
				wp_die();
			}

			$wpmu_mode     = Options_Service::mu_use_subsite_options() ? 'wpmuShared' : 'wpmuDedicated';
			$update_result = update_site_option( 'wpo365_wpmu_mode', $wpmu_mode );
			$ok            = $update_result ? 'OK' : 'NOK';

			self::ajax_response( $ok, '', '', null );
		}

		/**
		 * Checks for valid nonce and whether user is logged on and returns WP_User if OK or else
		 * writes error response message and return it to requester
		 *
		 * @since 5.0
		 *
		 * @param   string $error_message_fragment used to write a specific error message to the log.
		 * @return  WP_User if verified or else error response is returned to requester
		 */
		public static function verify_ajax_request( $error_message_fragment ) {
			$error_message = '';

			if ( ! is_user_logged_in() ) {
				$error_message = 'Attempt ' . $error_message_fragment . ' by a user that is not logged on';
			}

			if (
				Options_Service::get_global_boolean_var( 'enable_nonce_check' )
				&& ( ! isset( $_POST['nonce'] )
					|| ! wp_verify_nonce( $_POST['nonce'], 'wpo365_fx_nonce' ) ) // phpcs:ignore
			) {
				$error_message = 'Request ' . $error_message_fragment . ' has been tampered with (invalid nonce)';
			}

			if ( strlen( $error_message ) > 0 ) {
				Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> ' . $error_message );

				$response = array(
					'status'  => 'NOK',
					'message' => $error_message,
					'result'  => array(),
				);
				wp_send_json( $response );
				wp_die();
			}

			return wp_get_current_user();
		}

		/**
		 * Stops the execution of the program flow when a key is not found in the the global $_POST
		 * variable and returns a given error message
		 *
		 * @since 5.0
		 *
		 * @param array $keys array of keys to search for.
		 * @param bool  $sanitize
		 *
		 * @return void
		 */
		public static function verify_posted_data( $keys, $sanitize = true ) {

			foreach ( $keys as $key ) {

				if ( ! array_key_exists( $key, $_POST ) ) { // phpcs:ignore
					self::ajax_response( 'NOK', '1000', 'Incomplete data posted to complete request: ' . implode( ', ', $keys ), array() );
				}

				if ( $sanitize ) {
					$_POST[ $key ] = sanitize_text_field( $_POST[ $key ] ); // phpcs:ignore
				}
			}
		}

		/**
		 * Helper method to standardize response returned from a Pintra AJAX request
		 *
		 * @since 5.0
		 *
		 * @param   string $status OK or NOK.
		 * @param   string $error_codes
		 * @param   string $message customer message returned to requester.
		 * @param   mixed  $result associative array that is parsed as JSON and returned.
		 * @return void
		 */
		public static function ajax_response( $status, $error_codes, $message, $result ) {
			Log_Service::write_log( 'DEBUG', __METHOD__ . " -> Sending an AJAX response with status $status and message $message" );
			wp_send_json(
				array(
					'status'      => $status,
					'error_codes' => $error_codes,
					'message'     => $message,
					'result'      => $result,
				)
			);
			wp_die();
		}
	}
}
