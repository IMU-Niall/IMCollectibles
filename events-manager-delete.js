jQuery(document).ready(function($) {
    console.log('events-manager-delete.js loaded');
    $('.delete-event').on('click', function(e) {
        e.preventDefault();
        const eventId = $(this).data('event-id');
        const href = $(this).attr('href');
        console.log('Delete event clicked, eventId:', eventId, 'href:', href);

        if (!confirm('Are you sure you want to delete this event?')) {
            console.log('Deletion cancelled by user');
            return;
        }

        window.location.href = href;
    });
});