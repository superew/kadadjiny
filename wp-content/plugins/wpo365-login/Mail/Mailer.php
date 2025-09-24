<?php

namespace Wpo\Mail;

use Exception;
use ReflectionFunction;
use Wpo\Core\WordPress_Helpers;
use Wpo\Mail\Mail_Authorization_Helpers;
use Wpo\Services\Log_Service;
use Wpo\Services\Options_Service;
use WP_Error;
use Wpo\Core\Wpmu_Helpers;
use Wpo\Services\Access_Token_Service;
use Wpo\Services\Request_Service;

// Prevent public access to this script
defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\Wpo\Mail\Mailer' ) ) {

	class Mailer {


		public $phpmailer_data;
		public $is_ms_graph;

		// See https://wordpress.org/support/topic/not-compatible-with-gravityform/#post-16554594
		public $ErrorInfo; // phpcs:ignore

		public static function init( &$phpmailer ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( Options_Service::mu_use_subsite_options() && ! Wpmu_Helpers::mu_is_network_admin() ) {
				$mail_auth_value = get_option( 'wpo365_mail_authorization' );
			} else {
				$mail_auth_value = get_site_option( 'wpo365_mail_authorization' );
			}

			$mail_auth = empty( $mail_auth_value ) ? null : json_decode( $mail_auth_value );

			// Check for delegated authorization
			if ( ( empty( $mail_auth ) || ! property_exists( $mail_auth, 'access_token' ) ) && ! Options_Service::get_global_boolean_var( 'mail_staging_mode' ) ) {

				// Check for application-level authorization
				$mail_access_token_app_only = Access_Token_Service::get_app_only_access_token(
					'https://graph.microsoft.com/.default',
					'Mail.Send',
					true,
					'DEBUG'
				);

				if ( is_wp_error( $mail_access_token_app_only ) ) {
					$message = sprintf(
						'%s -> Authorization to sent emails using Microsoft Graph is missing and therefore email with subject "%s" for recipient(s) "%s" was not sent using the WPO365 Graph Mailer. Please complete the authorization or disable the WPO365 Graph Mailer.',
						__METHOD__,
							$phpmailer->Subject, // phpcs:ignore
							print_r( $phpmailer->getToAddresses(), true ) // phpcs:ignore
					);
					Log_Service::write_log( 'ERROR', $message );
					do_action( 'wpo365/mail/sent/fail', $message );
					return;
				}
			}

			$phpmailer_data            = clone $phpmailer;
			$phpmailer                 = new Mailer();
			$phpmailer->phpmailer_data = $phpmailer_data;
			$phpmailer->is_ms_graph    = true;
		}

		/**
		 * The send method of "this" PHPMailer.
		 *
		 * @return bool
		 *
		 * @throws \Exception Throws Exception.
		 */
		public function send( $retry_error_code = 0 ) {
			Log_Service::write_log( 'DEBUG', '##### -> ' . __METHOD__ );

			if ( Options_Service::get_global_boolean_var( 'mail_staging_mode' ) ) {
				$log_message = 'An email was not sent because the administrator activated the Mail Staging Mode. Please use the <a href="#mailLogViewer">Mail Log Viewer</a> to view staged emails.';
				self::update_mail_log( $log_message, true, 'WARN', true );
				return true;
			}

			$to = self::validate_email_addresses( $this->phpmailer_data->getToAddresses(), 'WARN' );

			if ( empty( $to ) ) {

				$mail_error = sprintf(
					'Cannot sent email with subject "%s" when no valid recipient has been specified [%s]',
					$this->phpmailer_data->Subject,
					print_r( $this->phpmailer_data->getToAddresses(), true ) // phpcs:ignore
				);

				self::update_mail_log( $mail_error, false, 'ERROR', true );
				return false;
			}

			$cc        = self::validate_email_addresses( $this->phpmailer_data->getCcAddresses(), 'WARN' );
			$bcc       = self::validate_email_addresses( $this->phpmailer_data->getBccAddresses(), 'WARN' );
			$reply_tos = self::validate_email_addresses( $this->phpmailer_data->getReplyToAddresses(), 'WARN' );

			if ( count( $reply_tos ) > 0 ) {
				$_mail_reply_to = $reply_tos;
			} else {
				$_mail_reply_to = self::to_array_of_valid_email_addresses( Options_Service::get_global_string_var( 'mail_reply_to' ) );
			}

			$reply_to = self::validate_email_addresses( $_mail_reply_to );

			$attachments       = array();
			$large_attachments = array();

			foreach ( $this->phpmailer_data->getAttachments() as $attachment ) {

				/**
				 * 0 => $path
				 * 1 => $filename
				 * 2 => $name
				 * 3 => $encoding
				 * 4 => $type
				 * 5 => false //isStringAttachment
				 * 6 => $disposition
				 * 7 => $name
				 */

				// The check for file_exists may be superflous because when the path not exists the attachment is not found in the array.

				$file_exists = false;
				$log_message = null;

				try {
					$file_exists = \file_exists( $attachment[0] );
					$file_size   = \filesize( $attachment[0] );

					if ( $file_size > 3145728 ) {

						if ( class_exists( '\Wpo\Mail\Mail_Attachments' ) ) {
							\Wpo\Mail\Mail_Attachments::add_large_attachment( $large_attachments, $attachment );
							continue;
						} else {
							throw new \Exception( 'Sending email attachments larger than 3 Mb using Microsoft Graph is <a href="https://www.wpo365.com/downloads/wpo365-mail/" target="blank">a premium feature</a>.' );
						}
					}
				} catch ( \Exception $e ) {
					$log_message = \sprintf(
						'%s -> Attachment cannot be added (path: %s, name: %s, type: %s, error: %s)',
						__METHOD__,
						$attachment[0],
						$attachment[2],
						$attachment[4],
						$e->getMessage()
					);

					self::update_mail_log( $log_message, false, 'ERROR', false );
					continue;
				}

				if ( $file_exists && empty( $attachment[5] ) && ! empty( $attachment[0] ) && ! empty( $attachment[2] ) && ! empty( $attachment[4] ) ) {
					$content = \base64_encode( \file_get_contents( $attachment[0] ) ); // phpcs:ignore

					$attachments[] = array(
						'@odata.type'  => '#microsoft.graph.fileAttachment',
						'name'         => $attachment[2],
						'contentType'  => $attachment[4],
						'contentBytes' => $content,
					);
				} else {
					$log_message = \sprintf(
						'%s -> Attachment cannot be added (path: %s, name: %s, type: %s, exists: %s)',
						__METHOD__,
						$attachment[0],
						$attachment[2],
						$attachment[4],
						$file_exists ? 'Yes' : 'No'
					);

					self::update_mail_log( $log_message, false, 'ERROR', false );
				}
			}

			if ( Options_Service::get_global_boolean_var( 'mail_send_to_bcc' ) ) {
				$default_recipient = Options_Service::get_global_string_var( 'mail_default_recipient' );

				if ( filter_var( $default_recipient, FILTER_VALIDATE_EMAIL ) ) {

					foreach ( $to as $to_recipient ) {
						$bcc[] = $to_recipient;
					}

					$to = array( array( 'emailAddress' => array( 'address' => $default_recipient ) ) );

					foreach ( $cc as $cc_recipient ) {
						$bcc[] = $cc_recipient;
					}

					$cc = array();
				} else {
					$log_message = sprintf( '%s -> The administrator has configured the option to send mail as BCC but did not specify a valid default recipient [%s]', __METHOD__, $default_recipient );
					self::update_mail_log( $log_message, false, 'ERROR', false );
				}
			}

			/**
			 * @since   15.0    Allow to send emails as text.
			 */
			$content_type = $this->phpmailer_data->ContentType !== 'text/plain' && Options_Service::get_global_string_var( 'mail_mime_type' ) === 'Html' ? 'Html' : 'Text';

			/**
			 * @since   21.9    Include custom headers
			 */

			$custom_headers           = $this->phpmailer_data->getCustomHeaders();
			$internet_message_headers = array();

			if ( count( $custom_headers ) > 0 ) {

				foreach ( $custom_headers as $custom_header ) {

					// Filter for custom headers starting with X- or x-
					if ( is_array( $custom_header ) && count( $custom_header ) === 2 && WordPress_Helpers::stripos( $custom_header[0], 'x-' ) === 0 ) {
						$internet_message_headers[] = array(
							'name'  => $custom_header[0],
							'value' => $custom_header[1],
						);
					}
				}
			}

			/**
			 * @since   18.0    Allow to override mail user
			 */

			$mail_from = Options_Service::get_global_string_var( 'mail_from' );
			$sender    = $mail_from;
			$send_from = array( 'emailAddress' => array( 'address' => $sender ) );

			/**
			 * @since   20.0    Allow to send from a Shared Mailbox
			 */

			if ( Options_Service::get_global_boolean_var( 'mail_send_shared' ) ) {
				$shared_mailbox = Options_Service::get_global_string_var( 'mail_send_shared_from' );

				if ( filter_var( $shared_mailbox, FILTER_VALIDATE_EMAIL ) ) {
					$array_of_email_addresses = self::validate_email_addresses( $shared_mailbox );

					if ( is_array( $array_of_email_addresses ) && count( $array_of_email_addresses ) === 1 ) {

						if ( $retry_error_code === 403 ) {
							$sender    = $array_of_email_addresses[0]['emailAddress']['address'];
							$send_from = null;
						} else {
							$send_from = $array_of_email_addresses[0];
						}
					}
				}
			}

			/**
			 * @since   21.x    Allow to send as / on behalf of
			 */
			if ( Options_Service::get_global_boolean_var( 'mail_send_as' ) ) {
				$mail_send_as_from = Options_Service::get_global_string_var( 'mail_send_as_from' );

				if ( filter_var( $mail_send_as_from, FILTER_VALIDATE_EMAIL ) ) {
					$array_of_email_addresses = self::validate_email_addresses( $mail_send_as_from );

					if ( is_array( $array_of_email_addresses ) && count( $array_of_email_addresses ) === 1 ) {

						if ( $retry_error_code === 403 ) {
							$sender    = $array_of_email_addresses[0]['emailAddress']['address'];
							$send_from = null;
						} else {
							$send_from = $array_of_email_addresses[0];
						}
					}
				}
			}

			// Forms can always override

			if ( Options_Service::get_global_boolean_var( 'mail_allow_from' ) ) {
				$forms_from = $this->phpmailer_data->From;

				if ( filter_var( $forms_from, FILTER_VALIDATE_EMAIL ) && strcasecmp( $forms_from, $sender ) !== 0 ) {
					$mail_from_domain  = ( explode( '@', $mail_from ) )[1];
					$forms_from_domain = ( explode( '@', $forms_from ) )[1];

					// Only allow to change the send-from account if the domain matches with the configured mail_from mail address' domain
					if ( strcasecmp( $mail_from_domain, $forms_from_domain ) === 0 || Options_Service::get_global_boolean_var( 'mail_skip_all_checks' ) ) {
						$array_of_email_addresses = self::validate_email_addresses( $forms_from );

						if ( is_array( $array_of_email_addresses ) && count( $array_of_email_addresses ) === 1 ) {

							if ( $retry_error_code === 403 ) {
								$sender    = $array_of_email_addresses[0]['emailAddress']['address'];
								$send_from = null;
							} else {
								$send_from = $array_of_email_addresses[0];
							}
						}
					} else {
						Log_Service::write_log( 'WARN', sprintf( '%s -> Ignoring the forms-from mail address \'%s\' because its domain is differs from the domain of the sending account \'%s\'', __METHOD__, $forms_from, $sender ) );
					}
				}
			}

			if ( empty( $large_attachments ) ) {
				$scope = Options_Service::get_global_boolean_var( 'mail_send_shared' ) ? 'Mail.Send.Shared' : 'Mail.Send';
			} else {
				$scope = Options_Service::get_global_boolean_var( 'mail_send_shared' ) ? 'Mail.ReadWrite.Shared' : 'Mail.ReadWrite';
			}

			$save_sent       = Options_Service::get_global_boolean_var( 'mail_save_to_sent_items' );
			$message_as_json = self::email_message_encode( $this->phpmailer_data->Subject, $to, $this->phpmailer_data->Body, $cc, $bcc, $reply_to, $content_type, $save_sent, $attachments, ! empty( $large_attachments ), $send_from, $internet_message_headers );
			$query           = empty( $large_attachments )
				? sprintf( '/users/%s/sendMail', rawurlencode( $sender ) )
				: sprintf( '/users/%s/messages', rawurlencode( $sender ) );
			$access_token    = Mail_Authorization_Helpers::get_mail_access_token( $scope );

			if ( is_wp_error( $access_token ) ) {
				$message_sent_result = $access_token;
			} else {
				$continue = apply_filters( 'wpo365/mail/before', null );

				if ( is_wp_error( $continue ) ) {
					$log_level = Options_Service::get_global_boolean_var( 'mail_auto_retry' ) ? 'WARN' : 'ERROR';
					self::update_mail_log( $continue->get_error_message(), false, $log_level, true );
					return false;
				}

				$message_sent_result = self::mg_fetch( $query, $message_as_json, $access_token['access_token'] );
			}

			// Default error

			$error_message = sprintf(
				'Could not sent %semail using Microsoft Graph',
				! empty( $large_attachments ) ? 'draft-' : ''
			);

			// Error occured at transport level e.g. cURL errors

			if ( is_wp_error( $message_sent_result ) ) {
				$log_message = $error_message . ' [Error: ' . $message_sent_result->get_error_message() . ']';
				self::update_mail_log( $log_message, false, 'ERROR', true );
				return false;
			}

			// Error occurred at Microsoft Graph

			if ( $message_sent_result['response_code'] < 200 || $message_sent_result['response_code'] > 299 ) {

				// Retry after 403 when using application-type permissions

				if ( isset( $access_token['type'] ) && $access_token['type'] === 'application' && $message_sent_result['response_code'] === 403 && $retry_error_code === 0 ) {
					Log_Service::write_log(
						'WARN',
						sprintf(
							'%s -> %s [Error: The user account which was used to submit this request \'%s\' does not have the right to send mail on behalf of the specified sending account \'%s\'].',
							__METHOD__,
							$error_message,
							$sender,
							( is_array( $send_from ) ? $send_from['emailAddress']['address'] : 'n.a.' )
						)
					);
					return $this->send( 403 );
				}

				if ( is_array( $message_sent_result ) && isset( $message_sent_result['payload'] ) && isset( $message_sent_result['payload']['error'] ) && isset( $message_sent_result['payload']['error']['message'] ) ) {
					$error_code  = isset( $message_sent_result['payload']['error']['code'] ) ? sprintf( ' | %s', $message_sent_result['payload']['error']['code'] ) : '';
					$log_message = sprintf(
						'%s [Error: %s%s]',
						$error_message,
						$message_sent_result['payload']['error']['message'],
						$error_code
					);
					self::update_mail_log( $log_message, false, 'ERROR', true );
					return false;
				}

				$log_message = sprintf( '%s [See debug log for details]', $error_message );
				self::update_mail_log( $log_message, false, 'ERROR', true );
				Log_Service::write_log( 'WARN', $message_sent_result );
				return false;
			}

			// Message is sent as draft -> Upload large attachments and send email when done

			if ( ! empty( $large_attachments ) && class_exists( '\Wpo\Mail\Mail_Attachments' ) ) {

				try {
					$message_id = $message_sent_result['payload']['id'];
					return \Wpo\Mail\Mail_Attachments::send_draft_email( $message_id, $sender, $large_attachments, $this->phpmailer_data->Subject, self::implode_to( $to ) );
				} catch ( \Exception $e ) {
					$log_message = sprintf(
						'Creating a draft email to be sent with attachments larger than 3 Mb failed [message ID not found in response]. Please manually delete the draft message from the "Drafts" folder for the account %s',
						$sender
					);
					self::update_mail_log( $log_message, false, 'ERROR', true );
					return false;
				}
			}

			$log_message = sprintf( 'WordPress email with subject [%s] sent successfully to [%s] using Microsoft Graph', $this->phpmailer_data->Subject, self::implode_to( $to ) );
			self::update_mail_log( $log_message, true, 'DEBUG', true );

			return true;
		}

		/**
		 * Sends a template based test mail to the mail address provided
		 *
		 * @since   11.7
		 *
		 * @param   mixed $to Email address(es).
		 *
		 * @return  boolean     True if succesful otherwise false
		 */
		public static function send_test_mail( $to, $cc = array(), $bcc = array(), $sent_attachment = false ) {
			Log_Service::write_log( 'DEBUG', __METHOD__ . ' -> Sending test email to ' . print_r( $to, true ) ); // phpcs:ignore

			// Check if wp_mail has been plugged
			if ( self::check_wp_mail() === false ) {
				return false;
			}

			add_filter( 'phpmailer_init', '\Wpo\Mail\Mailer::check_phpmailer', PHP_INT_MAX );

			$content_type = Options_Service::get_global_string_var( 'mail_mime_type' ) === 'Html' ? 'text/html' : 'text/plain';
			$template     = $content_type === 'text/html' ? 'test-mail-html' : 'test-mail-text';

			ob_start();
			include $GLOBALS['WPO_CONFIG']['plugin_dir'] . '/templates/' . $template . '.php';
			$content = wp_kses( ob_get_clean(), WordPress_Helpers::get_allowed_html() );

			$headers = array( \sprintf( 'Content-Type: %s; charset=UTF-8', $content_type ) );

			$_to  = self::to_array_of_valid_email_addresses( $to );
			$_cc  = self::to_array_of_valid_email_addresses( $cc );
			$_bcc = self::to_array_of_valid_email_addresses( $bcc );

			foreach ( $_cc as $__cc ) {
				$headers[] = \sprintf( 'cc: %s', $__cc );
			}

			foreach ( $_bcc as $__bcc ) {
				$headers[] = \sprintf( 'bcc: %s', $__bcc );
			}

			$subject = '[' . wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) . '] Test email by WPO365 | LOGIN Graph Mailer';

			$attachments = array();

			if ( $sent_attachment ) {

				$path = sprintf( '%s/wpo365-test-email-attachment.pdf', __DIR__ );

				if ( file_exists( $path ) ) {
					$attachments[] = $path;
				}
			}

			/**
			 * @since   18.1
			 *
			 * When sending test email always use configured from address.
			 */
			remove_all_filters( 'wp_mail_from' );
			add_filter( 'wp_mail_from', '\Wpo\Mail\Mailer::mail_from', 10, 1 );

			return wp_mail( $_to, $subject, $content, $headers, $attachments );
		}

		/**
		 * Filters wp_mail_from hook and sets it to the send-mail-from account (since 18.1) whenever it
		 * detects the WordPress configured default email address.
		 *
		 * @since   17.0
		 *
		 * @param   string $from_email     The current from address from wp_mail().
		 *
		 * @return  string      The filtered $from_email if the corresponding option was checked.
		 */
		public static function mail_from( $from_email ) {
			if ( Options_Service::get_global_boolean_var( 'use_graph_mailer' ) ) {

				if ( WordPress_Helpers::stripos( $from_email, 'wordpress@' ) !== false ) {
					// Get the site domain and get rid of www.
					$sitename    = wp_parse_url( network_home_url(), PHP_URL_HOST );
					$_from_email = 'wordpress@';

					if ( $sitename !== null ) {
						if ( substr( $sitename, 0, 4 ) === 'www.' ) {
							$sitename = substr( $sitename, 4 );
						}

						$_from_email .= $sitename;
					}

					if ( strcasecmp( $from_email, $_from_email ) === 0 ) {

						if ( Options_Service::get_global_boolean_var( 'mail_send_shared' ) ) {
							$shared_mailbox = Options_Service::get_global_string_var( 'mail_send_shared_from' );

							if ( filter_var( $shared_mailbox, FILTER_VALIDATE_EMAIL ) ) {
								return $shared_mailbox;
							}
						}

						$configured_mail_from = Options_Service::get_global_string_var( 'mail_from' );

						if ( filter_var( $configured_mail_from, FILTER_VALIDATE_EMAIL ) ) {
							return $configured_mail_from;
						}
					}
				}
			}

			return $from_email;
		}

		/**
		 * @since       19.0
		 *
		 * @param mixed $query
		 * @param mixed $body
		 * @param mixed $access_token
		 *
		 * @return      WP_Error|array
		 */
		public static function mg_fetch( $query, $body, $access_token, $method = 'POST', $headers = null ) {
			if ( empty( $headers ) ) {
				$headers                  = array( 'Content-Type' => 'application/json' );
				$headers['Authorization'] = sprintf( 'Bearer %s', $access_token );
				$headers['Expect']        = '';
			}

			$graph_version = Options_Service::get_global_string_var( 'graph_version' );
			$graph_version = empty( $graph_version ) || $graph_version === 'current'
				? 'v1.0'
				: 'beta';

			$tld = Options_Service::get_aad_option( 'tld' );
			$url = WordPress_Helpers::stripos( $query, 'https://' ) === 0
				? $query
				: sprintf(
					'https://graph.microsoft%s/%s/%s',
					! empty( $tld ) ? $tld : '.com',
					$graph_version,
					WordPress_Helpers::ltrim( $query, '/' )
				);

			$skip_ssl_verify = ! Options_Service::get_global_boolean_var( 'skip_host_verification' );

			Log_Service::write_log(
				'DEBUG',
				sprintf(
					'%s -> Fetching from Microsoft Graph to send WordPress emails using: %s',
					__METHOD__,
					$url
				)
			);

			$response = wp_remote_request(
				$url,
				array(
					'body'      => $body,
					'method'    => $method,
					'headers'   => $headers,
					'sslverify' => $skip_ssl_verify,
				)
			);

			if ( is_wp_error( $response ) ) {
				$error_message = sprintf(
					'%s -> Error occured whilst fetching from Microsoft Graph (%s): %s',
					__METHOD__,
					$url,
					$response->get_error_message()
				);

				$error_code = $response->get_error_code();

				if ( empty( $error_code ) ) {
					$error_code = 'GraphFetchException';
				}

				return new \WP_Error( $error_code, $error_message );
			}

			$body      = wp_remote_retrieve_body( $response );
			$body      = json_decode( $body, true );
			$http_code = wp_remote_retrieve_response_code( $response );
			return array(
				'payload'       => $body,
				'response_code' => $http_code,
			);
		}

		/**
		 * Logs debug / warning / error info to the WPO365 log stream and optionally to the WPO365 mail log DB.
		 *
		 * @since 24.0
		 *
		 * @param mixed $log_message
		 * @param bool  $success
		 * @param mixed $log_level
		 * @return void
		 */
		public static function update_mail_log( $log_message, $success, $log_level, $count_attempt ) {
			$request_service = Request_Service::get_instance();
			$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );

			if ( $count_attempt ) {

				if ( ! $success ) {
					do_action( 'wpo365/mail/sent/fail', $log_message );
				} else {
					do_action( 'wpo365/mail/sent', $log_message );
				}
			}

			if ( class_exists( '\Wpo\Mail\Mail_Db' ) ) {
				\Wpo\Mail\Mail_Db::update_mail_log(
					$success,
					$log_message,
					$count_attempt
				);
			}

			if ( ! empty( $log_level ) ) {
				$log_props_level = $log_level === 'WARN' ? 'warning' : 'error';
				$log_props       = array( 'wpoMail' => $log_props_level );
				$request->set_item( 'mail_error', $log_message );
				Log_Service::write_log( $log_level, sprintf( '%s -> %s', __METHOD__, $log_message, $log_props ) );
			}

			// if the attempt counts then now it is time to clean up
			if ( $count_attempt ) {
				$request->remove_item( 'mail_log_id' );
			}
		}

		/**
		 * Checks whether wp_mail has been plugged by another (must-use) plugin or similar.
		 *
		 * @since   21.6
		 *
		 * @return bool
		 */
		public static function check_wp_mail() {
			// Cannot perform the check
			if ( ! defined( 'WPINC' ) ) {
				return true;
			}

			try {
				$wp_mail_reflection = new ReflectionFunction( 'wp_mail' );
				$wp_mail_filepath   = $wp_mail_reflection->getFileName();
				$separator          = defined( 'DIRECTORY_SEPARATOR' ) ? DIRECTORY_SEPARATOR : '/';

				if ( strpos( $wp_mail_filepath, WPINC . $separator . 'pluggable.php' ) === false ) {
					$wp_mail_error = sprintf(
						'It appears that another (must-use) plugin [path: %s] has changed the WordPress "wp_mail" function. Most likely WPO365 is not able to successfully send WordPress emails using Microsoft Graph. You should deactivate and delete the plugin in question.',
						$wp_mail_filepath
					);

					Log_Service::write_log(
						'ERROR',
						sprintf(
							'%s -> %s',
							__METHOD__,
							$wp_mail_error
						)
					);

					$request_service = Request_Service::get_instance();
					$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
					$request->set_item( 'mail_error', $wp_mail_error );

					return false;
				}
			} catch ( Exception $e ) { // phpcs:ignore
			}

			return true;
		}

		/**
		 * Checks whether the instantiated PHP Mailer is the WPO365 Mailer.
		 *
		 * @since   21.6
		 *
		 * @return void
		 */
		public static function check_phpmailer( $phpmailer ) {
			if ( ! \property_exists( $phpmailer, 'is_ms_graph' ) || ! $phpmailer->is_ms_graph ) {
				$phpmailer_error = 'It appears that another (must-use) plugin has taken precedence over the WPO365 PHPMailer instance. Please review if any of your active plugins is configured to send WordPress emails e.g. "WP Mail SMTP" and deactivate it. Failing to do so, may prevent the WPO365 plugin to successfully send WordPress emails using Microsoft Graph.';

				Log_Service::write_log(
					'ERROR',
					sprintf(
						'%s -> %s',
						__METHOD__,
						$phpmailer_error
					)
				);

				$request_service = Request_Service::get_instance();
				$request         = $request_service->get_request( $GLOBALS['WPO_CONFIG']['request_id'] );
				$request->set_item( 'mail_error', $phpmailer_error );
			}
		}

		/**
		 * Will try to turn the input into an array of valid email addresses.
		 *
		 * @since   17.0
		 *
		 * @param   mixed $input  String or array to be converted.
		 * @return  array   Array of valid email addresses.
		 */
		private static function to_array_of_valid_email_addresses( $input ) {
			$email_addresses = array();
			$result          = array();

			if ( \is_string( $input ) ) {
				$json = json_decode( stripslashes( $input ), true );

				if ( json_last_error() === JSON_ERROR_NONE && is_array( $json ) ) {
					$email_addresses = $json;
				} elseif ( WordPress_Helpers::stripos( $input, ',' ) !== false ) {
					$email_addresses = \explode( ',', $input );
				} else {
					$email_addresses = array( $input );
				}
			}

			if ( \is_array( $input ) ) {
				$email_addresses = $input;
			}

			array_filter(
				$email_addresses,
				function ( $item ) use ( &$result ) {

					if ( ! \is_string( $item ) ) {
						return false;
					}

					$trimmed_sanitized = sanitize_email( trim( $item ) );

					if ( filter_var( $trimmed_sanitized, FILTER_VALIDATE_EMAIL ) ) {

						$result[] = $trimmed_sanitized;
						return true;
					}
				}
			);

			return $result;
		}

		/**
		 * Validates email addresses and formats those in Graph-compatible format.
		 *
		 * @since 11.7
		 *
		 * @param   mixed  $email_addresses    single email address, comma separated email address, semi colon separated email address, WP / Graph formatted email address array.
		 * @param   string $level              Level for debug log entries.
		 *
		 * @return  array   Array with valid email address that must checked if empty
		 */
		private static function validate_email_addresses( $email_addresses, $level = 'DEBUG' ) {
			if ( empty( $email_addresses ) ) {
				Log_Service::write_log( $level !== 'ERROR' ? 'DEBUG' : $level, __METHOD__ . ' -> Cannot validate an empty email address' );
				return array();
			}

			// Array that will contain all email addresses after harmonizing the input to a Graph-compatible format
			$_email_addresses = array();

			/**
			 * @param   $unformatted    string  A single email address
			 *
			 * @return  array   Assoc. array in the form WordPress provides and Graph expects it.
			 */
			$format = function ( $unformatted ) {
				return array( 'emailAddress' => array( 'address' => $unformatted ) );
			};

			/**
			 * Handle the case of email address provided as a string
			 * 1. Single email address
			 * 2. Comma seperated email addresses
			 * 3. Semi colon separated email addresses
			 */
			if ( is_string( $email_addresses ) ) {

				if ( WordPress_Helpers::stripos( $email_addresses, ',' ) !== false ) {
					$delimited = \explode( ',', $email_addresses );

					foreach ( $delimited as $_delimited ) {
						$_email_addresses[] = $format( $_delimited );
					}
				} elseif ( WordPress_Helpers::stripos( $email_addresses, ';' ) !== false ) {
					$delimited = \explode( ';', $email_addresses );

					foreach ( $delimited as $_delimited ) {
						$_email_addresses[] = $format( $_delimited );
					}
				} else {
					$_email_addresses[] = $format( $email_addresses );
				}
			} elseif ( is_array( $email_addresses ) ) { // Handle the case of email address provided as an array

				foreach ( $email_addresses as $_email_address ) {

					if ( isset( $_email_address['emailAddress'] ) && isset( $_email_address['emailAddress']['address'] ) ) {
						$_email_addresses[] = $_email_address;
						continue;
					} elseif ( is_array( $_email_address ) && count( $_email_address ) === 2 ) {
						$_email_addresses[] = array( 'emailAddress' => array( 'address' => $_email_address[0] ) );
						continue;
					} elseif ( is_string( $_email_address ) ) {
						$_email_addresses[] = $format( trim( $_email_address ) );
						continue;
					}

					Log_Service::write_log( $level, sprintf( '%s -> Email address format invalid (%s)', __METHOD__, var_export( $_email_address, true ) ) ); // phpcs:ignore
				}
			} else { // If format cannot be parsed then return an empty result
				Log_Service::write_log( $level, sprintf( '%s -> Email address format not recognized (%s)', __METHOD__, var_export( $email_addresses, true ) ) ); // phpcs:ignore
			}

			// Array that will contain all formatted email addresses that will be returned
			$result = array();

			// Validate each email address
			foreach ( $_email_addresses as $_email_address ) {

				try {
					if ( ! filter_var( $_email_address['emailAddress']['address'], FILTER_VALIDATE_EMAIL ) ) {
						Log_Service::write_log( $level, __METHOD__ . ' -> Invalid email address found (' . $_email_address['emailAddress']['address'] . ')' );
						continue;
					}

					$result[] = $_email_address;
				} catch ( \Exception $e ) {
					Log_Service::write_log( $level, sprintf( '%s -> Invalid email address found (%s)', __METHOD__, var_export( $_email_address, true ) ) ); // phpcs:ignore
					continue;
				}
			}

			return $result;
		}

		private static function email_message_encode( $subject, $to, $content, $cc = array(), $bcc = array(), $reply_to = array(), $content_type = 'Text', $save_sent = false, $attachments = array(), $draft = false, $send_from = null, $headers = array() ) {
			if ( $draft ) {
				$message = array(
					'subject'      => $subject,
					'body'         => array(
						'contentType' => $content_type,
						'content'     => $content,
					),
					'toRecipients' => $to,
				);

				if ( ! empty( $send_from ) ) {
					$message['from'] = $send_from;
				}

				if ( ! empty( $cc ) ) {
					$message['ccRecipients'] = $cc;
				}

				if ( ! empty( $bcc ) ) {
					$message['bccRecipients'] = $bcc;
				}

				if ( ! empty( $reply_to ) ) {
					$message['replyTo'] = $reply_to;
				}

				if ( ! empty( $attachments ) ) {
					$message['attachments'] = $attachments;
				}

				if ( ! empty( $headers ) ) {
					$message['internetMessageHeaders'] = $headers;
				}

				return wp_json_encode( $message );
			}

			$message = array(
				'message'         => array(
					'subject'      => $subject,
					'body'         => array(
						'contentType' => $content_type,
						'content'     => $content,
					),
					'toRecipients' => $to,
				),
				'saveToSentItems' => $save_sent,
			);

			if ( ! empty( $cc ) ) {
				$message['message']['ccRecipients'] = $cc;
			}

			if ( ! empty( $send_from ) ) {
				$message['message']['from'] = $send_from;
			}

			if ( ! empty( $bcc ) ) {
				$message['message']['bccRecipients'] = $bcc;
			}

			if ( ! empty( $reply_to ) ) {
				$message['message']['replyTo'] = $reply_to;
			}

			if ( ! empty( $attachments ) ) {
				$message['message']['attachments'] = $attachments;
			}

			if ( ! empty( $headers ) ) {
				$message['message']['internetMessageHeaders'] = $headers;
			}

			return wp_json_encode( $message );
		}

		private static function implode_to( $to ) {
			$result = array();

			foreach ( $to as $to_recipient ) {

				if ( ! empty( $to_recipient['emailAddress'] ) && ! empty( $to_recipient['emailAddress']['address'] ) ) {
					$result[] = $to_recipient['emailAddress']['address'];
				}
			}

			return implode( ',', $result );
		}
	}
}
