<?php
/*
Plugin Name: TypeForm - Modern Form Builder
Description: Create beautiful, responsive forms with drag-and-drop builder
Version: 1.0.0
Author: Your Name
*/

// Test if plugin is loading
add_action('admin_notices', 'typeform_test_notice');

function typeform_test_notice() {
    echo '<div class="notice notice-info is-dismissible">';
    echo '<p><strong>TypeForm Plugin is loaded!</strong> Check the sidebar for TypeForm menu.</p>';
    echo '</div>';
}

// Handle form submissions
add_action('init', 'handle_typeform_submission');

function handle_typeform_submission() {
    if ($_POST && isset($_POST['action']) && $_POST['action'] == 'submit_typeform') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'typeform_submissions';
        
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $message = sanitize_textarea_field($_POST['message']);
        $form_id = intval($_POST['form_id']);
        
        $wpdb->insert(
            $table_name,
            array(
                'form_id' => $form_id,
                'name' => $name,
                'email' => $email,
                'message' => $message
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        // Redirect to prevent resubmission
        wp_redirect(admin_url('admin.php?page=typeform-submissions&submitted=1'));
        exit;
    }
}

// Add shortcode for frontend forms
add_shortcode('typeform', 'typeform_shortcode');

function typeform_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id' => 1
    ), $atts);
    
    ob_start();
    ?>
    <div class="typeform-container">
        <form method="post" class="typeform-form">
            <input type="hidden" name="action" value="submit_typeform">
            <input type="hidden" name="form_id" value="<?php echo $atts['id']; ?>">
            
            <p><label>Name: <input type="text" name="name" required></label></p>
            <p><label>Email: <input type="email" name="email" required></label></p>
            <p><label>Message: <textarea name="message" rows="4"></textarea></label></p>
            <p><button type="submit" class="button button-primary">Submit Form</button></p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// Plugin activation hook
register_activation_hook(__FILE__, 'typeform_activate');

function typeform_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'typeform_forms';
    $submissions_table = $wpdb->prefix . 'typeform_submissions';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql1 = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        fields longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    $sql2 = "CREATE TABLE $submissions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        form_id mediumint(9) NOT NULL,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        message text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
}

// Register admin menu
add_action('admin_menu', 'typeform_plugin_add_admin_menu');

function typeform_plugin_add_admin_menu() {
    add_menu_page(
        'TypeForm Dashboard',          // Page title
        'TypeForm',                    // Menu title in sidebar
        'manage_options',              // Capability
        'typeform-plugin',             // Menu slug (unique)
        'typeform_dashboard_page',     // Callback function
        'dashicons-feedback',          // Icon
        31                            // Position in menu
    );
    
    add_submenu_page(
        'typeform-plugin',
        'Form Builder',
        'Form Builder',
        'manage_options',
        'typeform-builder',
        'typeform_builder_page'
    );
    
    add_submenu_page(
        'typeform-plugin',
        'Submissions',
        'Submissions',
        'manage_options',
        'typeform-submissions',
        'typeform_submissions_page'
    );
}

// Dashboard page
function typeform_dashboard_page() {
    echo '<div class="wrap">';
    echo '<h1>TypeForm Dashboard</h1>';
    echo '<p>Welcome to TypeForm - Modern Form Builder</p>';
    echo '<p>This plugin allows you to create beautiful, responsive forms.</p>';
    echo '<br>';
    echo '<h2>Quick Actions</h2>';
    echo '<a href="' . admin_url('admin.php?page=typeform-builder') . '" class="button button-primary">Create New Form</a>';
    echo '<a href="' . admin_url('admin.php?page=typeform-submissions') . '" class="button">View Submissions</a>';
    echo '</div>';
}

// Form Builder page
function typeform_builder_page() {
    echo '<div class="wrap">';
    echo '<h1>Form Builder</h1>';
    echo '<div id="typeform-builder">';
    echo '<div class="form-fields">';
    echo '<h3>Add Fields</h3>';
    echo '<button class="button add-field" data-type="text">Text Input</button>';
    echo '<button class="button add-field" data-type="email">Email Input</button>';
    echo '<button class="button add-field" data-type="textarea">Text Area</button>';
    echo '<button class="button add-field" data-type="select">Dropdown</button>';
    echo '<button class="button add-field" data-type="radio">Radio Buttons</button>';
    echo '<button class="button add-field" data-type="checkbox">Checkboxes</button>';
    echo '</div>';
    echo '<div class="form-preview">';
    echo '<h3>Form Preview</h3>';
    echo '<div id="preview-container">';
    echo '<form id="demo-form" method="post">';
    echo '<input type="hidden" name="action" value="submit_typeform">';
    echo '<input type="hidden" name="form_id" value="1">';
    echo '<p><label>Name: <input type="text" name="name" required></label></p>';
    echo '<p><label>Email: <input type="email" name="email" required></label></p>';
    echo '<p><label>Message: <textarea name="message" rows="4"></textarea></label></p>';
    echo '<p><button type="submit" class="button button-primary">Submit Form</button></p>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Submissions page
function typeform_submissions_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'typeform_submissions';
    
    // Get submissions
    $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    
    echo '<div class="wrap">';
    echo '<h1>Form Submissions</h1>';
    
    if (empty($submissions)) {
        echo '<p>No submissions yet. Create a form and test it!</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>Name</th>';
        echo '<th>Email</th>';
        echo '<th>Message</th>';
        echo '<th>Date</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($submissions as $submission) {
            echo '<tr>';
            echo '<td>' . $submission->id . '</td>';
            echo '<td>' . esc_html($submission->name) . '</td>';
            echo '<td>' . esc_html($submission->email) . '</td>';
            echo '<td>' . esc_html($submission->message) . '</td>';
            echo '<td>' . $submission->created_at . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    echo '</div>';
}
