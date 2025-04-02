jQuery(document).ready(function($) {
    // Upload CSV form submission
    $('#porygon-csv-upload-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        var uploadButton = $('#upload-csv-button');
        var originalButtonText = uploadButton.html();
        
        // Show loading state
        uploadButton.html(porygon_vars.loading_text).prop('disabled', true);
        
        // Clear previous notices
        $('#porygon-notices').empty();
        
        $.ajax({
            url: porygon_vars.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    // Show success message
                    $('#porygon-notices').html(
                        '<div class="notice notice-success is-dismissible"><p>' + 
                        response.data.message + 
                        '</p></div>'
                    );
                    
                    // Show mapping form
                    $('#porygon-upload-form').hide();
                    $('#porygon-mapping-form').html(response.data.mapping_form).show();
                    
                    // Init mapping form submission
                    initMappingForm();
                    
                    // Init dynamic fields
                    initDynamicFields();
                } else {
                    // Show error message
                    $('#porygon-notices').html(
                        '<div class="notice notice-error is-dismissible"><p>' + 
                        porygon_vars.error_text + ' ' + response.data.message + 
                        '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                // Show error message
                $('#porygon-notices').html(
                    '<div class="notice notice-error is-dismissible"><p>' + 
                    porygon_vars.error_text + ' ' + error + 
                    '</p></div>'
                );
            },
            complete: function() {
                // Reset button state
                uploadButton.html(originalButtonText).prop('disabled', false);
            }
        });
    });
    
    // Function to initialize dynamic fields
    function initDynamicFields() {
        // Add event listener to "Add New Meta Field" button
        $('#add-new-meta-field').on('click', function() {
            var headers = JSON.parse($('#csv-headers').val());
            var tableBody = $('#field-mapping-table tbody');
            var fieldCount = tableBody.find('tr').length;
            var newRow = $('<tr class="custom-meta-field"></tr>');
            
            // Create field name input
            newRow.append(
                '<td>' +
                '<input type="text" name="custom_meta_name[]" placeholder="New Meta Field Name" required>' +
                '</td>'
            );
            
            // Create column selector
            var selectHtml = '<td>' +
                            '<select name="custom_meta_column[]" required>' +
                            '<option value="">- Select Column -</option>';
            
            // Add options from headers
            $.each(headers, function(index, header) {
                selectHtml += '<option value="' + index + '">' + header + '</option>';
            });
            
            selectHtml += '</select>' +
                          '<button type="button" class="button remove-meta-field">' +
                          'Remove</button>' +
                          '</td>';
            
            newRow.append(selectHtml);
            tableBody.append(newRow);
            
            // Add event listener to remove button
            newRow.find('.remove-meta-field').on('click', function() {
                $(this).closest('tr').remove();
            });
        });
        
        // Delegate event listener for dynamic remove buttons
        $('#field-mapping-table').on('click', '.remove-meta-field', function() {
            $(this).closest('tr').remove();
        });
    }
    
    // Function to initialize mapping form submission
    function initMappingForm() {
        $('#porygon-mapping-form-data').on('submit', function(e) {
            e.preventDefault();
            
            // Process custom meta fields before serializing
            var customMetaNames = $('input[name="custom_meta_name[]"]').map(function() {
                return $(this).val();
            }).get();
            
            var customMetaColumns = $('select[name="custom_meta_column[]"]').map(function() {
                return $(this).val();
            }).get();
            
            // Add custom meta fields to field_map
            for (var i = 0; i < customMetaNames.length; i++) {
                if (customMetaNames[i] && customMetaColumns[i] !== '') {
                    // Create hidden input for each custom field
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'field_map[' + customMetaNames[i] + ']',
                        value: customMetaColumns[i]
                    }).appendTo(this);
                }
            }
            
            var formData = $(this).serialize();
            var insertButton = $('#insert-data-button');
            var originalButtonText = insertButton.html();
            
            // Show loading state
            insertButton.html(porygon_vars.loading_text).prop('disabled', true);
            
            // Clear previous notices
            $('#porygon-notices').empty();
            
            $.ajax({
                url: porygon_vars.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('#porygon-notices').html(
                            '<div class="notice notice-success is-dismissible"><p>' + 
                            response.data.message + 
                            '</p></div>'
                        );
                        
                        // Show results table
                        showResultsTable(response.data.results);
                        
                        // Optionally add a button to start over
                        $('#porygon-mapping-form').append(
                            '<p><button type="button" id="start-over-button" class="button">' +
                            'Start New Import</button></p>'
                        );
                        
                        // Add event listener for start over button
                        $('#start-over-button').on('click', function() {
                            $('#porygon-mapping-form').hide().empty();
                            $('#porygon-upload-form').show();
                            $('#porygon-notices').empty();
                        });
                    } else {
                        // Show error message
                        $('#porygon-notices').html(
                            '<div class="notice notice-error is-dismissible"><p>' + 
                            porygon_vars.error_text + ' ' + response.data.message + 
                            '</p></div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    // Show error message
                    $('#porygon-notices').html(
                        '<div class="notice notice-error is-dismissible"><p>' + 
                        porygon_vars.error_text + ' ' + error + 
                        '</p></div>'
                    );
                },
                complete: function() {
                    // Reset button state
                    insertButton.html(originalButtonText).prop('disabled', false);
                }
            });
        });
    }
    
    // Function to display import results
    function showResultsTable(results) {
        if (!results || results.length === 0) {
            return;
        }
        
        var table = '<h3>Import Results</h3>' +
                    '<table class="widefat striped">' +
                    '<thead><tr>' +
                    '<th>Row</th>' +
                    '<th>Status</th>' +
                    '<th>Details</th>' +
                    '</tr></thead><tbody>';
        
        $.each(results, function(index, result) {
            var statusClass = result.status === 'success' ? 'success' : 'error';
            var details = result.status === 'success' ? 
                          'Created post: ' + result.title + ' (ID: ' + result.post_id + ')' : 
                          result.message;
            
            table += '<tr class="' + statusClass + '">' +
                     '<td>' + result.row + '</td>' +
                     '<td>' + result.status + '</td>' +
                     '<td>' + details + '</td>' +
                     '</tr>';
        });
        
        table += '</tbody></table>';
        
        $('#porygon-mapping-form').append(table);
    }
});