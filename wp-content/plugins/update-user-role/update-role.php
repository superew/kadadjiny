<?php
/**
 * Plugin Name: Custom Role Permissions
 * Description: A simple plugin to customize User role permission.
 * Version: 1.0
 * Author: Andrew Nguyen
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Add a new menu item under Users for managing user roles
function custom_user_roles_menu() {
    add_users_page(
        'Manage User Roles', // Page title
        'Manage User Roles', // Menu title
        'manage_options',    // Capability
        'manage-user-roles', // Menu slug
        'custom_user_roles_page' // Callback function
    );
}
add_action('admin_menu', 'custom_user_roles_menu');

// Display the user roles management page
function custom_user_roles_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle form submissions for adding/editing roles
    if (isset($_POST['submit'])) {
        $role_name = sanitize_text_field($_POST['role_name']);
        $role_capabilities = isset($_POST['capabilities']) ? $_POST['capabilities'] : [];

        if (isset($_POST['action']) && $_POST['action'] === 'add') {
            // Add new role
            add_role($role_name, ucfirst($role_name), $role_capabilities);
            echo '<div class="updated"><p>Role added successfully!</p></div>';
        } elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
            // Edit existing role
            $role = get_role($role_name);
            if ($role) {
                foreach ($role_capabilities as $cap) {
                    $role->add_cap($cap);
                }
                echo '<div class="updated"><p>Role updated successfully!</p></div>';
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
            // Delete role
            remove_role($role_name);
            echo '<div class="updated"><p>Role deleted successfully!</p></div>';
        }
    }

    // Get existing roles
    global $wp_roles;
    $roles = $wp_roles->roles;

    ?>
    <div class="wrap">
        <h1>Manage User Roles</h1>
        <form method="post" action="">
            <h2>Add New Role</h2>
            <label for="role_name">Role Name:</label>
            <input type="text" name="role_name" id="role_name" required>
            <h3>Capabilities:</h3>
            <label><input type="checkbox" name="capabilities[]" value="read"> Read</label><br>
            <label><input type="checkbox" name="capabilities[]" value="edit_posts"> Edit Posts</label><br>
            <label><input type="checkbox" name="capabilities[]" value="upload_files"> Upload Files</label><br>
            <input type="hidden" name="action" value="add">
            <input type="submit" name="submit" class="button button-primary" value="Add Role">
        </form>

        <h2>Edit Existing Roles</h2>
        <form method="post" action="">
            <label for="existing_roles">Select Role:</label>
            <select name="role_name" id="existing_roles">
                <?php foreach ($roles as $role_slug => $role_details): ?>
                    <option value="<?php echo esc_attr($role_slug); ?>"><?php echo esc_html($role_details['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <h3>Capabilities:</h3>
            <label><input type="checkbox" name="capabilities[]" value="read"> Read</label><br>
            <label><input type="checkbox" name="capabilities[]" value="edit_posts"> Edit Posts</label><br>
            <label><input type="checkbox" name="capabilities[]" value="upload_files"> Upload Files</label><br>
            <input type="hidden" name="action" value="edit">
            <input type="submit" name="submit" class="button button-primary" value="Edit Role">
        </form>

        <h2>Delete Existing Role</h2>
        <form method="post" action="">
            <label for="delete_roles">Select Role to Delete:</label>
            <select name="role_name" id="delete_roles">
                <?php foreach ($roles as $role_slug => $role_details): ?>
                    <option value="<?php echo esc_attr($role_slug); ?>"><?php echo esc_html($role_details['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="delete">
            <input type="submit" name="submit" class="button button-danger" value="Delete Role">
        </form>
    </div>
    <?php
}
// // Function to reset administrator capabilities to default
// // Hook into plugin activation to reset admin capabilities
// register_activation_hook(__FILE__, 'reset_admin_capabilities');