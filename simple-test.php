<?php
/**
 * Plugin Name:       TypeForm - Modern Form Builder
 * Description:       Create beautiful, responsive forms enabling shortcode to be used in any post or page
 * version:           1.1.0 
 * requires at least: 5.0
 * requires PHP:      7.2
 * author:            Ahsan Habib Rafat
 * author URI:        https://github.com/Niltory11
 * license:           GPLv2 or later
 * license URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * update URI:        https://github.com/Niltory11
 * text domain:       sstwp-rafat
 * domain path:       /languages   
 */


// Test if plugin is loading
add_action('admin_notices', 'typeform_test_notice');
function typeform_test_notice() {
    echo '<div class="notice notice-info is-dismissible">';
    echo '<p><strong>TypeForm Plugin is loaded!</strong> Check the sidebar for TypeForm menu.</p>';
    echo '</div>';
    
    // Force recreate tables if needed
    global $wpdb;
    $forms_table = $wpdb->prefix . 'typeform_forms';
    $submissions_table = $wpdb->prefix . 'typeform_submissions';
    
    $forms_exists = $wpdb->get_var("SHOW TABLES LIKE '$forms_table'") == $forms_table;
    $submissions_exists = $wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") == $submissions_table;
    
    if (!$forms_exists || !$submissions_exists) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>TypeForm Tables Missing!</strong> Deactivate and reactivate the plugin to create tables.</p>';
        echo '</div>';
    }
}

// Handle form submissions and deletions
add_action('init', 'handle_typeform_submission');
function handle_typeform_submission() {
    // Handle form submission
    if ($_POST && isset($_POST['action']) && $_POST['action'] == 'submit_typeform') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'typeform_submissions';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            // Create table if it doesn't exist
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                form_id mediumint(9) NOT NULL,
                data longtext NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        $form_id = intval($_POST['form_id']);
        $data = [];

        // Store all submitted fields dynamically (except wp hidden fields)
        foreach ($_POST as $key => $value) {
            if (in_array($key, ['action', 'form_id'])) continue;
            $data[$key] = sanitize_text_field($value);
        }

        // Debug: Log the submission attempt
        error_log('TypeForm Submission: Form ID = ' . $form_id . ', Data = ' . print_r($data, true));

        $result = $wpdb->insert(
            $table_name,
            array(
                'form_id' => $form_id,
                'data' => wp_json_encode($data)
            ),
            array('%d', '%s')
        );

        if ($result === false) {
            error_log('TypeForm Error: ' . $wpdb->last_error);
        } else {
            error_log('TypeForm Success: Submission saved with ID = ' . $wpdb->insert_id);
        }

        // Redirect to prevent resubmission
        wp_redirect(add_query_arg('submitted', '1', wp_get_referer()));
        exit;
    }
    
    // Handle submission deletion
    if (isset($_GET['action']) && $_GET['action'] == 'delete_submission' && isset($_GET['id'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        global $wpdb;
        $submissions_table = $wpdb->prefix . 'typeform_submissions';
        $submission_id = intval($_GET['id']);
        
        $result = $wpdb->delete(
            $submissions_table,
            array('id' => $submission_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_redirect(add_query_arg('deleted', '1', admin_url('admin.php?page=typeform-submissions')));
        } else {
            wp_redirect(add_query_arg('error', '1', admin_url('admin.php?page=typeform-submissions')));
        }
        exit;
    }
}

// Add success message
add_action('wp_footer', 'typeform_success_message');
function typeform_success_message() {
    if (isset($_GET['submitted']) && $_GET['submitted'] == '1') {
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            alert("Form submitted successfully!");
        });
        </script>';
    }
}

// Enqueue frontend assets
add_action('wp_enqueue_scripts', 'typeform_enqueue_assets');
function typeform_enqueue_assets() {
	// Only enqueue on pages where the shortcode exists or on singular pages
	if (is_admin()) return;
	if (!is_singular() && !is_front_page() && !is_home()) return;
	// Basic heuristic: always enqueue on the frontend; it's lightweight
	wp_enqueue_style(
		'typeform-frontend',
		plugins_url('assets/style.css', __FILE__),
		array(),
		'1.0.0'
	);

	wp_enqueue_script(
		'typeform-frontend',
		plugins_url('assets/script.js', __FILE__),
		array(),
		'1.0.0',
		true
	);
}

// Add shortcode for frontend forms
add_shortcode('typeform', 'typeform_shortcode');
function typeform_shortcode($atts) {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'typeform_forms';

    $atts = shortcode_atts(array('id' => 1), $atts);
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id=%d", $atts['id']));

    if (!$form) return "<p>No form found with ID: " . $atts['id'] . "</p>";

    $fields = json_decode($form->fields, true);
    if (!$fields) return "<p>Form is empty.</p>";

    ob_start(); ?>
    <div class="typeform-container" style="max-width: 500px; margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h3><?php echo esc_html($form->title); ?></h3>
        <form method="post" class="typeform-form">
            <input type="hidden" name="action" value="submit_typeform">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($atts['id']); ?>">

            <?php foreach ($fields as $field): ?>
                <?php if ($field['type'] === 'text' || $field['type'] === 'email'): ?>
                    <p>
                        <label for="<?php echo esc_attr($field['name']); ?>"><?php echo esc_html($field['label']); ?></label><br>
                        <input type="<?php echo esc_attr($field['type']); ?>" name="<?php echo esc_attr($field['name']); ?>" id="<?php echo esc_attr($field['name']); ?>" required style="width: 100%; padding: 8px; margin: 5px 0;">
                    </p>
                <?php elseif ($field['type'] === 'textarea'): ?>
                    <p>
                        <label for="<?php echo esc_attr($field['name']); ?>"><?php echo esc_html($field['label']); ?></label><br>
                        <textarea name="<?php echo esc_attr($field['name']); ?>" id="<?php echo esc_attr($field['name']); ?>" rows="5" required style="width: 100%; padding: 8px; margin: 5px 0;"></textarea>
                    </p>
                <?php elseif ($field['type'] === 'select'): ?>
                    <p>
                        <label for="<?php echo esc_attr($field['name']); ?>"><?php echo esc_html($field['label']); ?></label><br>
                        <select name="<?php echo esc_attr($field['name']); ?>" id="<?php echo esc_attr($field['name']); ?>" required style="width: 100%; padding: 8px; margin: 5px 0;">
                            <option value="">Select an option</option>
                            <?php foreach ($field['options'] as $option): ?>
                                <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
            <p>
                <input type="submit" value="Submit Form" class="button button-primary" style="background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer;">
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// Plugin activation hook
register_activation_hook(__FILE__, 'typeform_activate');
function typeform_activate() {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'typeform_forms';
    $submissions_table = $wpdb->prefix . 'typeform_submissions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE $forms_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        fields longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $submissions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        form_id mediumint(9) NOT NULL,
        data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);

    // Insert a sample form if none exists
    $exists = $wpdb->get_var("SELECT COUNT(*) FROM $forms_table");
    if (!$exists) {
        $sample_fields = json_encode([
            ["id" => "field_1", "type" => "text", "label" => "Full Name", "required" => true, "placeholder" => "Enter your name"],
            ["id" => "field_2", "type" => "email", "label" => "Email Address", "required" => true, "placeholder" => "you@example.com"],
            ["id" => "field_3", "type" => "textarea", "label" => "Message", "required" => false, "placeholder" => "Write something..."],
            ["id" => "field_4", "type" => "select", "label" => "Department", "required" => true, "options" => ["Sales", "Support", "HR"]]
        ]);
        $wpdb->insert($forms_table, [
            'title' => 'Sample Contact Form',
            'fields' => $sample_fields
        ]);
    }
}

// Register admin menu
add_action('admin_menu', 'typeform_plugin_add_admin_menu');
function typeform_plugin_add_admin_menu() {
    add_menu_page(
        'TypeForm Dashboard',
        'TypeForm',
        'manage_options',
        'typeform-plugin',
        'typeform_dashboard_page',
        'dashicons-feedback',
        31
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
    echo '<div class="wrap"><h1>TypeForm Dashboard</h1>';
    echo '<p>Welcome to TypeForm - Modern Form Builder</p>';
    echo '<a href="' . admin_url('admin.php?page=typeform-builder') . '" class="button button-primary">Create New Form</a> ';
    echo '<a href="' . admin_url('admin.php?page=typeform-submissions') . '" class="button">View Submissions</a>';
    echo '</div>';
}

// Form Builder page
function typeform_builder_page() {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'typeform_forms';
    
    // Handle form creation
    if (isset($_POST['create_form'])) {
        $title = sanitize_text_field($_POST['form_title']);
        $fields = array();
        
        // Process form fields
        if (isset($_POST['field_type']) && is_array($_POST['field_type'])) {
            foreach ($_POST['field_type'] as $index => $type) {
                if (!empty($_POST['field_label'][$index])) {
                    $field = array(
                        'name' => 'field_' . ($index + 1),
                        'type' => $type,
                        'label' => sanitize_text_field($_POST['field_label'][$index])
                    );
                    
                    // Add options for select fields
                    if ($type === 'select' && !empty($_POST['field_options'][$index])) {
                        $options = explode(',', sanitize_text_field($_POST['field_options'][$index]));
                        $field['options'] = array_map('trim', $options);
                    }
                    
                    $fields[] = $field;
                }
            }
        }
        
        if (!empty($title) && !empty($fields)) {
            $wpdb->insert($forms_table, array(
                'title' => $title,
                'fields' => json_encode($fields)
            ));
            echo '<div class="notice notice-success"><p>Form created successfully! Use shortcode: <code>[typeform id="' . $wpdb->insert_id . '"]</code></p></div>';
        }
    }
    
    // Get existing forms
    $forms = $wpdb->get_results("SELECT * FROM $forms_table ORDER BY created_at DESC");
    
    echo '<div class="wrap">';
    echo '<h1>Form Builder</h1>';
    
    // Create new form section
    echo '<h2>Create New Form</h2>';
    echo '<form method="post" style="max-width: 600px;">';
    echo '<p><label>Form Title: <input type="text" name="form_title" required style="width: 100%;"></label></p>';
    
    echo '<h3>Add Questions</h3>';
    echo '<div id="fields-container">';
    echo '<div class="field-row">';
    echo '<p><label>Question: <input type="text" name="field_label[]" placeholder="Enter your question" required></label></p>';
    echo '<p><label>Type: <select name="field_type[]" required>';
    echo '<option value="text">Text Input</option>';
    echo '<option value="email">Email Input</option>';
    echo '<option value="textarea">Text Area</option>';
    echo '<option value="select">Dropdown/Select</option>';
    echo '</select></label></p>';
    echo '<p><label>Options (for dropdown): <input type="text" name="field_options[]" placeholder="Option 1, Option 2, Option 3"></label></p>';
    echo '</div>';
    echo '</div>';
    
    echo '<p><button type="button" onclick="addField()" class="button">Add Another Question</button></p>';
    echo '<p><input type="submit" name="create_form" value="Create Form" class="button button-primary"></p>';
    echo '</form>';
    
    // Existing forms section
    if (!empty($forms)) {
        echo '<h2>Existing Forms</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Title</th><th>Questions</th><th>Shortcode</th><th>Created</th></tr></thead><tbody>';
        
        foreach ($forms as $form) {
            $fields = json_decode($form->fields, true);
            $question_count = count($fields);
            
            echo '<tr>';
            echo '<td>' . $form->id . '</td>';
            echo '<td>' . esc_html($form->title) . '</td>';
            echo '<td>' . $question_count . ' questions</td>';
            echo '<td><code>[typeform id="' . $form->id . '"]</code></td>';
            echo '<td>' . $form->created_at . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    echo '<script>
    function addField() {
        const container = document.getElementById("fields-container");
        const newField = document.createElement("div");
        newField.className = "field-row";
        newField.style.borderTop = "1px solid #ccc";
        newField.style.paddingTop = "10px";
        newField.style.marginTop = "10px";
        newField.innerHTML = `
            <p><label>Question: <input type="text" name="field_label[]" placeholder="Enter your question" required></label></p>
            <p><label>Type: <select name="field_type[]" required>
                <option value="text">Text Input</option>
                <option value="email">Email Input</option>
                <option value="textarea">Text Area</option>
                <option value="select">Dropdown/Select</option>
            </select></label></p>
            <p><label>Options (for dropdown): <input type="text" name="field_options[]" placeholder="Option 1, Option 2, Option 3"></label></p>
        `;
        container.appendChild(newField);
    }
    </script>';
    
    echo '</div>';
}

// Submissions page
function typeform_submissions_page() {
    global $wpdb;
    $submissions_table = $wpdb->prefix . 'typeform_submissions';
    $forms_table = $wpdb->prefix . 'typeform_forms';
    
    // Show success/error messages
    if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Success!</strong> Submission deleted successfully.</p>';
        echo '</div>';
    }
    
    if (isset($_GET['error']) && $_GET['error'] == '1') {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>Error!</strong> Failed to delete submission.</p>';
        echo '</div>';
    }
    
    $submissions = $wpdb->get_results("SELECT * FROM $submissions_table ORDER BY created_at DESC");

    echo '<div class="wrap"><h1>Form Submissions</h1>';
    
    if (empty($submissions)) {
        echo '<p>No submissions yet. Create a form and test it!</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Form</th><th>Answers</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
        
        foreach ($submissions as $submission) {
            $data = json_decode($submission->data, true);
            $form = $wpdb->get_row($wpdb->prepare("SELECT title FROM $forms_table WHERE id = %d", $submission->form_id));
            $form_title = $form ? $form->title : 'Unknown Form';
            
            echo '<tr>';
            echo '<td>' . $submission->id . '</td>';
            echo '<td>' . esc_html($form_title) . '</td>';
            echo '<td>';
            
            if ($data && is_array($data)) {
                echo '<div style="max-width: 400px;">';
                foreach ($data as $field_name => $answer) {
                    echo '<strong>' . esc_html($field_name) . ':</strong> ';
                    echo esc_html($answer) . '<br>';
                }
                echo '</div>';
            } else {
                echo '<em>No data</em>';
            }
            
            echo '</td>';
            echo '<td>' . $submission->created_at . '</td>';
            echo '<td>';
            echo '<a href="' . admin_url('admin.php?page=typeform-submissions&action=delete_submission&id=' . $submission->id) . '" ';
            echo 'onclick="return confirm(\'Are you sure you want to delete this submission? This action cannot be undone.\')" ';
            echo 'class="button button-small button-link-delete">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    echo '</div>';
}
