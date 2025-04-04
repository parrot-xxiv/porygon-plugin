jQuery(document).ready(function($) {
    const ajaxUrl = porygon_bulkedit.ajax_url;
    const nonce = porygon_bulkedit.nonce;
    let selectedPostType = '';
    let currentPosts = [];
    let taxonomyOptions = {};
    let allColumns = {};
    let visibleColumns = ['ID', 'post_title', 'post_content']; // Default visible columns

    // Post type selection handling
    $('#post-type-selector').on('change', function() {
        selectedPostType = $(this).val();
        
        if (selectedPostType) {
            loadPosts(selectedPostType);
            $('.post-search-area').show();
        } else {
            $('.post-search-area, .bulk-edit-container').hide();
        }
    });

    // Load posts of selected type
    function loadPosts(postType) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_posts_for_bulk',
                post_type: postType,
                nonce: nonce
            },
            beforeSend: function() {
                showNotification('Loading posts...', 'info');
            },
            success: function(response) {
                if (response.success) {
                    currentPosts = response.data.posts;
                    taxonomyOptions = response.data.taxonomies || {};
                    allColumns = response.data.columns;
                    
                    // Set default visible columns - only ID, title and content
                    visibleColumns = ['ID', 'post_title', 'post_content'];
                    
                    // Generate column visibility controls
                    generateColumnControls(allColumns);
                    
                    // Generate table headers
                    generateTableHeaders();
                    
                    // Populate table with posts
                    populateTable(currentPosts);
                    
                    // Show the bulk edit container
                    $('.bulk-edit-container').show();
                    
                    hideNotification();
                } else {
                    showNotification(response.data.message || 'Error loading posts', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response:', xhr.responseText);
                showNotification('Server error occurred while loading posts', 'error');
            }
        });
    }

    // Generate column visibility controls
    function generateColumnControls(columns) {
        let controlsHtml = '<div class="column-visibility-controls">';
        controlsHtml += '<h4>Show/Hide Columns</h4>';
        controlsHtml += '<div class="column-checkboxes">';
        
        // Add checkboxes for each column
        Object.keys(columns).forEach(function(key) {
            const isChecked = visibleColumns.includes(key) ? 'checked' : '';
            controlsHtml += `
                <div class="column-checkbox">
                    <input type="checkbox" id="col-${key}" data-column="${key}" ${isChecked}>
                    <label for="col-${key}">${columns[key]}</label>
                </div>
            `;
        });
        
        controlsHtml += '</div></div>';
        
        // Add column controls to the page
        if ($('.column-visibility-container').length === 0) {
            $('.bulk-edit-container').prepend('<div class="column-visibility-container"></div>');
        }
        
        $('.column-visibility-container').html(controlsHtml);
        
        // Add event listeners for column visibility toggles
        $('.column-checkbox input').on('change', function() {
            const columnKey = $(this).data('column');
            
            if ($(this).is(':checked')) {
                // Add column to visible columns if not already there
                if (!visibleColumns.includes(columnKey)) {
                    visibleColumns.push(columnKey);
                }
            } else {
                // Remove column from visible columns
                visibleColumns = visibleColumns.filter(key => key !== columnKey);
            }
            
            // Regenerate table with updated visible columns
            generateTableHeaders();
            populateTable(currentPosts);
        });
    }

    // Generate table headers
    function generateTableHeaders() {
        let headerHtml = '<tr>';
        headerHtml += '<th>Actions</th>';
        
        // Add only visible columns
        visibleColumns.forEach(function(key) {
            if (allColumns[key]) {
                headerHtml += `<th data-key="${key}">${allColumns[key]}</th>`;
            }
        });
        
        headerHtml += '</tr>';
        $('#bulk-edit-table-head').html(headerHtml);
    }

    // Populate table with posts data
    function populateTable(posts) {
        if (!posts || posts.length === 0) {
            $('#bulk-edit-table-body').html('<tr><td colspan="' + (visibleColumns.length + 1) + '">No posts found</td></tr>');
            return;
        }
        
        let tableHtml = '';
        posts.forEach(function(post) {
            tableHtml += `<tr data-post-id="${post.ID}">`;
            tableHtml += `<td><button class="button remove-post">Remove</button></td>`;
            
            // Add only visible columns
            visibleColumns.forEach(function(key) {
                const value = post[key] || '';
                tableHtml += '<td>';
                
                // Create different input types based on column
                if (key === 'ID') {
                    tableHtml += `<span>${value}</span>`;
                } else if (key === 'post_content') {
                    tableHtml += `<textarea name="${key}" rows="3">${value}</textarea>`;
                } else if (key.startsWith('tax_')) {
                    const taxName = key.substring(4);
                    if (taxonomyOptions[taxName]) {
                        tableHtml += buildTaxonomySelector(taxName, value);
                    } else {
                        tableHtml += `<input type="text" name="${key}" value="${value}">`;
                    }
                } else if (key.startsWith('meta_')) {
                    const metaKey = key.substring(5);
                    tableHtml += `
                        <div class="meta-field">
                            <input type="text" name="${key}" value="${value}">
                            <button class="button delete-meta" data-meta-key="${metaKey}">Delete</button>
                        </div>
                    `;
                } else {
                    tableHtml += `<input type="text" name="${key}" value="${value}">`;
                }
                
                tableHtml += '</td>';
            });
            
            tableHtml += '</tr>';
        });
        
        $('#bulk-edit-table-body').html(tableHtml);
        
        // Add event listeners for remove and delete meta buttons
        $('.remove-post').on('click', removePostFromTable);
        $('.delete-meta').on('click', deleteMetaField);
    }

    // Build taxonomy selector
    function buildTaxonomySelector(taxName, currentValue) {
        const values = currentValue ? currentValue.split(',').map(term => term.trim()) : [];
        let options = taxonomyOptions[taxName];
        let html = `<input type="text" name="tax_${taxName}" value="${currentValue}" class="taxonomy-input">`;
        html += `<div class="taxonomy-selector" style="display:none;">`;
        
        options.forEach(function(option) {
            const checked = values.includes(option.name) ? 'checked' : '';
            html += `
                <div class="taxonomy-option">
                    <input type="checkbox" id="tax_${taxName}_${option.id}" value="${option.name}" ${checked}>
                    <label for="tax_${taxName}_${option.id}">${option.name}</label>
                </div>
            `;
        });
        
        html += `</div><button class="button show-taxonomy-selector">Select</button>`;
        return html;
    }

    // Handle showing taxonomy selector
    $(document).on('click', '.show-taxonomy-selector', function(e) {
        e.preventDefault();
        $(this).prev('.taxonomy-selector').toggle();
    });

    // Handle taxonomy selection
    $(document).on('change', '.taxonomy-selector input[type="checkbox"]', function() {
        const container = $(this).closest('td');
        const checkboxes = container.find('.taxonomy-selector input[type="checkbox"]:checked');
        const values = [];
        
        checkboxes.each(function() {
            values.push($(this).val());
        });
        
        container.find('.taxonomy-input').val(values.join(', '));
    });

    // Remove post from table
    function removePostFromTable() {
        $(this).closest('tr').remove();
    }

    // Delete meta field
    function deleteMetaField() {
        const btn = $(this);
        const row = btn.closest('tr');
        const postId = row.data('post-id');
        const metaKey = btn.data('meta-key');
        
        if (confirm(`Are you sure you want to delete the meta field "${metaKey}" from this post?`)) {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'delete_post_meta_for_bulkedit',
                    post_id: postId,
                    meta_key: metaKey,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        btn.closest('.meta-field').find('input').val('');
                        showNotification(response.data.message, 'success');
                    } else {
                        showNotification(response.data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Status:', status);
                    console.log('Error:', error);
                    console.log('Response:', xhr.responseText);
                    showNotification('Server error occurred', 'error');
                }
            });
        }
    }

    // Search for posts
    $('#post-search-button').on('click', function() {
        const searchTerm = $('#post-search-input').val().trim();
        
        if (!searchTerm) {
            showNotification('Please enter a search term', 'error');
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'search_posts',
                search: searchTerm,
                post_type: selectedPostType,
                nonce: nonce
            },
            beforeSend: function() {
                showNotification('Searching...', 'info');
            },
            success: function(response) {
                if (response.success && response.data.posts.length > 0) {
                    displaySearchResults(response.data.posts);
                    hideNotification();
                } else {
                    showNotification('No posts found', 'info');
                    $('.search-results').html('');
                }
            },
            error: function(xhr, status, error) {
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response:', xhr.responseText);
                showNotification('Server error occurred during search', 'error');
            }
        });
    });

    // Display search results
    function displaySearchResults(posts) {
        let html = '<ul class="search-results-list">';
        
        posts.forEach(function(post) {
            html += `
                <li>
                    <span>${post.post_title} (ID: ${post.ID}) - ${post.post_date}</span>
                    <button class="button add-to-table" data-post-id="${post.ID}">Add</button>
                </li>
            `;
        });
        
        html += '</ul>';
        $('.search-results').html(html);
        
        // Add event listener to Add buttons
        $('.add-to-table').on('click', function() {
            const postId = $(this).data('post-id');
            addPostToTable(postId);
        });
    }

    // Add post to table
    function addPostToTable(postId) {
        // First check if post is already in the table
        if ($(`#bulk-edit-table-body tr[data-post-id="${postId}"]`).length > 0) {
            showNotification('Post is already in the table', 'info');
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_posts',
                post_type: selectedPostType,
                post_id: postId,
                nonce: nonce
            },
            beforeSend: function() {
                showNotification('Adding post...', 'info');
            },
            success: function(response) {
                if (response.success && response.data.posts.length > 0) {
                    // Get existing posts and add the new one
                    const existingPosts = [];
                    $('#bulk-edit-table-body tr').each(function() {
                        existingPosts.push(parseInt($(this).data('post-id')));
                    });
                    
                    // Only add posts that aren't already in the table
                    const newPosts = response.data.posts.filter(post => !existingPosts.includes(parseInt(post.ID)));
                    
                    if (newPosts.length > 0) {
                        // Append to table
                        populateTable(newPosts);
                        showNotification('Post added to table', 'success');
                    } else {
                        showNotification('Post is already in the table', 'info');
                    }
                } else {
                    showNotification('Could not retrieve post data', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response:', xhr.responseText);
                showNotification('Server error occurred', 'error');
            }
        });
    }

    // Save all changes
    $('#save-all-changes').on('click', function() {
        const postsData = [];
        
        // Collect data from the table
        $('#bulk-edit-table-body tr').each(function() {
            const row = $(this);
            const postId = row.data('post-id');
            const postData = { ID: postId };
            
            // Get all input values
            row.find('input, textarea').each(function() {
                const input = $(this);
                const name = input.attr('name');
                
                if (name) {
                    postData[name] = input.val();
                }
            });
            
            postsData.push(postData);
        });
        
        if (postsData.length === 0) {
            showNotification('No posts to update', 'error');
            return;
        }
        
        // Send data to server
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'update_posts',
                posts_data: postsData,
                nonce: nonce
            },
            beforeSend: function() {
                showNotification('Saving changes...', 'info');
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    
                    if (response.data.errors && response.data.errors.length > 0) {
                        // Display errors if any
                        let errorHtml = '<ul class="errors-list">';
                        response.data.errors.forEach(function(error) {
                            errorHtml += `<li>${error}</li>`;
                        });
                        errorHtml += '</ul>';
                        
                        $('.porygon-notification-area').append(errorHtml);
                    }
                } else {
                    showNotification(response.data.message || 'Error saving changes', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response:', xhr.responseText);
                showNotification('Server error occurred while saving', 'error');
            }
        });
    });

    // Reset changes
    $('#reset-changes').on('click', function() {
        if (selectedPostType) {
            if (confirm('Are you sure you want to reset all changes?')) {
                loadPosts(selectedPostType);
            }
        }
    });

    // Notification functions
    function showNotification(message, type = 'info') {
        const notificationArea = $('.porygon-notification-area');
        notificationArea.html(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
        notificationArea.show();
        
        // Auto-hide success messages after 3 seconds
        if (type === 'success') {
            setTimeout(function() {
                notificationArea.fadeOut();
            }, 3000);
        }
    }

    function hideNotification() {
        $('.porygon-notification-area').hide();
    }
});