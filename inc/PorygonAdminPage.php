<?php

namespace Eren\Porygon;

class PorygonAdminPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_filter('use_block_editor_for_post', '__return_false', 10);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_ajax_porygon_upload_csv', [$this, 'ajaxHandleCsvUpload']);
        add_action('wp_ajax_porygon_insert_data', [$this, 'ajaxInsertCsvData']);
    }

    public function enqueueScripts($hook)
    {
        if ('toplevel_page_porygon-page' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'porygon-admin-style',
            plugin_dir_url(__FILE__) . '../css/porygon-admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'porygon-admin-script',
            plugin_dir_url(__FILE__) . '../js/porygon-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script(
            'porygon-admin-script',
            'porygon_vars',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('porygon_nonce'),
                'loading_text' => __('Processing...', 'porygon-plugin'),
                'success_text' => __('Success!', 'porygon-plugin'),
                'error_text' => __('Error:', 'porygon-plugin')
            ]
        );
    }

    public function addMenuPage()
    {
        add_menu_page(
            'Porygon Page',
            'Porygon',
            'manage_options',
            'porygon-page',
            [$this, 'renderPage'],
            'dashicons-admin-plugins',
            6
        );
    }

    public function renderPage()
    {
?>
        <div class="wrap">
            <h1><?php esc_html_e('Upload CSV/XLSX file', 'porygon-plugin'); ?></h1>
            <p><?php esc_html_e('Import file to generate data.', 'porygon-plugin'); ?></p>

            <div id="porygon-notices"></div>
            <div id="porygon-upload-form">
                <?php $this->renderCsvUploadForm(); ?>
            </div>
            <div id="porygon-mapping-form" style="display:none;"></div>
        </div>
    <?php
    }

    private function renderCsvUploadForm()
    {
    ?>
        <form id="porygon-csv-upload-form" method="post" enctype="multipart/form-data" class="wp-core-ui">
            <h2><?php esc_html_e('Upload CSV File', 'porygon-plugin'); ?></h2>

            <!-- Post Type Selection -->
            <div class="form-field">
                <label for="post_type"><?php esc_html_e('Select Post Type', 'porygon-plugin'); ?></label>
                <select name="post_type" id="post_type" required class="postform">
                    <?php
                    $post_types = get_post_types(['public' => true], 'objects');
                    foreach ($post_types as $post_type) {
                        echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->labels->singular_name) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <!-- File Upload -->
            <div class="form-field">
                <label for="csv_file"><?php esc_html_e('Choose CSV File', 'porygon-plugin'); ?></label>
                <input type="file" name="csv_file" accept=".csv,.xlsx" required class="upload">
            </div>

            <!-- Hidden Fields -->
            <input type="hidden" name="action" value="porygon_upload_csv">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('porygon_nonce'); ?>">

            <!-- Submit Button -->
            <div class="form-field">
                <button type="submit" id="upload-csv-button" class="button button-primary">
                    <?php esc_html_e('Upload File', 'porygon-plugin'); ?>
                </button>
            </div>
        </form>


    <?php
    }

    public function ajaxHandleCsvUpload()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'porygon-plugin')]);
        }

        // Check if file exists
        if (!isset($_FILES['csv_file']) || empty($_FILES['csv_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('No file uploaded', 'porygon-plugin')]);
        }

        $file = $_FILES['csv_file'];
        $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);

        if ($fileExt === 'csv') {
            $csvData = $this->parseCsv($file['tmp_name']);
        } elseif ($fileExt === 'xlsx') {
            wp_send_json_error(['message' => __('XLSX support coming soon', 'porygon-plugin')]);
            return;
        } else {
            wp_send_json_error(['message' => __('Invalid file type. Please upload CSV or XLSX', 'porygon-plugin')]);
            return;
        }

        if (!$csvData) {
            wp_send_json_error(['message' => __('Invalid CSV file or unable to read', 'porygon-plugin')]);
            return;
        }

        $postType = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        if (!$postType) {
            wp_send_json_error(['message' => __('No post type selected', 'porygon-plugin')]);
            return;
        }

        // Get mapping form HTML
        ob_start();
        $this->renderMappingForm($csvData, $postType);
        $mappingForm = ob_get_clean();

        wp_send_json_success([
            'message' => __('File uploaded successfully', 'porygon-plugin'),
            'mapping_form' => $mappingForm,
            'csv_data' => $csvData
        ]);
    }

    private function parseCsv($filePath)
    {
        if (($handle = fopen($filePath, 'r')) !== false) {
            $csvData = [];
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $csvData[] = $data;
            }
            fclose($handle);
            return $csvData;
        }
        return false;
    }

    private function renderMappingForm($csvData, $postType)
    {
        // Fetch post meta fields for the selected post type
        $postMetaFields = $this->getPostMetaFields($postType);

        // Add 'post_title' and 'post_content' to the meta fields
        $coreFields = ['post_title', 'post_content'];
        $allFields = array_merge($coreFields, $postMetaFields);

        // Get headers from first row of CSV
        $headers = isset($csvData[0]) ? $csvData[0] : [];
    ?>
        <h2><?php esc_html_e('Map CSV Columns to WordPress Fields', 'porygon-plugin'); ?></h2>
        <form id="porygon-mapping-form-data" method="post">
            <input type="hidden" name="post_type" value="<?php echo esc_attr($postType); ?>">
            <input type="hidden" name="action" value="porygon_insert_data">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('porygon_nonce'); ?>">
            <input type="hidden" name="csv_data" value="<?php echo esc_attr(json_encode($csvData)); ?>">
            <input type="hidden" id="csv-headers" value="<?php echo esc_attr(json_encode($headers)); ?>">

            <table id="field-mapping-table" class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('WordPress Field', 'porygon-plugin'); ?></th>
                        <th><?php esc_html_e('CSV Column', 'porygon-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allFields as $fieldName): ?>
                        <?php
                        $isRequired = in_array($fieldName, ['post_title']);
                        $requiredAttr = $isRequired ? ' required' : '';
                        $requiredText = $isRequired ? ' <span class="required">*</span>' : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html($fieldName) . $requiredText; ?></td>
                            <td>
                                <select name="field_map[<?php echo esc_attr($fieldName); ?>]" <?php echo $requiredAttr; ?>>
                                    <option value=""><?php esc_html_e('- Select Column -', 'porygon-plugin'); ?></option>
                                    <?php foreach ($headers as $index => $header): ?>
                                        <?php
                                        $selected = (strtolower($fieldName) === strtolower($header)) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($index); ?>" <?php echo $selected; ?>>
                                            <?php echo esc_html($header); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Custom meta fields will be added here dynamically -->
                </tbody>
            </table>

            <p>
                <button type="button" id="add-new-meta-field" class="button">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php esc_html_e('Add New Meta Field', 'porygon-plugin'); ?>
                </button>
            </p>

            <p>
                <button type="submit" id="insert-data-button" class="button-primary">
                    <?php esc_html_e('Insert All', 'porygon-plugin'); ?>
                </button>
            </p>
        </form>
<?php
    }

    public function ajaxInsertCsvData()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'porygon-plugin')]);
        }

        // Check for required data
        if (!isset($_POST['csv_data']) || !isset($_POST['field_map']) || !isset($_POST['post_type'])) {
            wp_send_json_error(['message' => __('Missing required data', 'porygon-plugin')]);
        }

        $csvData = json_decode(stripslashes($_POST['csv_data']), true);
        $fieldMap = $_POST['field_map'];
        $postType = sanitize_text_field($_POST['post_type']);

        // Fixed check for title mapping - properly handle '0' value
        if (!isset($fieldMap['post_title']) || ($fieldMap['post_title'] === '' && $fieldMap['post_title'] !== '0')) {
            wp_send_json_error(['message' => __('Title field mapping is required', 'porygon-plugin')]);
        }

        // Rest of the method remains the same...
        $headers = array_shift($csvData);
        $insertCount = 0;
        $errorCount = 0;
        $results = [];

        foreach ($csvData as $rowIndex => $row) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Prepare post data
            $postData = [
                'post_type' => $postType,
                'post_status' => 'draft',
            ];

            // Prepare meta data
            $metaData = [];

            // Map fields to post data and meta data
            foreach ($fieldMap as $wpField => $csvIndex) {
                // Fixed check - properly handle '0' value
                if ($csvIndex === '' && $csvIndex !== '0') {
                    continue;
                }

                $value = isset($row[(int)$csvIndex]) ? $row[(int)$csvIndex] : '';

                // Check if field is core or meta
                if (in_array($wpField, ['post_title', 'post_content'])) {
                    $postData[$wpField] = $value;
                } else {
                    $metaData[$wpField] = $value;
                }
            }

            // Check if title is set (required)
            if (empty($postData['post_title'])) {
                $errorCount++;
                $results[] = [
                    'row' => $rowIndex + 2,
                    'status' => 'error',
                    'message' => __('Missing title', 'porygon-plugin')
                ];
                continue;
            }

            // Insert post
            $postId = wp_insert_post($postData);

            if (is_wp_error($postId)) {
                $errorCount++;
                $results[] = [
                    'row' => $rowIndex + 2,
                    'status' => 'error',
                    'message' => $postId->get_error_message()
                ];
            } else {
                // Insert meta fields
                foreach ($metaData as $key => $value) {
                    update_post_meta($postId, $key, $value);
                }
                $insertCount++;
                $results[] = [
                    'row' => $rowIndex + 2,
                    'status' => 'success',
                    'post_id' => $postId,
                    'title' => $postData['post_title']
                ];
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Import completed: %d posts created successfully, %d failed.', 'porygon-plugin'),
                $insertCount,
                $errorCount
            ),
            'success_count' => $insertCount,
            'error_count' => $errorCount,
            'results' => $results
        ]);
    }
    private function getPostMetaFields($post_type)
    {
        global $wpdb;

        // Query all post IDs of the given post type
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 100",
                $post_type
            )
        );

        // Initialize an array to store the meta keys
        $meta_keys = [];

        // Loop through all the post IDs
        foreach ($post_ids as $post_id) {
            // Get the meta keys for each post
            $post_meta = get_post_meta($post_id);

            // Loop through the post meta and add keys to the array
            foreach ($post_meta as $key => $value) {
                if (!in_array($key, $meta_keys) && !strpos($key, '_')) {
                    $meta_keys[] = $key;
                }
            }
        }

        return $meta_keys;
    }
}
