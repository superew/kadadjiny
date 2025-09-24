<?php
    // add_role(
    //     'staff', // Unique slug for your role
    //     'Internal Staff', // Display name for your role
    //     array(
    //         'read' => true, // Basic capability for all users
    //         'edit_posts' => false, // Example capability
    //         // Add other desired capabilities here
    //     )
    // );
    // function restrict_admin_menu_for_custom_role() {
    //     if ( current_user_can( 'custom_role_slug' ) ) {
    //         remove_menu_page( 'edit.php' ); // Example: Remove Posts menu
    //         remove_submenu_page( 'options-general.php', 'options-writing.php' ); // Example: Remove Writing settings
    //     }
    // }
    // add_action( 'admin_menu', 'restrict_admin_menu_for_custom_role' );
    
//     function my_wp_nav_menu_args( $args = '' ) {
//     if ( is_user_logged_in() ) {
//         if ( current_user_can( 'administrator' ) ) {
//             // Display 'admin-menu' for users with the 'administrator' role
//             $args['menu'] = 'admin-menu';
//         }
//         if ( current_user_can( 'custom_role_slug' ) ) {
//             // Display 'admin-menu' for users with the 'administrator' role
//             $args['menu'] = 'staff-menu';
//         } else {
//             // Display 'logged-in-menu' for other logged-in users
//             $args['menu'] = 'logged-in-menu';
//         }
//     } else {
//         // Display 'logged-out-menu' for logged-out users
//         $args['menu'] = 'logged-out-menu';
//     }
//     return $args;
// }
// add_filter( 'wp_nav_menu_args', 'my_wp_nav_menu_args' );

// // Thay doi duong dan logo admin
//  function wpc_url_login(){
//  return "https://andrewqa.great-site.net/kadadjiny/"; // duong dan vao website cua ban
//  }
//  add_filter(‘login_headerurl’, ‘wpc_url_login’);