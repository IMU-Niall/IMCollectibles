jQuery(document).ready(function($) {
    console.log('events-manager.js loaded');

    // Handle AJAX Remove Event button
    $('.remove-event-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const eventId = button.data('event-id');
        const nonce = button.data('nonce');

        console.log('Remove button clicked, eventId:', eventId, 'nonce:', nonce);

        if (!confirm('Are you sure you want to remove this event? This action cannot be undone.')) {
            console.log('Deletion cancelled by user');
            return;
        }

        button.prop('disabled', true).text('Removing...');

        $.ajax({
            url: eventsManagerAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'remove_event',
                event_id: eventId,
                nonce: nonce // Align with PHP handler
            },
            beforeSend: function() {
                console.log('Sending AJAX request to:', eventsManagerAjax.ajaxurl);
            },
            success: function(response) {
                console.log('Remove event response:', response);
                if (response.success) {
                    alert('Event removed successfully.');
                    button.closest('tr').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    console.error('Remove event failed:', response.data?.message || 'Unknown error');
                    alert('Failed to remove event: ' + (response.data?.message || 'Unknown error'));
                    button.prop('disabled', false).text('Remove Event');
                }
            },
            error: function(xhr, status, error) {
                console.error('Remove event AJAX error:', status, error, 'Response:', xhr.responseText);
                alert('Error removing event: ' + (xhr.responseJSON?.data?.message || error));
                button.prop('disabled', false).text('Remove Event');
            },
            complete: function() {
                console.log('Remove event AJAX complete');
            }
        });
    });

    // Handle GET-based Delete link
    $('.delete-event').on('click', function(e) {
        e.preventDefault();
        const eventId = $(this).data('event-id');
        const href = $(this).attr('href');
        console.log('Delete link clicked, eventId:', eventId, 'href:', href);

        if (!confirm('Are you sure you want to delete this event?')) {
            console.log('Deletion cancelled by user');
            return;
        }

        window.location.href = href;
    });

    // Handle status update
    $('.event-status').on('change', function() {
        const select = $(this);
        const eventId = select.data('id');
        const nonce = select.data('nonce');
        const status = select.val();

        console.log('Status change, eventId:', eventId, 'status:', status, 'nonce:', nonce);

        $.ajax({
            url: eventsManagerAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_event_status',
                id: eventId,
                status: status,
                _wpnonce: nonce
            },
            beforeSend: function() {
                console.log('Sending status update AJAX request');
            },
            success: function(response) {
                console.log('Status update response:', response);
                if (response.success) {
                    alert('Status updated successfully.');
                } else {
                    console.error('Status update failed:', response.data?.error || 'Unknown error');
                    alert('Failed to update status: ' + (response.data?.error || 'Unknown error'));
                    select.val(select.data('original-status')); // Revert on failure
                }
            },
            error: function(xhr, status, error) {
                console.error('Status update AJAX error:', status, error, 'Response:', xhr.responseText);
                alert('Error updating status: ' + (xhr.responseJSON?.data?.error || error));
                select.val(select.data('original-status')); // Revert on failure
            }
        });
    });
});
