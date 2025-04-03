jQuery(document).ready(function($) {
    // Variables to track changes
    let originalMetaData = [];
    let currentPostId = 0;
    
    // Show success notification
    function showSuccess(message) {
        $('#porygon-notice-success p').text(message);
        $('#porygon-notice-success').fadeIn();
        setTimeout(function() {
            $('#porygon-notice-success').fadeOut();
        }, 3000);
    }
    
    // Show error notification
    function showError(message) {
        $('#porygon-notice-error p').text(message);
        $('#porygon-notice-error').fadeIn();
        setTimeout(function() {
            $('#porygon-notice-error').fadeOut();
        }, 3000);
    }
    
    // Handle post type selection
    $('#post-type-selector').on('change', function() {
        const postType = $(this).val();
        
        if (!postType) {
            $('#posts-selector-container').hide();
            $('#post-meta-container').hide();
            return;
        }
        
        // Show loading state
        $('#posts-selector').html('<option>Loading posts...</option>');
        $('#posts-selector-container').show();
        
        // Get posts of selected type
        $.ajax({
            url: porygon_meta.ajax_url,
            type: 'POST',
            data: {
                action: 'get_posts',
                nonce: porygon_meta.nonce,
                post_type: postType
            },
            success: function(response) {
                if (response.success) {
                    const posts = response.data.posts;
                    let options = '<option value="">-- Select Post --</option>';
                    
                    posts.forEach(function(post) {
                        options += `<option value="${post.id}">${post.title}</option>`;
                    });
                    
                    $('#posts-selector').html(options);
                } else {
                    showError(response.data.message || 'Error loading posts');
                    $('#posts-selector').html('<option value="">-- Select Post --</option>');
                }
            },
            error: function() {
                showError('Network error when loading posts');
                $('#posts-selector').html('<option value="">-- Select Post --</option>');
            }
        });
    });
    
    // Handle post selection
    $('#posts-selector').on('change', function() {
        const postId = $(this).val();
        currentPostId = postId;
        
        if (!postId) {
            $('#post-meta-container').hide();
            return;
        }
        
        // Show loading state
        $('#post-meta-list').html('<tr><td colspan="3">Loading meta data...</td></tr>');
        $('#post-meta-container').show();
        
        // Get post meta
        $.ajax({
            url: porygon_meta.ajax_url,
            type: 'POST',
            data: {
                action: 'get_post_meta',
                nonce: porygon_meta.nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    const meta = response.data.meta;
                    originalMetaData = [...meta]; // Store original data for reset
                    
                    $('#selected-post-title').text(response.data.post_title);
                    renderMetaTable(meta);
                } else {
                    showError(response.data.message || 'Error loading meta data');
                    $('#post-meta-list').html('<tr><td colspan="3">No meta data found</td></tr>');
                }
            },
            error: function() {
                showError('Network error when loading meta data');
                $('#post-meta-list').html('<tr><td colspan="3">Error loading meta data</td></tr>');
            }
        });
    });
    
    // Render meta table
    function renderMetaTable(meta) {
        if (meta.length === 0) {
            $('#post-meta-list').html('<tr><td colspan="3">No meta data found</td></tr>');
            return;
        }
        
        let rows = '';
        
        meta.forEach(function(item, index) {
            rows += `
                <tr data-index="${index}">
                    <td>
                        <input type="text" class="meta-key regular-text" value="${escapeHtml(item.key)}" data-original="${escapeHtml(item.key)}">
                    </td>
                    <td>
                        <input type="text" class="meta-value regular-text" value="${escapeHtml(item.value)}">
                    </td>
                    <td>
                        <button type="button" class="button button-link-delete delete-meta-btn">Delete</button>
                    </td>
                </tr>
            `;
        });
        
        $('#post-meta-list').html(rows);
    }
    
    // Helper function to escape HTML
    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
    // Handle delete meta
    $(document).on('click', '.delete-meta-btn', function() {
        const row = $(this).closest('tr');
        const key = row.find('.meta-key').data('original');
        
        if (!currentPostId) {
            showError('No post selected');
            return;
        }
        
        if (confirm('Are you sure you want to delete this meta?')) {
            $.ajax({
                url: porygon_meta.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_post_meta',
                    nonce: porygon_meta.nonce,
                    post_id: currentPostId,
                    meta_key: key
                },
                success: function(response) {
                    if (response.success) {
                        row.fadeOut(300, function() {
                            $(this).remove();
                            showSuccess(response.data.message || 'Meta deleted successfully');
                            
                            // If no rows left, show empty message
                            if ($('#post-meta-list tr').length === 0) {
                                $('#post-meta-list').html('<tr><td colspan="3">No meta data found</td></tr>');
                            }
                        });
                    } else {
                        showError(response.data.message || 'Error deleting meta');
                    }
                },
                error: function() {
                    showError('Network error when deleting meta');
                }
            });
        }
    });
    
    // Handle add new meta
    $('#add-meta-btn').on('click', function() {
        const newKey = $('#new-meta-key').val().trim();
        const newValue = $('#new-meta-value').val().trim();
        
        if (!newKey) {
            showError('Meta key is required');
            return;
        }
        
        if (!currentPostId) {
            showError('No post selected');
            return;
        }
        
        // If table shows "No meta data found", clear it
        if ($('#post-meta-list td').length === 1 && $('#post-meta-list td').text() === 'No meta data found') {
            $('#post-meta-list').empty();
        }
        
        // Add new row to table
        const newRow = `
            <tr data-new="true">
                <td>
                    <input type="text" class="meta-key regular-text" value="${escapeHtml(newKey)}" data-original="">
                </td>
                <td>
                    <input type="text" class="meta-value regular-text" value="${escapeHtml(newValue)}">
                </td>
                <td>
                    <button type="button" class="button button-link-delete delete-meta-btn">Delete</button>
                </td>
            </tr>
        `;
        
        $('#post-meta-list').append(newRow);
        
        // Clear input fields
        $('#new-meta-key').val('');
        $('#new-meta-value').val('');
    });
    
    // Handle save changes
    $('#save-meta-btn').on('click', function() {
        if (!currentPostId) {
            showError('No post selected');
            return;
        }
        
        const metaData = [];
        
        // Collect all meta data from table
        $('#post-meta-list tr').each(function() {
            const key = $(this).find('.meta-key').val().trim();
            const value = $(this).find('.meta-value').val().trim();
            const originalKey = $(this).find('.meta-key').data('original');
            
            if (key) {
                metaData.push({
                    key: key,
                    value: value,
                    old_key: originalKey
                });
            }
        });
        
        // Save meta data
        $.ajax({
            url: porygon_meta.ajax_url,
            type: 'POST',
            data: {
                action: 'save_post_meta',
                nonce: porygon_meta.nonce,
                post_id: currentPostId,
                meta_data: metaData
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message || 'Meta data saved successfully');
                    
                    // Refresh meta data display
                    $('#posts-selector').trigger('change');
                } else {
                    showError(response.data.message || 'Error saving meta data');
                }
            },
            error: function() {
                showError('Network error when saving meta data');
            }
        });
    });
    
    // Handle reset changes
    $('#reset-meta-btn').on('click', function() {
        if (confirm('Are you sure you want to reset all changes?')) {
            renderMetaTable(originalMetaData);
            showSuccess('Changes reset successfully');
        }
    });
});