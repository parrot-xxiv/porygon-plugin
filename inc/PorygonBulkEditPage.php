<?php

namespace Eren\Porygon;

class PorygonBulkEditPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_ajax_get_posts_for_bulk', [$this, 'getPosts']);
        add_action('wp_ajax_update_posts', [$this, 'updatePosts']);
        add_action('wp_ajax_delete_post_meta_for_bulkedit', [$this, 'deletePostMeta']);
        add_action('wp_ajax_search_posts', [$this, 'searchPosts']);
    }

    public function addMenuPage()
    {
        add_menu_page(
            'Bulk Edit Page',
            'Bulk Edit',
            'manage_options',
            'bulkedit-page',
            [$this, 'renderPage'],
            'dashicons-admin-plugins',
            6
        );
    }

    public function enqueueScripts($hook)
    {
        if ($hook != 'toplevel_page_bulkedit-page') {
            return;
        }
        wp_enqueue_style('porygon-bulkedit-css', plugin_dir_url(__FILE__) . '../css/bulkedit.css', [], '1.0.0');

        wp_enqueue_script('porygon-bulkedit-js', plugin_dir_url(__FILE__) . '../js/bulkedit.js', ['jquery'], '1.0.0', true);

        wp_localize_script('porygon-bulkedit-js', 'porygon_bulkedit', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('porygon_bulkedit_nonce'),
        ]);
    }

    public function renderPage()
    {
        // Get all available post types
        $post_types = get_post_types(['public' => true], 'objects');
?>
        <div class="wrap porygon-bulkedit">
            <h1>Bulk Edit</h1>

            <div class="porygon-notification-area"></div>

            <div class="post-type-selection">
                <label for="post-type-selector">Select Post Type:</label>
                <select id="post-type-selector">
                    <option value="">-- Select Post Type --</option>
                    <?php foreach ($post_types as $post_type): ?>
                        <option value="<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="post-search-area" style="display: none;">
                <h3>Search and Add Posts</h3>
                <div class="search-box">
                    <input type="text" id="post-search-input" placeholder="Search posts...">
                    <button id="post-search-button" class="button">Search</button>
                </div>
                <div class="search-results"></div>
            </div>

            <div class="bulk-edit-container" style="display: none;">
                <h3>Edit Selected Posts</h3>
                <div class="bulk-actions">
                    <button id="save-all-changes" class="button button-primary">Save All Changes</button>
                    <button id="reset-changes" class="button">Reset Changes</button>
                </div>
                <div class="posts-table-container">
                    <table id="bulk-edit-table" class="wp-list-table widefat fixed striped">
                        <thead id="bulk-edit-table-head"></thead>
                        <tbody id="bulk-edit-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
<?php
    }

    public function getPosts()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_bulkedit_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $specific_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        // Query to get either a specific post or the latest 10 posts
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => $specific_post_id ? 1 : 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'any'
        ];

        // If specific post ID is provided, get only that post
        if ($specific_post_id) {
            $args['p'] = $specific_post_id;
        }

        $posts = get_posts($args);
        $result = [];

        if (empty($posts)) {
            wp_send_json_success(['posts' => [], 'columns' => []]);
            return;
        }

        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        // Prepare column headers
        $columns = [
            'ID' => 'ID',
            'post_title' => 'Title',
            'post_content' => 'Content'
        ];

        // Add taxonomy columns
        foreach ($taxonomies as $tax) {
            $columns['tax_' . $tax->name] = $tax->labels->name;
        }

        // Process each post
        foreach ($posts as $post) {
            $post_data = [
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content
            ];

            // Add taxonomy values
            foreach ($taxonomies as $tax) {
                $terms = wp_get_post_terms($post->ID, $tax->name, ['fields' => 'names']);
                $post_data['tax_' . $tax->name] = !empty($terms) ? implode(', ', $terms) : '';
            }

            // Get post meta
            $meta_keys = get_post_custom_keys($post->ID);
            if ($meta_keys) {
                foreach ($meta_keys as $key) {
                    // Skip internal WordPress meta
                    // if (substr($key, 0, 1) === '_') {
                    //     continue;
                    // }

                    $values = get_post_meta($post->ID, $key, false);
                    $post_data['meta_' . $key] = is_array($values) && count($values) === 1 ? $values[0] : json_encode($values);

                    // Add this meta key to columns if not already there
                    if (!isset($columns['meta_' . $key])) {
                        $columns['meta_' . $key] = 'Meta: ' . $key;
                    }
                }
            }

            $result[] = $post_data;
        }

        wp_send_json_success([
            'posts' => $result,
            'columns' => $columns,
            'taxonomies' => $this->getTaxonomyOptions($taxonomies, $post_type)
        ]);
    }
    private function getTaxonomyOptions($taxonomies, $post_type)
    {
        $taxonomy_options = [];

        foreach ($taxonomies as $tax) {
            $terms = get_terms([
                'taxonomy' => $tax->name,
                'hide_empty' => false,
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                $options = [];
                foreach ($terms as $term) {
                    $options[] = [
                        'id' => $term->term_id,
                        'name' => $term->name
                    ];
                }
                $taxonomy_options[$tax->name] = $options;
            }
        }

        return $taxonomy_options;
    }

    public function searchPosts()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_bulkedit_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';

        if (empty($search_term)) {
            wp_send_json_error(['message' => 'Search term is required']);
            return;
        }

        $args = [
            'post_type' => $post_type,
            'posts_per_page' => 20,
            's' => $search_term,
        ];

        $query = new \WP_Query($args);
        $results = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $results[] = [
                    'ID' => get_the_ID(),
                    'post_title' => get_the_title(),
                    'post_date' => get_the_date()
                ];
            }
            wp_reset_postdata();
        }

        wp_send_json_success(['posts' => $results]);
    }

    public function updatePosts()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_bulkedit_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $posts_data = isset($_POST['posts_data']) ? $_POST['posts_data'] : [];

        if (empty($posts_data) || !is_array($posts_data)) {
            wp_send_json_error(['message' => 'No data to update']);
            return;
        }

        $updated = 0;
        $errors = [];

        foreach ($posts_data as $post_data) {
            $post_id = isset($post_data['ID']) ? absint($post_data['ID']) : 0;

            if (!$post_id) {
                continue;
            }

            // Update post data
            $post_update = [];

            if (isset($post_data['post_title'])) {
                $post_update['post_title'] = sanitize_text_field($post_data['post_title']);
            }

            if (isset($post_data['post_content'])) {
                $post_update['post_content'] = wp_kses_post($post_data['post_content']);
            }

            // Update the post if we have title or content changes
            if (!empty($post_update)) {
                $post_update['ID'] = $post_id;
                $result = wp_update_post($post_update, true);

                if (is_wp_error($result)) {
                    $errors[] = sprintf('Error updating post #%d: %s', $post_id, $result->get_error_message());
                    continue;
                }
            }

            // Update taxonomies
            foreach ($post_data as $key => $value) {
                if (strpos($key, 'tax_') === 0) {
                    $taxonomy = substr($key, 4);
                    $terms = array_map('trim', explode(',', sanitize_text_field($value)));

                    if (!empty($terms)) {
                        $result = wp_set_object_terms($post_id, $terms, $taxonomy);
                        if (is_wp_error($result)) {
                            $errors[] = sprintf('Error updating taxonomy %s for post #%d: %s', $taxonomy, $post_id, $result->get_error_message());
                        }
                    }
                }
            }

            // Update meta fields
            foreach ($post_data as $key => $value) {
                if (strpos($key, 'meta_') === 0) {
                    $meta_key = substr($key, 5);
                    $meta_value = sanitize_text_field($value);
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }

            $updated++;
        }

        wp_send_json_success([
            'message' => sprintf('%d posts updated successfully.', $updated),
            'updated' => $updated,
            'errors' => $errors
        ]);
    }

    public function deletePostMeta()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'porygon_bulkedit_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $meta_key = isset($_POST['meta_key']) ? sanitize_text_field($_POST['meta_key']) : '';

        if (!$post_id || empty($meta_key)) {
            wp_send_json_error(['message' => 'Invalid post ID or meta key']);
            return;
        }

        $result = delete_post_meta($post_id, $meta_key);

        if ($result) {
            wp_send_json_success(['message' => 'Meta field deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete meta field']);
        }
    }
}
