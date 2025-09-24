<?php
/**
 * Plugin Name: Custom Admin Page
 * Description: A simple plugin to customize the WordPress login page.
 * Version: 1.0
 * Author: Andrew Nguyen
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue custom styles
function custom_login_styles() {
    $logo_url = plugin_dir_url( __FILE__ ) . 'images/kac-logo.png';
    echo '<style>
        #login h1 a {
            background-image: url(' . $logo_url . ');
            background-size: contain;
            width: 100%;
            height: 80px;
        }
        body.login {
            background-color: #f1f1f1;
        }
        .wp-core-ui .button-primary {
            background: #31b36b !important;
            border: none !important;
            text-shadow: none !important;
            box-shadow: none !important;
        }
        .wp-core-ui .button-primary:hover {
        background-color: grey !important;
        }
        // .login form {
        //     box-shadow: none !important;
        //     background: transparent !important;
        // }
    </style>';
}
add_action( 'login_enqueue_scripts', 'custom_login_styles' );

// Change the login logo URL
function custom_login_logo_url() {
    return home_url();
}
add_filter( 'login_headerurl', 'custom_login_logo_url' );

// Change the login logo title
function custom_login_logo_title() {
    return 'Your Site Name';
}
add_filter( 'login_headertitle', 'custom_login_logo_title' );

// /**
//  * Thêm logo vào trang quản trị WordPress
//  */
//  function tp_admin_logo() {
//     echo ‘<br/> <img src="’ .plugins_url(‘images/kac-logo.png’, __FILE__). ‘"/>’;
//  }
// add_action( ‘admin_notices’, ‘tp_admin_logo’ );

// /**
//  * Sửa chữ dưới footer của trang quản trị
//  */
// function tp_admin_footer_credits( $text ) {
//     $text = ‘Welcome to Kadadjiny admin page’;
//     return $text;
// }
// add_filter( ‘admin_footer_text’, ‘tp_admin_footer_credits’ );