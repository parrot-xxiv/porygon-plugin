<?php

// Disable Gutenberg Editor
add_filter('use_block_editor_for_post', '__return_false', 10);

// Hook to add the admin menu page
add_action('admin_menu', 'custom_plugin_add_menu_page');

function custom_plugin_add_menu_page()
{
    // Adds the top-level menu page to the WordPress dashboard
    add_menu_page(
        'Import Data Page', // Page title
        'Import Data', // Menu title
        'manage_options', // Capability required to view the page
        'import-data-plugin', // Slug (URL)
        'custom_plugin_render_page', // Function that will display the page content
        'dashicons-admin-plugins', // Icon for the menu (optional)
        6 // Position in the menu (optional)
    );
}

// Function to render the content of the custom page
function custom_plugin_render_page()
{
    $post_types = get_post_types(array('public' => true), 'objects');
?>
    <div class="wrap">
        <h1><?php esc_html_e('Hatdog', 'entry-plugin'); ?></h1>
        <p><?php esc_html_e('This is a custom page for your plugin. You can add any content here!', 'my-plugin'); ?></p>
        <!-- Your custom HTML, form, or settings can go here -->

        <!-- Dropdown for Custom Post Types -->
        <form method="post" id="cpt_details_form">
            <select name="cpt_name" id="cpt_name">
                <option value=""><?php esc_html_e('Select a Post Type', 'cpt-details-viewer'); ?></option>
                <?php foreach ($post_types as $post_type): ?>
                    <option value="<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->singular_name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="view_details" value="View Details" class="button-primary">
        </form>

        <!-- CSV Upload Form -->
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <input type="submit" name="upload_csv" value="Upload CSV" class="button-primary">
        </form>

        <?php
        if (isset($_POST['upload_csv']) && !empty($_FILES['csv_file']['tmp_name'])) {
            // Call the function to handle CSV upload
            csv_importer_handle_upload($_FILES['csv_file']);
        }
        ?>

        <?php
        // Check if the form has been submitted and process the selected post type
        if (isset($_POST['view_details']) && !empty($_POST['cpt_name'])) {
            $cpt_name = sanitize_text_field($_POST['cpt_name']);
            cpt_details_viewer_display_details($cpt_name);
        }
        ?>

    </div>
<?php
}

// Function to handle CSV file upload and insert data into CPT or post
function csv_importer_handle_upload($file)
{
    // Check if the file is a CSV
    if ($file['type'] !== 'text/csv') {
        echo '<div class="error"><p>' . esc_html__('Please upload a valid CSV file.', 'csv-importer') . '</p></div>';
        return;
    }

    // Open the CSV file
    $csv = fopen($file['tmp_name'], 'r');
    if ($csv === false) {
        echo '<div class="error"><p>' . esc_html__('Error opening the file.', 'csv-importer') . '</p></div>';
        return;
    }

    // Skip the first row if it contains headers (optional)
    $headers = fgetcsv($csv);

    // Loop through each row of the CSV
    while (($row = fgetcsv($csv)) !== false) {
        // Map each CSV field to variables (assume we have columns: title, content, etc.)
        $title = sanitize_text_field($row[0]);
        $content = sanitize_textarea_field($row[1]);
        $post_type = 'post'; // Change to your CPT slug

        // Create a new post (or CPT) from the CSV data
        $post_data = array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish', // or 'draft'
            'post_type'    => $post_type,
        );

        // Insert the post
        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            // Optionally, set post meta or custom fields here
            update_post_meta($post_id, '_custom_meta_key', 'custom_value'); // Example meta
        }
    }

    // Close the CSV file
    fclose($csv);

    echo '<div class="updated"><p>' . esc_html__('CSV file has been processed and posts have been created.', 'csv-importer') . '</p></div>';
}

// Function to display details for the selected Custom Post Type
function cpt_details_viewer_display_details($cpt_name)
{
    // Get all posts of the selected CPT
    $posts = get_posts(array(
        'post_type' => $cpt_name,
        'posts_per_page' => -1
    ));

    if (empty($posts)) {
        echo '<p>No posts found for this custom post type.</p>';
        return;
    }

    echo '<h2>Details for Custom Post Type: ' . esc_html($cpt_name) . '</h2>';

    // Start the table structure
    echo '<table class="post-details-table" style="width:100%; border: 1px solid #ddd; border-collapse: collapse; margin-bottom: 20px;">';
    echo '<thead><tr><th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Post</th><th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Taxonomy</th><th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Post Meta</th></tr></thead>';
    echo '<tbody>';

    // Loop through each post and display details in rows
    foreach ($posts as $post) {
        // Display post title and ID in the first column
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;"><strong>' . esc_html($post->post_title) . ' (ID: ' . esc_html($post->ID) . ')</strong></td>';

        // Display taxonomies associated with the post in the second column
        $taxonomies = get_object_taxonomies($post->post_type);
        $taxonomy_details = '';
        if ($taxonomies) {
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    $term_names = array_map(function ($term) {
                        return $term->name;
                    }, $terms);
                    $taxonomy_details .= esc_html(get_taxonomy($taxonomy)->labels->singular_name) . ': ' . esc_html(implode(', ', $term_names)) . '<br>';
                }
            }
        }
        echo '<td style="padding: 10px; border: 1px solid #ddd;">' . $taxonomy_details . '</td>';

        // Display post meta in the third column
        $post_meta = get_post_meta($post->ID);
        $post_meta_details = '';
        if (!empty($post_meta)) {
            foreach ($post_meta as $meta_key => $meta_value) {
                $meta_value_str = implode(', ', $meta_value);  // Convert array values into a comma-separated string
                $post_meta_details .= esc_html($meta_key) . ': ' . esc_html($meta_value_str) . '<br>';
            }
        }
        echo '<td style="padding: 10px; border: 1px solid #ddd;">' . $post_meta_details . '</td>';
        echo '</tr>';
    }

    // End the table
    echo '</tbody></table>';


    // // Loop through each post and display details
    // foreach ($posts as $post) {
    //     echo '<h3>' . esc_html($post->post_title) . ' (ID: ' . esc_html($post->ID) . ')</h3>';

    //     // Display Post Meta
    //     $post_meta = get_post_meta($post->ID);
    //     echo '<h4>Post Meta:</h4><ul>';
    //     foreach ($post_meta as $meta_key => $meta_value) {
    //         echo '<li><strong>' . esc_html($meta_key) . ':</strong> ' . esc_html(implode(', ', $meta_value)) . '</li>';
    //     }
    //     echo '</ul>';

    //     // Display Taxonomies associated with the post
    //     $taxonomies = get_object_taxonomies($post->post_type);
    //     if ($taxonomies) {
    //         echo '<h4>Taxonomies:</h4><ul>';
    //         foreach ($taxonomies as $taxonomy) {
    //             $terms = get_the_terms($post->ID, $taxonomy);
    //             if ($terms && !is_wp_error($terms)) {
    //                 echo '<li><strong>' . esc_html(get_taxonomy($taxonomy)->labels->singular_name) . ':</strong>';
    //                 foreach ($terms as $term) {
    //                     echo ' ' . esc_html($term->name) . ',';
    //                 }
    //                 echo '</li>';
    //             }
    //         }
    //         echo '</ul>';
    //     }
    // }
}
