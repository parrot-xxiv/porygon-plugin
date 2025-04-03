<?php

namespace Eren\Porygon;

class PorygonMetaPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_ajax_get_posts', [$this, 'getPosts']);
        add_action('wp_ajax_get_post_meta', [$this, 'getPostMeta']);
        add_action('wp_ajax_save_post_meta', [$this, 'savePostMeta']);
        add_action('wp_ajax_delete_post_meta', [$this, 'deletePostMeta']);
    }

    public function addMenuPage()
    {
        add_menu_page(
            'Meta Manager Page',
            'Meta Manager',
            'manage_options',
            'metamanager-page',
            [$this, 'renderPage'],
            'dashicons-admin-plugins',
            6
        );
    }

    public function enqueueScripts($hook)
    {
        if ($hook != 'toplevel_page_metamanager-page') {
            return;
        }

        wp_enqueue_style('porygon-meta-manager-css', plugin_dir_url(__FILE__) . '../css/meta-manager.css', [], '1.0.0');
        
        wp_enqueue_script('porygon-meta-manager-js', plugin_dir_url(__FILE__) . '../js/meta-manager.js', ['jquery'], '1.0.0', true);
        
        wp_localize_script('porygon-meta-manager-js', 'porygon_meta', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('porygon_meta_nonce'),
        ]);
    }

    public function renderPage() {
        ?>
        <div class="wrap porygon-meta-manager">
            <h1>Meta Manager</h1>
            
            <div class="notice notice-success is-dismissible" id="porygon-notice-success" style="display:none;">
                <p></p>
            </div>
            
            <div class="notice notice-error is-dismissible" id="porygon-notice-error" style="display:none;">
                <p></p>
            </div>
            
            <div class="porygon-meta-controls">
                <!-- Post Type Selector -->
                <div class="porygon-control-group">
                    <label for="post-type-selector">Select Post Type:</label>
                    <select id="post-type-selector">
                        <option value="">-- Select Post Type --</option>
                        <?php
                        $post_types = get_post_types(['public' => true], 'objects');
                        foreach ($post_types as $post_type) {
                            echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Posts Selector (loads via AJAX) -->
                <div class="porygon-control-group" id="posts-selector-container" style="display:none;">
                    <label for="posts-selector">Select Post:</label>
                    <select id="posts-selector">
                        <option value="">-- Select Post --</option>
                    </select>
                </div>
            </div>
            
            <!-- Meta Table (loads via AJAX) -->
            <div id="post-meta-container" style="display:none;">
                <h2>Post Meta for: <span id="selected-post-title"></span></h2>
                
                <table class="wp-list-table widefat fixed striped" id="post-meta-table">
                    <thead>
                        <tr>
                            <th width="40%">Meta Key</th>
                            <th width="50%">Meta Value</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="post-meta-list">
                        <!-- Meta rows will be loaded here via AJAX -->
                    </tbody>
                    <tfoot>
                        <tr id="add-new-meta-row">
                            <td>
                                <input type="text" id="new-meta-key" placeholder="New meta key" class="regular-text">
                            </td>
                            <td>
                                <input type="text" id="new-meta-value" placeholder="New meta value" class="regular-text">
                            </td>
                            <td>
                                <button type="button" id="add-meta-btn" class="button button-secondary">Add</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="porygon-meta-actions">
                    <button type="button" id="save-meta-btn" class="button button-primary">Save Changes</button>
                    <button type="button" id="reset-meta-btn" class="button button-secondary">Reset Changes</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function getPosts()
    {
        check_ajax_referer('porygon_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        
        if (empty($post_type)) {
            wp_send_json_error(['message' => 'Post type is required']);
        }
        
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        
        $posts = get_posts($args);
        
        $formatted_posts = [];
        foreach ($posts as $post) {
            $formatted_posts[] = [
                'id' => $post->ID,
                'title' => sprintf('(ID:%d) %s', $post->ID, $post->post_title),
            ];
        }
        
        wp_send_json_success(['posts' => $formatted_posts]);
    }

    public function getPostMeta()
    {
        check_ajax_referer('porygon_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (empty($post_id)) {
            wp_send_json_error(['message' => 'Post ID is required']);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }
        
        $meta = get_post_meta($post_id);
        $formatted_meta = [];
        
        foreach ($meta as $key => $values) {
            foreach ($values as $value) {
                $formatted_meta[] = [
                    'key' => $key,
                    'value' => maybe_serialize($value),
                ];
            }
        }
        
        wp_send_json_success([
            'meta' => $formatted_meta,
            'post_title' => $post->post_title,
        ]);
    }

    public function savePostMeta()
    {
        check_ajax_referer('porygon_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $meta_data = isset($_POST['meta_data']) ? $_POST['meta_data'] : [];
        
        if (empty($post_id)) {
            wp_send_json_error(['message' => 'Post ID is required']);
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }
        
        // Get current meta
        $current_meta = get_post_meta($post_id);
        
        // Create arrays for operations
        $to_update = [];
        $keys_updated = [];
        
        // Process meta updates
        foreach ($meta_data as $item) {
            $key = sanitize_text_field($item['key']);
            $value = sanitize_text_field($item['value']);
            $old_key = isset($item['old_key']) ? sanitize_text_field($item['old_key']) : $key;
            
            // If key was changed, delete old one
            if ($key !== $old_key && isset($current_meta[$old_key])) {
                delete_post_meta($post_id, $old_key);
            }
            
            // Update meta value
            if (!isset($keys_updated[$key])) {
                delete_post_meta($post_id, $key); // Clear old values first
                $keys_updated[$key] = true;
            }
            
            $to_update[] = [
                'key' => $key,
                'value' => $value,
            ];
        }
        
        // Apply updates
        foreach ($to_update as $update) {
            add_post_meta($post_id, $update['key'], $update['value']);
        }
        
        wp_send_json_success(['message' => 'Post meta updated successfully']);
    }

    public function deletePostMeta()
    {
        check_ajax_referer('porygon_meta_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $meta_key = isset($_POST['meta_key']) ? sanitize_text_field($_POST['meta_key']) : '';
        
        if (empty($post_id) || empty($meta_key)) {
            wp_send_json_error(['message' => 'Post ID and meta key are required']);
        }
        
        $result = delete_post_meta($post_id, $meta_key);
        
        if ($result) {
            wp_send_json_success(['message' => 'Meta deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete meta']);
        }
    }
}