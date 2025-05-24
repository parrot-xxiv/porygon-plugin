<?php

namespace Eren\Porygon;

class PorygonImportCsvPage
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
        if ('toplevel_page_porygon-import-csv' !== $hook) {
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
            'Import CSV Page',
            'Import CSV',
            'manage_options',
            'porygon-import-csv',
            [$this, 'renderPage'],
            'dashicons-admin-plugins',
            6
        );
    }

    public function renderPage()
    {
?>
        <div class="wrap">
            <h1><?php esc_html_e('Upload CSV file', 'porygon-plugin'); ?></h1>
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
        
        // Get taxonomies for the post type
        $taxonomies = get_object_taxonomies($postType, 'objects');
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

            <?php if (!empty($taxonomies)): ?>
            <h3><?php esc_html_e('Taxonomy Mapping', 'porygon-plugin'); ?></h3>
            <table id="taxonomy-mapping-table" class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('WordPress Taxonomy', 'porygon-plugin'); ?></th>
                        <th><?php esc_html_e('CSV Column', 'porygon-plugin'); ?></th>
                        <th><?php esc_html_e('Create Missing Terms', 'porygon-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taxonomies as $taxonomy): ?>
                        <tr>
                            <td><?php echo esc_html($taxonomy->labels->singular_name); ?></td>
                            <td>
                                <select name="taxonomy_map[<?php echo esc_attr($taxonomy->name); ?>]">
                                    <option value=""><?php esc_html_e('- Select Column -', 'porygon-plugin'); ?></option>
                                    <?php foreach ($headers as $index => $header): ?>
                                        <?php 
                                        $selected = (strtolower($taxonomy->name) === strtolower($header) || 
                                                    strtolower($taxonomy->labels->singular_name) === strtolower($header)) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($index); ?>" <?php echo $selected; ?>>
                                            <?php echo esc_html($header); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="checkbox" name="create_terms[<?php echo esc_attr($taxonomy->name); ?>]" value="1" checked>
                                <?php esc_html_e('Create if not exists', 'porygon-plugin'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

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
        $taxonomyMap = isset($_POST['taxonomy_map']) ? $_POST['taxonomy_map'] : [];
        $createTerms = isset($_POST['create_terms']) ? $_POST['create_terms'] : [];

        // Fixed check for title mapping - properly handle '0' value
        if (!isset($fieldMap['post_title']) || ($fieldMap['post_title'] === '' && $fieldMap['post_title'] !== '0')) {
            wp_send_json_error(['message' => __('Title field mapping is required', 'porygon-plugin')]);
        }

        $headers = array_shift($csvData);
        $insertCount = 0;
        $errorCount = 0;
        $results = [];
        $errors = [];
        
        // First pass: Validate all data before insertion
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

            // Map fields to post data
            foreach ($fieldMap as $wpField => $csvIndex) {
                // Fixed check - properly handle '0' value
                if ($csvIndex === '' && $csvIndex !== '0') {
                    continue;
                }

                $value = isset($row[(int)$csvIndex]) ? $row[(int)$csvIndex] : '';

                if (in_array($wpField, ['post_title', 'post_content'])) {
                    $postData[$wpField] = $value;
                }
            }

            // Check if title is set (required)
            if (empty($postData['post_title'])) {
                $errorCount++;
                $errors[] = [
                    'row' => $rowIndex + 2,
                    'message' => __('Missing title', 'porygon-plugin')
                ];
            }
            
            // Validate taxonomy terms if needed
            foreach ($taxonomyMap as $taxonomy => $csvIndex) {
                if (!empty($csvIndex) || $csvIndex === '0') {
                    $termNames = isset($row[(int)$csvIndex]) ? explode(',', $row[(int)$csvIndex]) : [];
                    
                    foreach ($termNames as $termName) {
                        $termName = trim($termName);
                        if (empty($termName)) continue;
                        
                        // Check if we can create terms
                        if (!isset($createTerms[$taxonomy]) && !term_exists($termName, $taxonomy)) {
                            $errorCount++;
                            $errors[] = [
                                'row' => $rowIndex + 2,
                                'message' => sprintf(__('Term "%s" does not exist in taxonomy "%s" and creation is not enabled', 'porygon-plugin'), $termName, $taxonomy)
                            ];
                        }
                    }
                }
            }
        }
        
        // If there are validation errors, abort import
        if ($errorCount > 0) {
            wp_send_json_error([
                'message' => __('Validation failed. No data was imported.', 'porygon-plugin'),
                'errors' => $errors
            ]);
            return;
        }
        
        // If no errors, proceed with insertion
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
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

                // Insert post
                $postId = wp_insert_post($postData);

                if (is_wp_error($postId)) {
                    throw new \Exception($postId->get_error_message() . ' at row ' . ($rowIndex + 2));
                }

                // Insert meta fields
                foreach ($metaData as $key => $value) {
                    update_post_meta($postId, $key, $value);
                }
                
                // Process taxonomy terms
                // Process taxonomy terms - MORE ROBUST LOOKUP
                foreach ($taxonomyMap as $taxonomy => $csvIndex) {
                    if ($csvIndex === '') continue; // No CSV column selected for this taxonomy

                    $columnIndex = (int)$csvIndex;
                    if (!isset($row[$columnIndex])) {
                        continue;
                    }
                    
                    $termNamesRaw = $row[$columnIndex];
                    $termNamesList = [];
                    if (is_string($termNamesRaw)) {
                        $termNamesList = explode(',', $termNamesRaw);
                    } elseif (is_numeric($termNamesRaw)) { // Handle if a single numeric value is not treated as string by explode
                        $termNamesList = [(string)$termNamesRaw];
                    }
                    
                    $processedTermIds = [];

                    foreach ($termNamesList as $termNameSingle) {
                        $termNameSingle = trim($termNameSingle); // Trim whitespace
                        if (empty($termNameSingle)) {
                            continue;
                        }

                        // UNCOMMENT FOR DEEP DEBUGGING:
                        // error_log("Porygon Import - Processing term: '{$termNameSingle}' in taxonomy '{$taxonomy}' for post '{$postData['post_title']}'");

                        $resolved_term_id = null;
                        
                        // 1. Try term_exists()
                        $existing_term_data = term_exists($termNameSingle, $taxonomy);
                        // UNCOMMENT FOR DEEP DEBUGGING:
                        // error_log("Porygon Import - term_exists('{$termNameSingle}', '{$taxonomy}') returned: " . print_r($existing_term_data, true));

                        if ($existing_term_data) {
                            if (is_array($existing_term_data)) {
                                $resolved_term_id = (int)$existing_term_data['term_id'];
                            } else {
                                $resolved_term_id = (int)$existing_term_data; // Should be term_id
                            }
                            // UNCOMMENT FOR DEEP DEBUGGING:
                            // error_log("481 Porygon Import - Found via term_exists. ID: {$resolved_term_id}");
                        } else {
                            // 2. If term_exists failed, try get_term_by 'name'
                            $term_object_by_name = get_term_by('name', $termNameSingle, $taxonomy);
                            // UNCOMMENT FOR DEEP DEBUGGING:
                            // error_log("486 Porygon Import - get_term_by('name', '{$termNameSingle}', '{$taxonomy}') returned: " . print_r($term_object_by_name, true));

                            if ($term_object_by_name instanceof \WP_Term) {
                                $resolved_term_id = (int)$term_object_by_name->term_id;
                                // UNCOMMENT FOR DEEP DEBUGGING:
                                // error_log("491 Porygon Import - Found via get_term_by('name'). ID: {$resolved_term_id}");
                            } else {
                                // 3. If that failed, try get_term_by 'slug'
                                // For numeric names like "15", the slug is often also "15"
                                $slug_to_check = sanitize_title($termNameSingle);
                                $term_object_by_slug = get_term_by('slug', $slug_to_check, $taxonomy);
                                // UNCOMMENT FOR DEEP DEBUGGING:
                                // error_log("498 Porygon Import - get_term_by('slug', '{$slug_to_check}', '{$taxonomy}') returned: " . print_r($term_object_by_slug, true));
                                
                                if ($term_object_by_slug instanceof \WP_Term) {
                                    $resolved_term_id = (int)$term_object_by_slug->term_id;
                                    // UNCOMMENT FOR DEEP DEBUGGING:
                                    // error_log("503 Porygon Import - Found via get_term_by('slug'). ID: {$resolved_term_id}");
                                }
                            }
                        }

                        // If term was found by any method above:
                        if ($resolved_term_id && $resolved_term_id > 0) {
                            // UNCOMMENT FOR DEEP DEBUGGING:
                            // error_log("511 Porygon Import - Existing term resolved. ID: {$resolved_term_id} for '{$termNameSingle}'");
                        } else {
                            // Term still not found by any lookup method, attempt to create if allowed
                            if (isset($createTerms[$taxonomy]) && $createTerms[$taxonomy] == '1') {
                                // UNCOMMENT FOR DEEP DEBUGGING:
                                // error_log("Porygon Import - Term '{$termNameSingle}' not found by any method. Attempting to create in '{$taxonomy}'. CreateTerms flag: " . $createTerms[$taxonomy]);
                                
                                $new_term_result = wp_insert_term($termNameSingle, $taxonomy);
                                
                                // UNCOMMENT FOR DEEP DEBUGGING:
                                // if (is_wp_error($new_term_result)) {
                                //     error_log("Porygon Import - wp_insert_term for '{$termNameSingle}' FAILED. Error code: " . $new_term_result->get_error_code() . ", Message: " . $new_term_result->get_error_message());
                                // } else {
                                //     error_log("Porygon Import - wp_insert_term for '{$termNameSingle}' SUCCEEDED. Result: " . print_r($new_term_result, true));
                                // }

                                if (!is_wp_error($new_term_result) && is_array($new_term_result) && isset($new_term_result['term_id'])) {
                                    $resolved_term_id = (int)$new_term_result['term_id'];
                                } else {
                                     // UNCOMMENT FOR DEEP DEBUGGING:
                                     // error_log("Porygon Import - Term creation failed for '{$termNameSingle}' or result was unexpected even if not WP_Error.");
                                }
                            } else {
                                // UNCOMMENT FOR DEEP DEBUGGING:
                                // error_log("Porygon Import - Term '{$termNameSingle}' not found, and creation not enabled for taxonomy '{$taxonomy}'.");
                            }
                        }

                        if ($resolved_term_id && $resolved_term_id > 0) {
                            if (!in_array($resolved_term_id, $processedTermIds)) {
                                $processedTermIds[] = $resolved_term_id;
                            }
                        }
                    } // End foreach $termNameSingle

                    if (!empty($processedTermIds)) {
                        $set_terms_result = wp_set_object_terms($postId, $processedTermIds, $taxonomy, false); 
                        // UNCOMMENT FOR DEEP DEBUGGING:
                        if (is_wp_error($set_terms_result)) {
                            // error_log("Porygon Import - wp_set_object_terms FAILED for post {$postId}, taxonomy '{$taxonomy}'. Error: " . $set_terms_result->get_error_message());
                        } else {
                            // error_log("Porygon Import - wp_set_object_terms SUCCEEDED for post {$postId}, taxonomy '{$taxonomy}'. Terms: " . implode(',', $processedTermIds));
                        }
                    }
                } // End foreach $taxonomyMap

                $insertCount++;
                $results[] = [
                    'row' => $rowIndex + 2,
                    'status' => 'success',
                    'post_id' => $postId,
                    'title' => $postData['post_title']
                ];
            }
            
            // If we got this far with no exceptions, commit the transaction
            $wpdb->query('COMMIT');
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Import completed: %d posts created successfully.', 'porygon-plugin'),
                    $insertCount
                ),
                'success_count' => $insertCount,
                'error_count' => 0,
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            // If any error occurred, rollback the transaction
            $wpdb->query('ROLLBACK');
            
            wp_send_json_error([
                'message' => __('Error during import. No data was imported.', 'porygon-plugin'),
                'error' => $e->getMessage()
            ]);
        }
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
