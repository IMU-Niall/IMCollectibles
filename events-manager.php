<?php
// File: events-manager.php (REVISED - Enhanced for Events and Live Events)
// Changes: Added hidden status, Unleashed/Decoded tags, multi-line title support, DaCast optimization, removed reaction counters

// Enqueue Scripts
add_action('admin_enqueue_scripts', 'events_manager_scripts');
function events_manager_scripts($hook) {
    if ($hook !== 'toplevel_page_events-manager' && $hook !== 'events-manager_page_events-manager-add') return;
    wp_enqueue_script('events-manager', get_template_directory_uri() . '/js/events-manager.js', ['jquery'], '1.2', true);
    wp_enqueue_media();
    wp_localize_script('events-manager', 'eventsManagerAjax', [
        'ajaxurl' => admin_url('admin-ajax.php')
    ]);
}

// Admin Menu (unchanged)
add_action('admin_menu', 'events_manager_menu');
function events_manager_menu() {
    add_menu_page(
        'Events Manager',
        'Events Manager',
        'manage_options',
        'events-manager',
        'events_manager_page',
        'dashicons-calendar-alt',
        20
    );
    add_submenu_page(
        'events-manager',
        'All Events',
        'All Events',
        'manage_options',
        'events-manager',
        'events_manager_page'
    );
    add_submenu_page(
        'events-manager',
        'Add New Event',
        'Add New',
        'manage_options',
        'events-manager-add',
        'events_manager_add_page'
    );
    add_submenu_page(
        'events-manager',
        'RSVPs',
        'RSVPs',
        'manage_options',
        'events-manager-rsvps',
        'events_manager_rsvps_page'
    );
}

// Tip Manager (unchanged)
add_action('admin_menu', 'register_tip_manager_menu');
function register_tip_manager_menu() {
    add_submenu_page(
        'events-manager',
        'Tip Manager',
        'Tip Manager',
        'manage_options',
        'events-manager-tips',
        'render_tip_manager_page'
    );
}

function render_tip_manager_page() {
    // Unchanged, keeping existing functionality
    global $wpdb;
    $tip_accounts_table = $wpdb->prefix . 'tip_accounts';
    $mappings_table = $wpdb->prefix . 'event_tip_mappings';
    $events_table = $wpdb->prefix . 'events';

    if (isset($_POST['action']) && check_admin_referer('tip_manager_nonce')) {
        if ($_POST['action'] === 'add_tip_account') {
            $name = sanitize_text_field($_POST['tip_name']);
            $xrp_address = sanitize_text_field($_POST['xrp_address']);
            if (!empty($name) && !empty($xrp_address) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrp_address)) {
                $wpdb->insert($tip_accounts_table, [
                    'name' => $name,
                    'xrp_address' => $xrp_address,
                    'created_at' => current_time('mysql')
                ], ['%s', '%s', '%s']);
                xaman_log("Added tip account: Name=$name, Address=$xrp_address");
                echo '<div class="notice notice-success is-dismissible"><p>Tip account added.</p></div>';
            } else {
                xaman_log("Invalid tip account data: Name=$name, Address=$xrp_address");
                echo '<div class="notice notice-error is-dismissible"><p>Invalid name or XRP address.</p></div>';
            }
        } elseif ($_POST['action'] === 'delete_tip_account') {
            $tip_account_id = absint($_POST['tip_account_id']);
            $wpdb->delete($tip_accounts_table, ['id' => $tip_account_id], ['%d']);
            $wpdb->delete($mappings_table, ['tip_account_id' => $tip_account_id], ['%d']);
            xaman_log("Deleted tip account ID=$tip_account_id");
            echo '<div class="notice notice-success is-dismissible"><p>Tip account deleted.</p></div>';
        } elseif ($_POST['action'] === 'assign_tip_account') {
            $event_id = absint($_POST['event_id']);
            $tip_account_id = absint($_POST['tip_account_id']);
            if ($event_id && $tip_account_id) {
                $wpdb->insert($mappings_table, [
                    'event_id' => $event_id,
                    'tip_account_id' => $tip_account_id,
                    'created_at' => current_time('mysql')
                ], ['%d', '%d', '%s']);
                xaman_log("Assigned tip account ID=$tip_account_id to Event ID=$event_id");
                echo '<div class="notice notice-success is-dismissible"><p>Tip account assigned to event.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Invalid event or tip account selection.</p></div>';
            }
        } elseif ($_POST['action'] === 'remove_tip_mapping') {
            $mapping_id = absint($_POST['mapping_id']);
            $wpdb->delete($mappings_table, ['id' => $mapping_id], ['%d']);
            xaman_log("Removed tip mapping ID=$mapping_id");
            echo '<div class="notice notice-success is-dismissible"><p>Tip account unassigned from event.</p></div>';
        }
    }

    $tip_accounts = $wpdb->get_results("SELECT * FROM $tip_accounts_table ORDER BY created_at DESC");
    $events = $wpdb->get_results("SELECT id, title FROM $events_table ORDER BY event_date DESC");
    $mappings = $wpdb->get_results("SELECT m.id, m.event_id, m.tip_account_id, e.title, t.name, t.xrp_address
                                    FROM $mappings_table m
                                    JOIN $events_table e ON m.event_id = e.id
                                    JOIN $tip_accounts_table t ON m.tip_account_id = t.id
                                    ORDER BY m.created_at DESC");

    ?>
    <div class="wrap">
        <h1>Tip Manager</h1>
        <h2>Add Tip Account</h2>
        <form method="post">
            <?php wp_nonce_field('tip_manager_nonce'); ?>
            <input type="hidden" name="action" value="add_tip_account">
            <table class="form-table">
                <tr>
                    <th><label for="tip_name">Name</label></th>
                    <td><input type="text" id="tip_name" name="tip_name" required class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="xrp_address">XRP Address</label></th>
                    <td><input type="text" id="xrp_address" name="xrp_address" required class="regular-text" pattern="r[1-9A-HJ-NP-Za-km-z]{25,34}"></td>
                </tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Add Tip Account"></p>
        </form>

        <h2>Manage Tip Accounts</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>XRP Address</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tip_accounts as $account): ?>
                    <tr>
                        <td><?php echo esc_html($account->name); ?></td>
                        <td><?php echo esc_html($account->xrp_address); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('tip_manager_nonce'); ?>
                                <input type="hidden" name="action" value="delete_tip_account">
                                <input type="hidden" name="tip_account_id" value="<?php echo esc_attr($account->id); ?>">
                                <input type="submit" class="button button-secondary" value="Delete" onclick="return confirm('Are you sure you want to delete this tip account?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Assign Tip Accounts to Events</h2>
        <form method="post">
            <?php wp_nonce_field('tip_manager_nonce'); ?>
            <input type="hidden" name="action" value="assign_tip_account">
            <table class="form-table">
                <tr>
                    <th><label for="event_id">Event</label></th>
                    <td>
                        <select id="event_id" name="event_id" required class="regular-text">
                            <option value="">Select Event</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo esc_attr($event->id); ?>"><?php echo esc_html($event->title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tip_account_id">Tip Account</label></th>
                    <td>
                        <select id="tip_account_id" name="tip_account_id" required class="regular-text">
                            <option value="">Select Tip Account</option>
                            <?php foreach ($tip_accounts as $account): ?>
                                <option value="<?php echo esc_attr($account->id); ?>"><?php echo esc_html($account->name); ?> (<?php echo esc_html($account->xrp_address); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Assign Tip Account"></p>
        </form>

        <h2>Event Tip Assignments</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Tip Account</th>
                    <th>XRP Address</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mappings as $mapping): ?>
                    <tr>
                        <td><?php echo esc_html($mapping->title); ?></td>
                        <td><?php echo esc_html($mapping->name); ?></td>
                        <td><?php echo esc_html($mapping->xrp_address); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('tip_manager_nonce'); ?>
                                <input type="hidden" name="action" value="remove_tip_mapping">
                                <input type="hidden" name="mapping_id" value="<?php echo esc_attr($mapping->id); ?>">
                                <input type="submit" class="button button-secondary" value="Remove" onclick="return confirm('Are you sure you want to remove this tip account from the event?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Main Events Page (List Table)
function events_manager_page() {
    global $wpdb;
    $table = new Events_Manager_List_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Events Manager</h1>
        <a href="<?php echo admin_url('admin.php?page=events-manager-add'); ?>" class="page-title-action">Add New</a>
        <?php if (isset($_GET['message']) && $_GET['message'] === 'deleted'): ?>
            <div class="notice notice-success is-dismissible"><p>Event deleted successfully.</p></div>
        <?php endif; ?>
        <form method="get">
            <input type="hidden" name="page" value="events-manager">
            <?php $table->display(); ?>
        </form>
        <script>
            jQuery(document).ready(function($) {
                console.log('Inline events-manager.js loaded');
                $('.remove-event-button').on('click', function(e) {
                    e.preventDefault();
                    const button = $(this);
                    const eventId = button.data('event-id');
                    const nonce = button.data('nonce');
                    console.log('Inline: Remove button clicked, eventId:', eventId, 'nonce:', nonce);
                    if (!confirm('Are you sure you want to remove this event?')) {
                        console.log('Inline: Deletion cancelled by user');
                        return;
                    }
                    button.prop('disabled', true).text('Removing...');
                    $.ajax({
                        url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'remove_event',
                            event_id: eventId,
                            _wpnonce: nonce
                        },
                        beforeSend: function() {
                            console.log('Inline: Sending AJAX request');
                        },
                        success: function(response) {
                            console.log('Inline: Remove event response:', response);
                            if (response.success) {
                                alert('Event removed successfully.');
                                button.closest('tr').fadeOut(300, function() {
                                    $(this).remove();
                                });
                            } else {
                                console.error('Inline: Remove event failed:', response.data?.message || 'Unknown error');
                                alert('Failed to remove event: ' + (response.data?.message || 'Unknown error'));
                                button.prop('disabled', false).text('Remove Event');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Inline: Remove event AJAX error:', status, error, 'Response:', xhr.responseText);
                            alert('Error removing event: ' + (xhr.responseJSON?.data?.message || error));
                            button.prop('disabled', false).text('Remove Event');
                        }
                    });
                });
                $('.delete-event').on('click', function(e) {
                    e.preventDefault();
                    const eventId = $(this).data('event-id');
                    const href = $(this).attr('href');
                    console.log('Inline: Delete link clicked, eventId:', eventId, 'href:', href);
                    if (!confirm('Are you sure you want to delete this event?')) {
                        console.log('Inline: Deletion cancelled by user');
                        return;
                    }
                    window.location.href = href;
                });
            });
        </script>
    </div>
    <?php
}

// Custom List Table
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class Events_Manager_List_Table extends WP_List_Table {
    function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'event_date' => 'Date',
            'status' => 'Status',
            'category' => 'Category', // Added category column
            'rsvp_count' => 'RSVPs',
            'recording_url' => 'Replay URL'
        ];
    }

    function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'events';
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Filters
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
        $where = [];
        if ($status) {
            $where[] = $wpdb->prepare('status = %s', $status);
        }
        if ($category) {
            $where[] = $wpdb->prepare('category = %s', $category);
        }
        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total items
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_clause");

        // Get items
        $query = "SELECT e.*, (SELECT COUNT(*) FROM {$wpdb->prefix}event_rsvps r WHERE r.event_id = e.id) as rsvp_count
                  FROM $table_name e $where_clause
                  ORDER BY event_date DESC
                  LIMIT %d OFFSET %d";
        $this->items = $wpdb->get_results($wpdb->prepare($query, $per_page, $offset));

        // Pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        // Columns
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'title':
                $actions = [
                    'edit' => sprintf('<a href="%s">Edit</a>', admin_url('admin.php?page=events-manager-add&id=' . $item->id)),
                    'delete' => sprintf('<a href="%s" class="delete-event" data-event-id="%d">Delete</a>', wp_nonce_url(admin_url('admin.php?page=events-manager&action=delete&id=' . $item->id), 'delete_event_' . $item->id), $item->id),
                    'remove' => sprintf('<a href="#" class="remove-event-button" data-event-id="%d" data-nonce="%s">Remove Event</a>', $item->id, wp_create_nonce('remove_event_' . $item->id)),
                    'rsvps' => sprintf('<a href="%s">View RSVPs</a>', admin_url('admin.php?page=events-manager-rsvps&event_id=' . $item->id))
                ];
                return wp_kses_post($item->title) . $this->row_actions($actions); // Allow <br> in title
            case 'event_date':
                return esc_html(date('F j, Y, g:i A', strtotime($item->event_date)));
            case 'status':
                return sprintf(
                    '<select class="event-status" data-id="%d" data-nonce="%s">
                        <option value="scheduled" %s>Scheduled</option>
                        <option value="live" %s>Live</option>
                        <option value="hidden" %s>Hidden</option>
                        <option value="past" %s>Past</option>
                    </select>',
                    $item->id,
                    wp_create_nonce('update_event_status_' . $item->id),
                    selected($item->status, 'scheduled', false),
                    selected($item->status, 'live', false),
                    selected($item->status, 'hidden', false),
                    selected($item->status, 'past', false)
                );
            case 'category':
                return esc_html($item->category ? ucfirst(str_replace('_', ' ', $item->category)) : '-');
            case 'rsvp_count':
                return esc_html($item->rsvp_count);
            case 'recording_url':
                return $item->recording_url ? '<a href="' . esc_url($item->recording_url) . '" target="_blank">View</a>' : '-';
            default:
                return '';
        }
    }

    function column_cb($item) {
        return sprintf('<input type="checkbox" name="event_ids[]" value="%s" />', $item->id);
    }

    function extra_tablenav($which) {
        if ($which == 'top') {
            $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
            $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
            ?>
            <div class="alignleft actions">
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="scheduled" <?php selected($status, 'scheduled'); ?>>Scheduled</option>
                    <option value="live" <?php selected($status, 'live'); ?>>Live</option>
                    <option value="hidden" <?php selected($status, 'hidden'); ?>>Hidden</option>
                    <option value="past" <?php selected($status, 'past'); ?>>Past</option>
                </select>
                <select name="category">
                    <option value="">All Categories</option>
                    <option value="frequencies_unleashed" <?php selected($category, 'frequencies_unleashed'); ?>>Frequencies Unleashed</option>
                    <option value="frequencies_decoded" <?php selected($category, 'frequencies_decoded'); ?>>Frequencies Decoded</option>
                </select>
                <input type="submit" class="button" value="Filter">
            </div>
            <?php
        }
    }
}

// Add/Edit Event Page
function events_manager_add_page() {
    global $wpdb;
    $event_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    $event = $event_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}events WHERE id = %d", $event_id)) : null;

    if ($_POST && isset($_POST['save_event']) && check_admin_referer('save_event')) {
        // Validate stream URL
        $stream_url = !empty($_POST['stream_url']) ? esc_url_raw($_POST['stream_url']) : '';
        if ($stream_url) {
            if (strpos($stream_url, 'twitch.tv') !== false) {
                if (!preg_match('#https?://(www\.)?twitch\.tv/[a-zA-Z0-9_]+#', $stream_url)) {
                    echo '<div class="notice notice-error"><p>Invalid Twitch channel URL. Use format: https://www.twitch.tv/channel_name</p></div>';
                    return;
                }
            } elseif (strpos($stream_url, 'iframe.dacast.com') !== false) {
                // Validate DaCast iframe
                if (!preg_match('/<iframe[^>]+src=["\']https:\/\/iframe\.dacast\.com\/[^"\']+["\']/i', $stream_url) && !preg_match('/https:\/\/iframe\.dacast\.com\/[^\s]+/', $stream_url)) {
                    echo '<div class="notice notice-error"><p>Invalid DaCast URL or iframe.</p></div>';
                    return;
                }
            } elseif (!preg_match('/\.m3u8$/', $stream_url)) {
                echo '<div class="notice notice-error"><p>Stream URL must be a valid HLS playlist (.m3u8) for non-Twitch/DaCast streams.</p></div>';
                return;
            }
        }

        // Convert event_date to UTC
        $event_date = sanitize_text_field($_POST['event_date']);
        try {
            $date = new DateTime($event_date, new DateTimeZone('UTC'));
            $event_date_utc = $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            xaman_log("Failed to parse event_date: {$_POST['event_date']}, error: {$e->getMessage()}");
            echo '<div class="notice notice-error"><p>Invalid event date format. Please use YYYY-MM-DD HH:MM.</p></div>';
            return;
        }

        // Validate start_time and end_time
        $start_time = !empty($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : null;
        $end_time = !empty($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : null;
        if ($start_time) {
            try {
                $start_date = new DateTime($start_time, new DateTimeZone('UTC'));
                $start_time = $start_date->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                xaman_log("Failed to parse start_time: {$_POST['start_time']}, error: {$e->getMessage()}");
                echo '<div class="notice notice-error"><p>Invalid start time format.</p></div>';
                return;
            }
        }
        if ($end_time) {
            try {
                $end_date = new DateTime($end_time, new DateTimeZone('UTC'));
                $end_time = $end_date->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                xaman_log("Failed to parse end_time: {$_POST['end_time']}, error: {$e->getMessage()}");
                echo '<div class="notice notice-error"><p>Invalid end time format.</p></div>';
                return;
            }
        }

        // Handle banner URLs and media uploads
        $left_banner_url = !empty($_POST['left_banner_url']) ? esc_url_raw($_POST['left_banner_url']) : '';
        $right_banner_url = !empty($_POST['right_banner_url']) ? esc_url_raw($_POST['right_banner_url']) : '';
        $top_banner_url = !empty($_POST['top_banner_url']) ? esc_url_raw($_POST['top_banner_url']) : '';
        $top_banner_desktop_url = !empty($_POST['top_banner_desktop_url']) ? esc_url_raw($_POST['top_banner_desktop_url']) : '';
        $bottom_banner_url = !empty($_POST['bottom_banner_url']) ? esc_url_raw($_POST['bottom_banner_url']) : '';
        $recording_url = !empty($_POST['recording_url']) ? esc_url_raw($_POST['recording_url']) : '';
        $thumbnail_url = !empty($_POST['thumbnail_url']) ? esc_url_raw($_POST['thumbnail_url']) : '';

        // Sanitize title with <br> support
        $title = wp_kses($_POST['title'], ['br' => []]);

        // Validate category
        $category = sanitize_text_field($_POST['category'] ?? '');
        if (!in_array($category, ['frequencies_unleashed', 'frequencies_decoded', ''])) {
            $category = '';
        }

        $data = [
            'title' => $title,
            'event_date' => $event_date_utc,
            'status' => in_array($_POST['status'], ['scheduled', 'live', 'hidden', 'past']) ? $_POST['status'] : 'scheduled',
            'category' => $category,
            'recording_url' => $recording_url,
            'thumbnail_url' => $thumbnail_url,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'stream_url' => $stream_url,
            'left_banner_url' => $left_banner_url,
            'right_banner_url' => $right_banner_url,
            'top_banner_url' => $top_banner_url,
            'top_banner_desktop_url' => $top_banner_desktop_url,
            'bottom_banner_url' => $bottom_banner_url
        ];

        if ($event_id) {
            $wpdb->update($wpdb->prefix . 'events', $data, ['id' => $event_id]);
            xaman_log("Updated event ID $event_id with title: $title, category: $category, stream_url: $stream_url, recording_url: $recording_url, thumbnail_url: $thumbnail_url, event_date: $event_date_utc");
            delete_transient('events_shortcode_data');
            echo '<div class="notice notice-success"><p>Event updated.</p></div>';
        } else {
            $wpdb->insert($wpdb->prefix . 'events', $data);
            $event_id = $wpdb->insert_id;
            xaman_log("Created event ID $event_id with title: $title, category: $category, stream_url: $stream_url, recording_url: $recording_url, thumbnail_url: $thumbnail_url, event_date: $event_date_utc");
            delete_transient('events_shortcode_data');
            echo '<div class="notice notice-success"><p>Event created.</p></div>';
        }
    }

    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        $event_id = absint($_GET['id']);
        xaman_log("Processing delete request: action=" . ($_GET['action'] ?? 'not set') . ", id=$event_id, nonce=" . ($_GET['_wpnonce'] ?? 'not set'));
        if (!check_admin_referer('delete_event_' . $event_id)) {
            xaman_log("Nonce verification failed for event deletion: ID=$event_id, Nonce=" . ($_GET['_wpnonce'] ?? 'not set'));
            wp_die('Security check failed. Please try again.', 'Error', ['response' => 403]);
        }
        global $wpdb;
        $tables = [
            'events' => ['id' => $event_id],
            'event_rsvps' => ['event_id' => $event_id],
            'event_chat' => ['event_id' => $event_id],
            'event_reactions' => ['event_id' => $event_id],
            'event_viewers' => ['event_id' => $event_id]
        ];
        $success = true;
        foreach ($tables as $table => $where) {
            $result = $wpdb->delete($wpdb->prefix . $table, $where, ['%d']);
            if ($result === false) {
                xaman_log("Failed to delete from {$wpdb->prefix}{$table} for event ID=$event_id: " . $wpdb->last_error);
                $success = false;
            } else {
                xaman_log("Deleted from {$wpdb->prefix}{$table} for event ID=$event_id: $result rows affected");
            }
        }
        if ($success) {
            delete_transient('events_shortcode_data');
            xaman_log("Cleared events_shortcode_data transient for event ID=$event_id");
            wp_redirect(admin_url('admin.php?page=events-manager&message=deleted'));
            exit;
        } else {
            wp_die('Failed to delete event. Check logs for details.', 'Error', ['response' => 500]);
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo $event_id ? 'Edit Event' : 'Add New Event'; ?></h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('save_event'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="title">Title</label></th>
                    <td>
                        <textarea name="title" id="title" class="regular-text" required><?php echo esc_textarea($event ? $event->title : ''); ?></textarea>
                        <p class="description">Use &lt;br&gt; for line breaks (e.g., "Frequencies Decoded Vol.8&lt;br&gt;Featuring guest name").</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="event_date">Event Date</label></th>
                    <td><input type="datetime-local" name="event_date" id="event_date" value="<?php echo esc_attr($event ? str_replace(' ', 'T', $event->event_date) : ''); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="start_time">Start Time (Optional)</label></th>
                    <td><input type="datetime-local" name="start_time" id="start_time" value="<?php echo esc_attr($event && $event->start_time ? str_replace(' ', 'T', $event->start_time) : ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="end_time">End Time (Optional)</label></th>
                    <td><input type="datetime-local" name="end_time" id="end_time" value="<?php echo esc_attr($event && $event->end_time ? str_replace(' ', 'T', $event->end_time) : ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td>
                        <select name="status" id="status">
                            <option value="scheduled" <?php selected($event ? $event->status : '', 'scheduled'); ?>>Scheduled</option>
                            <option value="live" <?php selected($event ? $event->status : '', 'live'); ?>>Live</option>
                            <option value="hidden" <?php selected($event ? $event->status : '', 'hidden'); ?>>Hidden</option>
                            <option value="past" <?php selected($event ? $event->status : '', 'past'); ?>>Past</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="category">Category</label></th>
                    <td>
                        <select name="category" id="category">
                            <option value="" <?php selected($event ? $event->category : '', ''); ?>>None</option>
                            <option value="frequencies_unleashed" <?php selected($event ? $event->category : '', 'frequencies_unleashed'); ?>>Frequencies Unleashed</option>
                            <option value="frequencies_decoded" <?php selected($event ? $event->category : '', 'frequencies_decoded'); ?>>Frequencies Decoded</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="stream_url">Live Stream URL</label></th>
                    <td>
                        <input type="url" name="stream_url" id="stream_url" value="<?php echo esc_attr($event ? $event->stream_url : ''); ?>" class="regular-text" placeholder="e.g., https://www.twitch.tv/channel_name or https://iframe.dacast.com/...">
                        <p class="description">Enter a Twitch URL, DaCast iframe/URL, or HLS playlist (.m3u8).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="recording_url">Replay URL</label></th>
                    <td>
                        <input type="url" name="recording_url" id="recording_url" value="<?php echo esc_attr($event ? $event->recording_url : ''); ?>" class="regular-text" placeholder="e.g., https://www.youtube.com/watch?v=...">
                        <p class="description">Enter the URL for the event recording (e.g., YouTube, DaCast, or HLS playlist).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="thumbnail_url">Event Thumbnail</label></th>
                    <td>
                        <input type="url" name="thumbnail_url" id="thumbnail_url" value="<?php echo esc_attr($event ? $event->thumbnail_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_thumbnail_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the event thumbnail image.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="left_banner_url">Left Banner (Desktop, 160x600px)</label></th>
                    <td>
                        <input type="url" name="left_banner_url" id="left_banner_url" value="<?php echo esc_attr($event ? $event->left_banner_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_left_banner_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the left banner image for desktop (recommended 160x600px).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="right_banner_url">Right Banner (Desktop, 160x600px)</label></th>
                    <td>
                        <input type="url" name="right_banner_url" id="right_banner_url" value="<?php echo esc_attr($event ? $event->right_banner_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_right_banner_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the right banner image for desktop (recommended 160x600px).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="top_banner_url">Top Banner (Desktop & Mobile, 320x100px)</label></th>
                    <td>
                        <input type="url" name="top_banner_url" id="top_banner_url" value="<?php echo esc_attr($event ? $event->top_banner_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_top_banner_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the top banner image for desktop and mobile (recommended 320x100px).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="top_banner_desktop_url">Top Banner (Desktop, 680x213px)</label></th>
                    <td>
                        <input type="url" name="top_banner_desktop_url" id="top_banner_desktop_url" value="<?php echo esc_attr($event ? $event->top_banner_desktop_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_top_banner_desktop_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the top banner image for desktop (recommended 680x213px). Falls back to Top Banner if not set.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="bottom_banner_url">Bottom Banner (Mobile, 320x100px)</label></th>
                    <td>
                        <input type="url" name="bottom_banner_url" id="bottom_banner_url" value="<?php echo esc_attr($event ? $event->bottom_banner_url : ''); ?>" class="regular-text">
                        <input type="button" id="upload_bottom_banner_button" class="button" value="Upload Image">
                        <p class="description">Upload or enter the URL of the bottom banner image for mobile (recommended 320x100px).</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="save_event" class="button button-primary" value="Save Event">
            </p>
        </form>
        <script>
            jQuery(document).ready(function($) {
                // Media uploader for thumbnail
                $('#upload_thumbnail_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Event Thumbnail',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#thumbnail_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for left banner
                $('#upload_left_banner_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Left Banner Image',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#left_banner_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for right banner
                $('#upload_right_banner_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Right Banner Image',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#right_banner_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for top banner
                $('#upload_top_banner_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Top Banner Image (Desktop & Mobile)',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#top_banner_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for top banner desktop
                $('#upload_top_banner_desktop_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Top Banner Image (Desktop)',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#top_banner_desktop_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for bottom banner
                $('#upload_bottom_banner_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Bottom Banner Image (Mobile)',
                        button: { text: 'Use this image' },
                        multiple: false
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#bottom_banner_url').val(attachment.url);
                    });
                    frame.open();
                });
                // Media uploader for recording
                $('#upload_recording_button').click(function(e) {
                    e.preventDefault();
                    var frame = wp.media({
                        title: 'Select Event Recording',
                        button: { text: 'Use this video' },
                        multiple: false,
                        library: { type: ['video'] }
                    });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#recording_url').val(attachment.url);
                    });
                    frame.open();
                });
            });
        </script>
    </div>
    <?php
}

// RSVPs Page (unchanged)
function events_manager_rsvps_page() {
    global $wpdb;
    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
    $events = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}events ORDER BY event_date DESC");
    $rsvps = $event_id ? $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, e.title FROM {$wpdb->prefix}event_rsvps r
         JOIN {$wpdb->prefix}events e ON r.event_id = e.id
         WHERE r.event_id = %d
         ORDER BY r.rsvp_at DESC",
        $event_id
    )) : [];
    ?>
    <div class="wrap">
        <h1>Event RSVPs</h1>
        <form method="get">
            <input type="hidden" name="page" value="events-manager-rsvps">
            <select name="event_id">
                <option value="">Select Event</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?php echo esc_attr($event->id); ?>" <?php selected($event_id, $event->id); ?>><?php echo esc_html($event->title); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="Filter">
        </form>
        <?php if ($rsvps): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>XRPL Account</th>
                        <th>RSVP Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rsvps as $rsvp): ?>
                        <tr>
                            <td><?php echo esc_html($rsvp->title); ?></td>
                            <td><?php echo esc_html($rsvp->xrpl_account); ?></td>
                            <td><?php echo esc_html(date('F j, Y, g:i A', strtotime($rsvp->rsvp_at))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No RSVPs found for this event.</p>
        <?php endif; ?>
    </div>
    <?php
}

// Update event status
add_action('wp_ajax_update_event_status', 'update_event_status');
function update_event_status() {
    global $wpdb;
    $id = absint($_POST['id']);
    $status = in_array($_POST['status'], ['scheduled', 'live', 'hidden', 'past']) ? $_POST['status'] : 'scheduled';
    check_ajax_referer('update_event_status_' . $id, '_wpnonce');

    xaman_log("Attempting to update event ID $id status to $status");

    $result = $wpdb->update(
        $wpdb->prefix . 'events',
        ['status' => $status],
        ['id' => $id],
        ['%s'],
        ['%d']
    );

    if ($result === false) {
        xaman_log("Failed to update event ID $id status to $status: " . $wpdb->last_error);
        wp_send_json(['success' => false, 'error' => 'Database error'], 500);
    } elseif ($result === 0) {
        xaman_log("No changes made for event ID $id status to $status (already set or not found)");
        wp_send_json(['success' => false, 'error' => 'Event not found or no change'], 400);
    }

    delete_transient('events_shortcode_data');
    xaman_log("Cleared events_shortcode_data transient for event ID $id status update to $status");

    xaman_log("Updated event ID $id status to $status");
    wp_send_json(['success' => true, 'message' => 'Status updated']);
}

// Remove event
add_action('wp_ajax_remove_event', 'remove_event');
function remove_event() {
    header('Content-Type: application/json');
    global $wpdb;

    xaman_log("AJAX remove_event called: event_id=" . ($_POST['event_id'] ?? 'not set') . ", _wpnonce=" . ($_POST['_wpnonce'] ?? 'not set'));

    if (!isset($_POST['event_id']) || !isset($_POST['_wpnonce'])) {
        xaman_log("AJAX remove_event missing required fields: event_id=" . ($_POST['event_id'] ?? 'not set') . ", _wpnonce=" . ($_POST['_wpnonce'] ?? 'not set'));
        wp_send_json_error(['message' => 'Missing event ID or nonce'], 400);
    }

    $event_id = absint($_POST['event_id']);
    $nonce = sanitize_text_field($_POST['_wpnonce']);

    if (!wp_verify_nonce($nonce, 'remove_event_' . $event_id)) {
        xaman_log("AJAX nonce verification failed for event deletion: ID=$event_id, Nonce=$nonce");
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    $tables = [
        'events' => ['id' => $event_id],
        'event_rsvps' => ['event_id' => $event_id],
        'event_chat' => ['event_id' => $event_id],
        'event_reactions' => ['event_id' => $event_id],
        'event_viewers' => ['event_id' => $event_id]
    ];

    $success = true;
    foreach ($tables as $table => $where) {
        $result = $wpdb->delete($wpdb->prefix . $table, $where, ['%d']);
        if ($result === false) {
            xaman_log("AJAX failed to delete from {$wpdb->prefix}{$table} for event ID=$event_id: " . $wpdb->last_error);
            $success = false;
        } else {
            xaman_log("AJAX deleted from {$wpdb->prefix}{$table} for event ID=$event_id: $result rows affected");
        }
    }

    if ($success) {
        delete_transient('events_shortcode_data');
        xaman_log("AJAX cleared events_shortcode_data transient for event ID=$event_id");
        wp_send_json_success(['message' => 'Event deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete event'], 500);
    }
}

// Schedule cron event
add_action('wp', 'schedule_event_status_cron');
function schedule_event_status_cron() {
    if (!wp_next_scheduled('update_event_statuses')) {
        wp_schedule_event(time(), 'every_minute', 'update_event_statuses');
        xaman_log("Scheduled update_event_statuses cron job");
    }
}

// Define custom cron interval
add_filter('cron_schedules', 'add_every_minute_cron_schedule');
function add_every_minute_cron_schedule($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display' => __('Every Minute')
    ];
    return $schedules;
}

// Update event statuses
add_action('update_event_statuses', 'update_event_statuses_cron');
function update_event_statuses_cron() {
    global $wpdb;
    $events_table = $wpdb->prefix . 'events';
    $current_time = current_time('mysql');
    $current_timestamp = current_time('timestamp');
    $wp_timezone = wp_timezone_string();

    xaman_log("Running update_event_statuses cron at $current_time (Timestamp: $current_timestamp, Timezone: $wp_timezone)");

    $all_events = $wpdb->get_results("SELECT id, title, status, start_time, end_time FROM $events_table");
    xaman_log("Current events: " . json_encode($all_events, JSON_UNESCAPED_UNICODE));

    // Set events to live
    $events_to_live = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, start_time, end_time FROM $events_table
         WHERE status = 'scheduled' AND start_time IS NOT NULL
         AND start_time <= %s AND (end_time IS NULL OR end_time >= %s)",
        $current_time, $current_time
    ));

    foreach ($events_to_live as $event) {
        $result = $wpdb->update(
            $events_table,
            ['status' => 'live'],
            ['id' => $event->id],
            ['%s'],
            ['%d']
        );
        if ($result !== false) {
            delete_transient('events_shortcode_data');
            xaman_log("Updated event ID {$event->id} ({$event->title}) to status 'live' (Start: {$event->start_time}, End: {$event->end_time})");
        } else {
            xaman_log("Failed to update event ID {$event->id} to 'live': " . $wpdb->last_error);
        }
    }

    // Set events to hidden (e.g., for footage editing)
    $events_to_hidden = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, start_time, end_time FROM $events_table
         WHERE status = 'live' AND end_time IS NOT NULL
         AND end_time < %s AND recording_url IS NULL",
        $current_time
    ));

    foreach ($events_to_hidden as $event) {
        $result = $wpdb->update(
            $events_table,
            ['status' => 'hidden'],
            ['id' => $event->id],
            ['%s'],
            ['%d']
        );
        if ($result !== false) {
            delete_transient('events_shortcode_data');
            xaman_log("Updated event ID {$event->id} ({$event->title}) to status 'hidden' (Start: {$event->start_time}, End: {$event->end_time})");
        } else {
            xaman_log("Failed to update event ID {$event->id} to 'hidden': " . $wpdb->last_error);
        }
    }

    // Set events to past
    $events_to_past = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, start_time, end_time FROM $events_table
         WHERE status IN ('live', 'hidden') AND end_time IS NOT NULL
         AND end_time < %s AND recording_url IS NOT NULL",
        $current_time
    ));

    foreach ($events_to_past as $event) {
        $result = $wpdb->update(
            $events_table,
            ['status' => 'past'],
            ['id' => $event->id],
            ['%s'],
            ['%d']
        );
        if ($result !== false) {
            delete_transient('events_shortcode_data');
            xaman_log("Updated event ID {$event->id} ({$event->title}) to status 'past' (Start: {$event->start_time}, End: {$event->end_time})");
        } else {
            xaman_log("Failed to update event ID {$event->id} to 'past': " . $wpdb->last_error);
        }
    }
}

// Events Shortcode
add_shortcode('events', 'events_shortcode');
function events_shortcode() {
    global $wpdb;
    xaman_log("Shortcode [events] executed on page: " . get_the_title());

    $account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    $has_nft = false;
    if ($account) {
        $transient_key = 'xaman_nft_' . md5($account);
        $nfts = get_transient($transient_key);
        if ($nfts === false) {
            $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();
            xaman_log("Fetching NFT data for events page: $url");
            $response = wp_remote_get($url, ['timeout' => 10]);
            if (is_wp_error($response)) {
                xaman_log("Failed to fetch NFT data for $account: " . $response->get_error_message());
            } else {
                $nfts = json_decode(wp_remote_retrieve_body($response), true);
                set_transient($transient_key, $nfts, 300);
                xaman_log("Cached NFT data for $account");
            }
        }
        if ($nfts && isset($nfts['result']['account_nfts'])) {
            foreach ($nfts['result']['account_nfts'] as $nft) {
                if (
                    ($nft['Issuer'] === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $nft['NFTokenTaxon'] == 0) ||
                    ($nft['Issuer'] === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $nft['NFTokenTaxon'] == 777) ||
                    ($nft['Issuer'] === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $nft['NFTokenTaxon'] == 717825) ||
                    ($nft['Issuer'] === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $nft['NFTokenTaxon'] == 1056369418)
                ) {
                    $has_nft = true;
                    xaman_log("Qualifying NFT found for $account: Issuer={$nft['Issuer']}, Taxon={$nft['NFTokenTaxon']}");
                    break;
                }
            }
        }
        xaman_log("NFT status for $account: " . ($has_nft ? 'Has qualifying NFT' : 'No qualifying NFT'));
    } else {
        xaman_log("No valid XRPL account for NFT check");
    }

    $events_transient_key = 'events_shortcode_data';
    $cached_events = get_transient($events_transient_key);
    if ($cached_events !== false) {
        xaman_log("Serving cached events data");
        $live_events = $cached_events['live'];
        $upcoming_events = $cached_events['upcoming'];
        $past_events = $cached_events['past'];
        $unleashed_events = $cached_events['unleashed'] ?? [];
        $decoded_events = $cached_events['decoded'] ?? [];
    } else {
        $events_table = $wpdb->prefix . 'events';
        $current_time = current_time('mysql');
        try {
            $live_events = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, event_date, stream_url
                 FROM $events_table
                 WHERE status = 'live'
                 ORDER BY event_date ASC
                 LIMIT 10"
            ));

            $upcoming_events = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, event_date
                 FROM $events_table
                 WHERE status = 'scheduled'
                 ORDER BY event_date ASC
                 LIMIT 10"
            ));

            $past_events = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, event_date, recording_url, thumbnail_url, category
                 FROM $events_table
                 WHERE status = 'past' AND recording_url IS NOT NULL
                 ORDER BY event_date DESC
                 LIMIT 10"
            ));

            $unleashed_events = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, event_date, recording_url, thumbnail_url
                 FROM $events_table
                 WHERE status = 'past' AND recording_url IS NOT NULL AND category = 'frequencies_unleashed'
                 ORDER BY event_date DESC
                 LIMIT 10"
            ));

            $decoded_events = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, event_date, recording_url, thumbnail_url
                 FROM $events_table
                 WHERE status = 'past' AND recording_url IS NOT NULL AND category = 'frequencies_decoded'
                 ORDER BY event_date DESC
                 LIMIT 10"
            ));

            xaman_log("Fetched " . count($live_events) . " live events, " . count($upcoming_events) . " upcoming events, " . count($past_events) . " past events, " . count($unleashed_events) . " unleashed events, " . count($decoded_events) . " decoded events");
            set_transient($events_transient_key, [
                'live' => $live_events,
                'upcoming' => $upcoming_events,
                'past' => $past_events,
                'unleashed' => $unleashed_events,
                'decoded' => $decoded_events
            ], 300);
        } catch (Exception $e) {
            xaman_log("Error fetching events: " . $e->getMessage());
            return '<p>Error loading events. Please try again later.</p>';
        }
    }

    $rsvps_table = $wpdb->prefix . 'event_rsvps';
    $nonce_replay = wp_create_nonce('event_replay_nonce');
    $nonce_rsvp = wp_create_nonce('event_rsvp_nonce');
    $nonce_live_check = wp_create_nonce('check_live_events');

    $live_event_ids = array_map(function($event) { return $event->id; }, $live_events);
    $live_event_ids_json = json_encode($live_event_ids);

    ob_start();
    ?>
    <div class="events" style="max-width:1000px; margin:0 auto; padding:20px; font-family:'Montserrat',sans-serif; text-align:center; background:#000; color:#fff;">
        <style>
            .events h2, .events h3 {
                color: var(--imu-gold, #d6ba66) !important;
            }
            .events .live-container, .events .timeline-container, .events .carousel-container {
                margin: 20px auto;
                padding: 10px;
                border: 1px solid var(--imu-gold, #d6ba66);
                border-radius: 5px;
                background: #000;
            }
            .events .live-event {
                padding: 15px;
            }
            .events .live-event h4 {
                margin: 0 0 5px;
                color: #fff;
                font-size: 18px;
            }
            .events .live-event p {
                margin: 0;
                color: #999;
                font-size: 14px;
            }
            .events .live-event .stream-btn {
                display: inline-block;
                padding: 8px 15px;
                background: var(--imu-gold, #d6ba66);
                color: #000;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 10px;
                font-size: 14px;
            }
            .events .timeline {
                list-style: none;
                padding: 0;
            }
            .events .timeline li {
                position: relative;
                padding: 15px 0 15px 40px;
                border-left: 2px solid var(--imu-gold, #d6ba66);
                margin-bottom: 10px;
            }
            .events .timeline li::before {
                content: '';
                position: absolute;
                left: -8px;
                top: 20px;
                width: 12px;
                height: 12px;
                background: var(--imu-gold, #d6ba66);
                border-radius: 50%;
            }
            .events .timeline li h4 {
                margin: 0 0 5px;
                color: #fff;
                font-size: 18px;
            }
            .events .timeline li p {
                margin: 0;
                color: #999;
                font-size: 14px;
            }
            .events .timeline li .rsvp-btn {
                padding: 8px 15px;
                border: none;
                border-radius: 5px;
                font-family: 'Montserrat', sans-serif;
            }
            .events .timeline li .btn {
                display: inline-block;
                padding: 8px 15px;
                background: var(--imu-gold, #d6ba66);
                color: #000;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 10px;
                font-size: 14px;
            }
            .events .carousel-container {
                margin: 20px auto;
                padding: 10px;
                border: 1px solid var(--imu-gold, #d6ba66);
                border-radius: 5px;
                background: #000;
            }
            .events .carousel {
                max-width: 800px;
                margin: 0 auto;
            }
            .events .carousel-item {
                padding: 10px;
                text-align: center;
            }
            .events .carousel-item img {
                width: 100%;
                height: 150px;
                object-fit: cover;
                border-radius: 5px;
                border: 1px solid var(--imu-gold, #d6ba66);
            }
            .events .carousel-item h4 {
                margin: 10px 0 5px;
                color: #fff;
                font-size: 16px;
            }
            .events .carousel-item p {
                margin: 0;
                color: #999;
                font-size: 12px;
            }
            .events .carousel-item .btn {
                display: inline-block;
                padding: 8px 15px;
                background: var(--imu-gold, #d6ba66);
                color: #000;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 10px;
                font-size: 14px;
            }
            .events .carousel-item .category-badge {
                position: absolute;
                top: 15px;
                left: 15px;
                background: var(--imu-gold, #d6ba66);
                color: #000;
                padding: 5px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .events .slick-prev, .events .slick-next {
                background: var(--imu-gold, #d6ba66) !important;
                color: #000 !important;
                z-index: 1;
            }
            .events .slick-dots li button:before {
                color: var(--imu-gold, #d6ba66) !important;
            }
            .events .slick-dots li.slick-active button:before {
                color: #fff !important;
            }
            .events .event-time {
                margin: 0;
                color: #FFFFFF !important;
                font-size: 14px;
                font-family: 'Montserrat', sans-serif;
            }
            @media (max-width: 768px) {
                .events .event-time {
                    font-size: 12px;
                }
                .events .live-event h4, .events .timeline li h4 {
                    font-size: 16px;
                }
                .events .live-event p, .events .timeline li p {
                    font-size: 12px;
                }
                .events .live-event .stream-btn, .events .timeline li .rsvp-btn, .events .timeline li .btn {
                    padding: 6px 12px;
                    font-size: 12px;
                }
                .events .carousel-item img {
                    height: 100px;
                }
                .events .carousel-item h4 {
                    font-size: 14px;
                }
                .events .carousel-item p {
                    font-size: 10px;
                }
                .events .carousel-item .btn {
                    font-size: 12px;
                    padding: 6px 12px;
                }
                .events .carousel-item .category-badge {
                    font-size: 10px;
                    padding: 3px 8px;
                }
            }
        </style>
        <h2>Events</h2>

        <!-- Live Events Section -->
        <?php if ($live_events): ?>
            <div class="live-container">
                <h3>Live Events</h3>
                <?php foreach ($live_events as $event): ?>
                    <div class="live-event">
                        <h4><?php echo esc_html($event->title); ?></h4>
                        <p class="event-time" data-utc-time="<?php echo esc_attr(strtotime($event->event_date)); ?>000">
                            <?php echo esc_html(date('F j, Y, g:i A', strtotime($event->event_date))); ?>
                        </p>
                        <?php if ($has_nft): ?>
                            <a href="<?php echo esc_url(home_url('/live/?event_id=' . $event->id)); ?>" class="stream-btn" target="_blank">Watch Live</a>
                        <?php elseif ($account): ?>
                            <p>You need a Protector or Guardian NFT to watch live events.</p>
                            <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="btn">Mint Now</a>
                        <?php else: ?>
                            <p>Please log in with Xaman Wallet to watch live events.</p>
                            <?php echo do_shortcode('[xaman_login]'); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Upcoming Events Timeline -->
        <div class="timeline-container">
            <h3>Upcoming Events</h3>
            <?php if ($upcoming_events): ?>
                <ul class="timeline">
                    <?php foreach ($upcoming_events as $event):
                        $has_rsvpd = $account ? $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $rsvps_table WHERE event_id = %d AND xrpl_account = %s",
                            $event->id, $account
                        )) : 0;
                        xaman_log("Event ID {$event->id} RSVP status for $account: " . ($has_rsvpd ? 'Already RSVP\'d' : 'Not RSVP\'d'));
                    ?>
                        <li>
                            <h4><?php echo esc_html($event->title); ?></h4>
                            <p class="event-time" data-utc-time="<?php echo esc_attr(strtotime($event->event_date)); ?>000">
                                <?php echo esc_html(date('F j, Y, g:i A', strtotime($event->event_date))); ?>
                            </p>
                            <?php if (!$account): ?>
                                <p>Please log in to RSVP for this event.</p>
                                <?php echo do_shortcode('[xaman_login]'); ?>
                            <?php elseif ($account && !$has_nft): ?>
                                <p>You need a Protector or Guardian NFT to RSVP for this event.</p>
                                <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="btn">Mint Now</a>
                            <?php else: ?>
                                <button class="rsvp-btn" data-event-id="<?php echo esc_attr($event->id); ?>" data-nonce="<?php echo esc_attr($nonce_rsvp); ?>" <?php echo $has_rsvpd ? 'disabled' : ''; ?> style="padding:8px 15px; background:<?php echo $has_rsvpd ? '#ccc' : 'var(--imu-gold, #d6ba66)'; ?>; color:<?php echo $has_rsvpd ? '#666' : '#000'; ?>; border:none; border-radius:5px; cursor:<?php echo $has_rsvpd ? 'not-allowed' : 'pointer'; ?>; font-family:'Montserrat',sans-serif;">
                                    <?php echo $has_rsvpd ? 'RSVP\'d' : 'RSVP'; ?>
                                </button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No upcoming events scheduled. Check back soon!</p>
            <?php endif; ?>
        </div>

        <!-- Past Events Carousel -->
        <div class="carousel-container">
            <h3>Past Event Recordings</h3>
            <?php if ($past_events): ?>
                <?php if ($has_nft): ?>
                    <div class="carousel past-events-carousel">
                        <?php foreach ($past_events as $event): ?>
                            <div class="carousel-item">
                                <img src="<?php echo esc_url($event->thumbnail_url ?: 'https://imcollectibles.io/wp-content/uploads/2023/12/event_placeholder.jpg'); ?>" alt="<?php echo esc_attr($event->title); ?>">
                                <h4><?php echo esc_html($event->title); ?></h4>
                                <p><?php echo esc_html(date('F j, Y', strtotime($event->event_date))); ?></p>
                                <a href="<?php echo esc_url(home_url('/replay/?event_id=' . $event->id)); ?>" class="btn replay-btn" data-event-id="<?php echo esc_attr($event->id); ?>" data-nonce="<?php echo esc_attr($nonce_replay); ?>" target="_blank">Watch Replay</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($account): ?>
                    <p>You need a Protector or Guardian NFT to view past event recordings.</p>
                    <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="btn">Mint Now</a>
                <?php else: ?>
                    <p>Please log in with Xaman Wallet to view past event recordings.</p>
                    <?php echo do_shortcode('[xaman_login]'); ?>
                <?php endif; ?>
            <?php else: ?>
                <p>No past event recordings available.</p>
            <?php endif; ?>
        </div>

        <!-- Frequencies Unleashed Carousel -->
        <div class="carousel-container">
            <h3>Frequencies Unleashed</h3>
            <?php if ($concert_events): ?>
                <?php if ($has_nft): ?>
                    <div class="carousel concert-carousel">
                        <?php foreach ($concert_events as $event): ?>
                            <div class="carousel-item">
                                <div style="position: relative;">
                                    <img src="<?php echo esc_url($event->thumbnail_url ?: 'https://imcollectibles.io/wp-content/uploads/2023/12/event_placeholder.jpg'); ?>" alt="<?php echo esc_attr($event->title); ?>">
                                    <span class="category-badge">Unleashed</span>
                                </div>
                                <h4><?php echo esc_html($event->title); ?></h4>
                                <p><?php echo esc_html(date('F j, Y', strtotime($event->event_date))); ?></p>
                                <a href="<?php echo esc_url(home_url('/replay/?event_id=' . $event->id)); ?>" class="btn replay-btn" data-event-id="<?php echo esc_attr($event->id); ?>" data-nonce="<?php echo esc_attr($nonce_replay); ?>" target="_blank">Watch Replay</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($account): ?>
                    <p>You need a Protector or Guardian NFT to view Frequencies Unleashed recordings.</p>
                    <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="btn">Mint Now</a>
                <?php else: ?>
                    <p>Please log in with Xaman Wallet to view Frequencies Unleashed recordings.</p>
                    <?php echo do_shortcode('[xaman_login]'); ?>
                <?php endif; ?>
            <?php else: ?>
                <p>No Frequencies Unleashed recordings available.</p>
            <?php endif; ?>
        </div>

        <!-- Frequencies Decoded Carousel -->
        <div class="carousel-container">
            <h3>Frequencies Decoded</h3>
            <?php if ($frequencies_events): ?>
                <?php if ($has_nft): ?>
                    <div class="carousel frequencies-carousel">
                        <?php foreach ($frequencies_events as $event): ?>
                            <div class="carousel-item">
                                <div style="position: relative;">
                                    <img src="<?php echo esc_url($event->thumbnail_url ?: 'https://imcollectibles.io/wp-content/uploads/2023/12/event_placeholder.jpg'); ?>" alt="<?php echo esc_attr($event->title); ?>">
                                    <span class="category-badge">Decoded</span>
                                </div>
                                <h4><?php echo esc_html($event->title); ?></h4>
                                <p><?php echo esc_html(date('F j, Y', strtotime($event->event_date))); ?></p>
                                <a href="<?php echo esc_url(home_url('/replay/?event_id=' . $event->id)); ?>" class="btn replay-btn" data-event-id="<?php echo esc_attr($event->id); ?>" data-nonce="<?php echo esc_attr($nonce_replay); ?>" target="_blank">Watch Replay</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($account): ?>
                    <p>You need a Protector or Guardian NFT to view Frequencies Decoded recordings.</p>
                    <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="btn">Mint Now</a>
                <?php else: ?>
                    <p>Please log in with Xaman Wallet to view Frequencies Decoded recordings.</p>
                    <?php echo do_shortcode('[xaman_login]'); ?>
                <?php endif; ?>
            <?php else: ?>
                <p>No Frequencies Decoded recordings available.</p>
            <?php endif; ?>
        </div>

        <!-- Load Slick Slider and jQuery -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css"/>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css"/>
        <script>
            jQuery(document).ready(function($) {
                $('.past-events-carousel, .concert-carousel, .frequencies-carousel').each(function() {
                    $(this).slick({
                        slidesToShow: 3,
                        slidesToScroll: 1,
                        autoplay: true,
                        autoplaySpeed: 5000,
                        dots: true,
                        arrows: true,
                        responsive: [
                            {
                                breakpoint: 768,
                                settings: {
                                    slidesToShow: 2
                                }
                            },
                            {
                                breakpoint: 480,
                                settings: {
                                    slidesToShow: 1
                                }
                            }
                        ]
                    });
                });

                // Handle RSVP button clicks
                $('.rsvp-btn').on('click', function(e) {
                    e.preventDefault();
                    const $btn = $(this);
                    if ($btn.prop('disabled')) return;

                    const eventId = $btn.data('event-id');
                    const nonce = $btn.data('nonce');
                    console.log('Attempting to RSVP for Event ID:', eventId);

                    const formData = new FormData();
                    formData.append('action', 'handle_event_rsvp');
                    formData.append('event_id', eventId);
                    formData.append('xrpl_account', '<?php echo esc_js($account); ?>');
                    formData.append('nonce', nonce);

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        console.log('RSVP response status:', response.status);
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('RSVP response:', data);
                        if (data.success) {
                            $btn.prop('disabled', true).text("RSVP'd").css({
                                'background': '#ccc',
                                'color': '#666',
                                'cursor': 'not-allowed'
                            });
                            alert(data.message);
                        } else {
                            console.warn('RSVP failed:', data.error);
                            alert(data.error || 'Failed to RSVP');
                        }
                    })
                    .catch(error => {
                        console.error('RSVP error:', error);
                        alert('Error sending RSVP: ' + error.message);
                    });
                });

                // Poll for live event updates
                let liveEventIds = <?php echo $live_event_ids_json; ?>;
                let isPolling = false;

                function arraysEqual(arr1, arr2) {
                    if (arr1.length !== arr2.length) return false;
                    return arr1.sort().join() === arr2.sort().join();
                }

                function checkLiveEvents() {
                    if (isPolling) return;
                    isPolling = true;
                    console.log('Checking for live events, current liveEventIds:', liveEventIds);
                    $.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'check_live_events',
                            _wpnonce: '<?php echo esc_js($nonce_live_check); ?>'
                        },
                        success: function(response) {
                            console.log('Live events check response:', response);
                            if (response.success && response.data.live_event_ids) {
                                const newLiveEventIds = response.data.live_event_ids;
                                console.log('New liveEventIds:', newLiveEventIds);
                                if (!arraysEqual(newLiveEventIds, liveEventIds)) {
                                    console.log('Live events changed, refreshing page');
                                    window.location.reload();
                                }
                                liveEventIds = newLiveEventIds;
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Live events check error:', status, error, 'Response:', xhr.responseText);
                        },
                        complete: function() {
                            isPolling = false;
                            setTimeout(checkLiveEvents, 120000);
                        }
                    });
                }
                checkLiveEvents();

                // Convert event times to user's local timezone
                function convertEventTimesToLocal() {
                    const eventTimeElements = document.querySelectorAll('.event-time');
                    eventTimeElements.forEach(element => {
                        const utcTime = parseInt(element.getAttribute('data-utc-time'), 10);
                        if (!isNaN(utcTime)) {
                            const date = new Date(utcTime);
                            const options = {
                                year: 'numeric',
                                month: 'long',
                                day: 'numeric',
                                hour: 'numeric',
                                minute: '2-digit',
                                hour12: true,
                                timeZoneName: 'short'
                            };
                            const formatter = new Intl.DateTimeFormat('en-US', options);
                            const parts = formatter.formatToParts(date);
                            let formattedTime = '';
                            let timezone = '';
                            parts.forEach(part => {
                                if (part.type !== 'timeZoneName') {
                                    formattedTime += part.value;
                                } else {
                                    timezone = part.value.replace(/[+-]\d{1,2}/, '');
                                }
                            });
                            formattedTime = formattedTime.replace(/,\s*$/, '');
                            element.textContent = `${formattedTime} ${timezone}`;
                            console.log('Converted time:', utcTime, 'to', `${formattedTime} ${timezone}`);
                        } else {
                            console.warn('Invalid UTC time for element:', element);
                        }
                    });
                }

                convertEventTimesToLocal();
            });
        </script>
    </div>
    <?php
    return ob_get_clean();
}



function handle_event_rsvp() {
    header('Content-Type: application/json');
    try {
        xaman_log("RSVP raw POST: " . json_encode($_POST));

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'event_rsvp_nonce')) {
            xaman_log("RSVP nonce verification failed: Received=" . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        xaman_log("RSVP attempt: Event ID=$event_id, Account=$account");

        if (empty($event_id)) {
            xaman_log("Invalid RSVP data: Event ID is empty");
            wp_send_json(['success' => false, 'error' => 'Invalid event ID'], 400);
        }

        if (empty($account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            xaman_log("Invalid RSVP data: Account=$account");
            wp_send_json(['success' => false, 'error' => 'Invalid XRPL account'], 400);
        }
        // Fix 1b (Aug 2026): the posted account must belong to the caller's
        // session token; previously anyone could RSVP as any wallet.
        $rsvp_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($account)
            : array('ok' => false, 'wallet' => '');
        if (empty($rsvp_auth['ok'])) {
            wp_send_json(['success' => false, 'error' => 'Not authorized for this account'], 403);
        }

        global $wpdb;
        $rsvps_table = $wpdb->prefix . 'event_rsvps';
        $events_table = $wpdb->prefix . 'events';
        $profiles_table = $wpdb->prefix . 'xaman_profiles';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$rsvps_table'") === $rsvps_table;
        if (!$table_exists) {
            xaman_log("RSVP table missing: $rsvps_table");
            wp_send_json(['success' => false, 'error' => 'RSVP table not found'], 500);
        }

        $event = $wpdb->get_row($wpdb->prepare("SELECT title, event_date FROM $events_table WHERE id = %d", $event_id));
        if (!$event) {
            xaman_log("Event does not exist: Event ID=$event_id");
            wp_send_json(['success' => false, 'error' => 'Event not found'], 400);
        }

        $already_rsvpd = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $rsvps_table WHERE event_id = %d AND xrpl_account = %s",
            $event_id, $account
        ));
        if ($already_rsvpd) {
            xaman_log("Already RSVP'd: Event ID=$event_id, Account=$account");
            wp_send_json(['success' => false, 'error' => 'Already RSVP\'d'], 400);
        }

        $result = $wpdb->insert(
            $rsvps_table,
            [
                'event_id' => $event_id,
                'xrpl_account' => $account,
                'rsvp_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s']
        );

        if ($result === false) {
            xaman_log("RSVP insert failed: Event ID=$event_id, Account=$account, Error: " . $wpdb->last_error);
            wp_send_json(['success' => false, 'error' => 'Failed to save RSVP: ' . $wpdb->last_error], 500);
        }

        send_notification($account, 'rsvp', [
            'title' => $event->title,
            'date' => date('F j, Y, g:i A', strtotime($event->event_date))
        ]);

        xaman_log("RSVP saved: Event ID=$event_id, Account=$account");
        wp_send_json(['success' => true, 'message' => 'RSVP successful!']);
    } catch (Exception $e) {
        xaman_log("RSVP exception: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
    }
}
add_action('wp_ajax_handle_event_rsvp', 'handle_event_rsvp');
add_action('wp_ajax_nopriv_handle_event_rsvp', 'handle_event_rsvp');




// live_events_shortcode (REVISED - Fetch all active tip accounts)
add_shortcode('live_events', 'live_events_shortcode');
function live_events_shortcode() {
    global $wpdb;
    xaman_log("Shortcode [live_events] executed on page: " . get_the_title());

    $account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    if ($account === '') {
        xaman_log("No valid session wallet for live events");
        return '<div class="section"><p>Please log in with Xaman Wallet to access live events.</p>' . do_shortcode('[xaman_login]') . '</div>';
    }
    $transient_key = 'xaman_nft_' . md5($account);
    $nfts = get_transient($transient_key);
    $has_nft = false;

    if ($nfts === false) {
        $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();
        xaman_log("Fetching NFT data for live events: $url");
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            xaman_log("Failed to fetch NFT data for $account: " . $response->get_error_message());
            return '<p>Error fetching wallet data. Please try again later.</p>';
        }
        $nfts = json_decode(wp_remote_retrieve_body($response), true);
        set_transient($transient_key, $nfts, 300);
        xaman_log("Cached NFT data for $account");
    }

    if ($nfts && isset($nfts['result']['account_nfts'])) {
        foreach ($nfts['result']['account_nfts'] as $nft) {
            if (
                ($nft['Issuer'] === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $nft['NFTokenTaxon'] == 0) ||
                ($nft['Issuer'] === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $nft['NFTokenTaxon'] == 777) ||
                ($nft['Issuer'] === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $nft['NFTokenTaxon'] == 717825) ||
                ($nft['Issuer'] === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $nft['NFTokenTaxon'] == 1056369418)
            ) {
                $has_nft = true;
                break;
            }
        }
    }

    if (!$has_nft) {
        xaman_log("Account $account lacks required NFTs for live events");
        return '<div class="section"><p>Become a Protector or Guardian to access live events!</p><a href="' . esc_url(home_url('/mint/')) . '" class="btn" style="display:inline-block; padding:10px 20px; background:gold; color:black; text-decoration:none; border-radius:5px;">Mint Now</a></div>';
    }

    // Check XFT trustline
    $xft_issuer = 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt';
    $xft_transient_key = 'xaman_xft_' . md5($account);
    $has_xft_trustline = get_transient($xft_transient_key . '_trustline');

    xaman_log("Initial XFT trustline transient for $account: " . var_export($has_xft_trustline, true));

    if (isset($_GET['clear_trustline_transient']) && $_GET['clear_trustline_transient'] === '1') {
        delete_transient($xft_transient_key . '_trustline');
        xaman_log("Cleared XFT trustline transient for $account");
        $has_xft_trustline = false;
    }

    if ($has_xft_trustline === false) {
        $xrpl_api_url = "https://s1.ripple.com:51234/";
        $request = ['method' => 'account_lines', 'params' => [['account' => $account, 'ledger_index' => 'current']]];
        $response = wp_remote_post($xrpl_api_url, [
            'body' => json_encode($request),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);
        $has_xft_trustline = false;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['result']['lines'])) {
                foreach ($body['result']['lines'] as $line) {
                    if ($line['account'] === $xft_issuer && $line['currency'] === 'XFT') {
                        $has_xft_trustline = true;
                        break;
                    }
                }
            }
        } else {
            xaman_log("XRPL API error for $account: " . (is_wp_error($response) ? $response->get_error_message() : "HTTP {$response['response']['code']}"));
        }
        set_transient($xft_transient_key . '_trustline', $has_xft_trustline, 300);
        xaman_log("Checked XFT trustline for $account: " . ($has_xft_trustline ? 'Yes' : 'No'));
    }

    $events_table = $wpdb->prefix . 'events';
    $event_id = absint($_GET['event_id'] ?? 0);
    try {
        if ($event_id) {
            $live_event = $wpdb->get_row($wpdb->prepare(
                "SELECT id, title, event_date, status, stream_url, recording_url, thumbnail_url, start_time, end_time, left_banner_url, right_banner_url, top_banner_url, top_banner_desktop_url, bottom_banner_url 
                 FROM $events_table 
                 WHERE id = %d AND status = 'live' 
                 LIMIT 1",
                $event_id
            ));
        } else {
            $live_event = $wpdb->get_row(
                "SELECT id, title, event_date, status, stream_url, recording_url, thumbnail_url, start_time, end_time, left_banner_url, right_banner_url, top_banner_url, top_banner_desktop_url, bottom_banner_url 
                 FROM $events_table 
                 WHERE status = 'live' 
                 ORDER BY event_date DESC 
                 LIMIT 1"
            );
        }
        if ($live_event) {
            xaman_log("Live event found: ID={$live_event->id}, Title={$live_event->title}, Stream URL={$live_event->stream_url}");
            $viewers_table = $wpdb->prefix . 'event_viewers';
            $wpdb->replace($viewers_table, [
                'event_id' => $live_event->id,
                'xrpl_account' => $account,
                'joined_at' => current_time('mysql')
            ]);
            xaman_log("Recorded viewer: Account=$account, Event ID={$live_event->id}");
        } else {
            xaman_log("No live event found: Event ID=$event_id");
        }
    } catch (Exception $e) {
        xaman_log("Error fetching live event: " . $e->getMessage());
        return '<p>Error loading live event. Please try again later.</p>';
    }

    $tip_accounts_table = $wpdb->prefix . 'tip_accounts';
    $tip_accounts = $wpdb->get_results("SELECT id, name, xrp_address FROM $tip_accounts_table WHERE status = 'active'");
    xaman_log("Fetched " . count($tip_accounts) . " active tip accounts");

    // Enqueue scripts and styles
    wp_enqueue_style('imu-events', get_template_directory_uri() . '/css/imu-events.css', [], '1.0.0');
    wp_enqueue_style('video-js', 'https://vjs.zencdn.net/7.21.0/video-js.css', [], '7.21.0');
    wp_enqueue_script('video-js', 'https://vjs.zencdn.net/7.21.0/video.min.js', [], '7.21.0', true);
    wp_enqueue_script('hls-js', 'https://cdn.jsdelivr.net/npm/hls.js@latest', [], null, true);
    wp_enqueue_script('videojs-hls-quality-selector', 'https://cdn.jsdelivr.net/npm/videojs-hls-quality-selector@1.1.1/dist/videojs-hls-quality-selector.min.js', ['video-js'], '1.1.1', true);
    wp_enqueue_script('imu-events-js', get_template_directory_uri() . '/js/imu-events.js', ['hls-js', 'video-js', 'videojs-hls-quality-selector'], '1.0.0', true);
    wp_localize_script('imu-events-js', 'eventReplayData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonceTip' => wp_create_nonce('xrpl_marketplace_nonce'),
        'nonceTrustline' => wp_create_nonce('send_trustline_nonce'),
        'nonceLiveCheck' => wp_create_nonce('check_live_events'),
        'nonceReaction' => wp_create_nonce('event_reaction_nonce'),
        'nonceViewer' => wp_create_nonce('event_viewer_nonce'),
        'nonceChat' => wp_create_nonce('event_chat_nonce'),
        'eventId' => $live_event ? absint($live_event->id) : 0,
        'xrplAccount' => $account,
        'hasXftTrustline' => $has_xft_trustline,
        'isYouTube' => false,
        'isTwitch' => $live_event && strpos($live_event->stream_url, 'twitch.tv') !== false,
        'isDacastIframe' => $live_event && (strpos($live_event->stream_url, 'iframe.dacast.com') !== false || strpos($live_event->stream_url, '<iframe') !== false),
        'wsUrl' => 'wss://events-chat.imcollectibles.io',
        'homeUrl' => esc_url(home_url('/live/?event_id='))
    ]);

    ob_start();
    ?>
    <div class="live-events" style="max-width:1200px; margin:0 auto; padding:10px; font-family:'Montserrat',sans-serif; text-align:center; position:relative;">
        <?php
        $top_banner_url = esc_url($live_event->top_banner_url ?? '');
        $top_banner_desktop_url = esc_url($live_event->top_banner_desktop_url ?? '');
        $bottom_banner_url = esc_url($live_event->bottom_banner_url ?? '');
        $is_mobile = wp_is_mobile();
        $top_banner_final_url = $is_mobile || empty($top_banner_desktop_url) ? $top_banner_url : $top_banner_desktop_url;
        $top_banner_placeholder = $is_mobile ? 'https://via.placeholder.com/320x100' : 'https://via.placeholder.com/1000x213';
        ?>
        <?php if ($live_event): ?>
            <h2 style="color:var(--imu-gold, #d6ba66); margin:0;"><?php echo esc_html($live_event->title); ?></h2>
            <div class="button-container">
                <?php if ($tip_accounts): ?>
                    <button class="tip-button" onclick="document.getElementById('tip-popup').style.display='block';">Send Tip</button>
                    <button class="help-button" onclick="document.getElementById('help-popup').style.display='block';">Help</button>
                    <button class="chat-toggle" id="chat-toggle">Chat</button>
                <?php endif; ?>
            </div>
            <?php if ($tip_accounts): ?>
                <div class="tip-popup" id="tip-popup">
                    <h3>Send a Tip</h3>
                    <form id="tip-form">
                        <input type="hidden" name="event_id" value="<?php echo esc_attr($live_event->id); ?>">
                        <input type="hidden" name="xrpl_account" value="<?php echo esc_attr($account); ?>">
                        <label for="tip_account_id">Recipient:</label>
                        <select id="tip_account_id" name="tip_account_id" required>
                            <option value="">Select Recipient</option>
                            <?php foreach ($tip_accounts as $tip_account): ?>
                                <option value="<?php echo esc_attr($tip_account->id); ?>" data-xrp-address="<?php echo esc_attr($tip_account->xrp_address); ?>">
                                    <?php echo esc_html($tip_account->name); ?> (<?php echo esc_html($tip_account->xrp_address); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="currency">Currency:</label>
                        <select id="currency" name="currency" required>
                            <option value="">Select recipient first</option>
                        </select>
                        <small style="color:#888; display:block; margin-top:2px;">Available currencies depend on recipient's trustlines</small>
                        <label for="amount">Amount:</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="Enter amount (min 0.01)">
                        <label for="memo">Message (Optional):</label>
                        <textarea id="memo" name="memo" maxlength="255" placeholder="Add a message with your tip (max 255 characters)" style="width:100%; padding:8px; border:1px solid var(--imu-gold, #d6ba66); border-radius:5px; background:#000; color:#fff; resize:vertical;"></textarea>
                        <button type="submit">Send Tip</button>
                        <button type="button" class="close-popup" onclick="document.getElementById('tip-popup').style.display='none';">Close</button>
                    </form>
                </div>
                <div class="help-popup" id="help-popup">
                    <h3>Maximise Your Experiences at IMU Events</h3>
                    <h5>Turn up the volume!</h5><p>Hit the unmute button in the bottom right of the media player to turn on the sound!</p>
                    <h5>Show your love</h5><p>Send a tip in $XRP or $XFT by hitting the "Send Tip" button above the media player</p>
                    <h5>Need the $XFT Trustline?</h5><p>Scroll to the bottom of the page and hit "Set Trustline"</p>
                    <h5>Seeing Clearly</h5><p>Drag any corner of the chat box to resize that to your hearts desire!</p>
                    <h5>Get out of the way</h5><p>Move the chat box by holding and dragging the gold "Live Chat" bar!</p>
                    <button type="button" onclick="document.getElementById('help-popup').style.display='none';">Close</button>
                </div>
            <?php endif; ?>
            <div class="media-container">
                <?php if (!empty($live_event->left_banner_url)): ?>
                    <img src="<?php echo esc_url($live_event->left_banner_url); ?>" alt="Left Banner" class="banner left">
                <?php endif; ?>
                <div class="video-container">
                    <?php
                    $stream_url = $live_event->stream_url;
                    $is_twitch = strpos($stream_url, 'twitch.tv') !== false;
                    $is_dacast_iframe = strpos($stream_url, 'iframe.dacast.com') !== false || strpos($stream_url, '<iframe') !== false;

                    if ($is_twitch) {
                        $channel = preg_replace('#https?://(www\.)?twitch\.tv/([a-zA-Z0-9_]+)#', '$2', $stream_url);
                        if ($channel && $channel !== $stream_url) {
                            ?>
                            <iframe
                                class="twitch-embed"
                                src="https://player.twitch.tv/?channel=<?php echo esc_attr($channel); ?>&parent=imcollectibles.io"
                                height="450"
                                width="100%"
                                frameborder="0"
                                scrolling="no"
                                allowfullscreen="true"
                                allow="fullscreen">
                            </iframe>
                            <?php
                        } else {
                            echo '<div class="stream-error">Invalid Twitch channel URL. Please check the stream URL.</div>';
                        }
                    } elseif ($is_dacast_iframe) {
                        if (strpos($stream_url, '<iframe') !== false) {
                            $stream_url = wp_kses($stream_url, [
                                'iframe' => [
                                    'src' => [],
                                    'height' => [],
                                    'width' => [],
                                    'frameborder' => [],
                                    'scrolling' => [],
                                    'allowfullscreen' => [],
                                    'class' => [],
                                    'allow' => []
                                ]
                            ]);
                            if (strpos($stream_url, 'class=') === false) {
                                $stream_url = str_replace('<iframe', '<iframe class="dacast-embed"', $stream_url);
                            } else {
                                $stream_url = str_replace('class="', 'class="dacast-embed ', $stream_url);
                            }
                            echo $stream_url;
                        } else {
                            ?>
                            <iframe
                                class="dacast-embed"
                                src="<?php echo esc_url($stream_url); ?>"
                                height="450"
                                width="100%"
                                frameborder="0"
                                scrolling="no"
                                allowfullscreen="true"
                                allow="autoplay; encrypted-media; fullscreen">
                            </iframe>
                            <?php
                        }
                    } else {
                        ?>
                        <video id="live-stream" class="video-js vjs-default-skin" controls autoplay muted data-setup='{"fluid": true, "controlBar": {"downloadButton": false}}'>
                            <source src="<?php echo esc_url($stream_url); ?>" type="application/x-mpegURL">
                            Your browser does not support the video tag.
                        </video>
                        <?php
                    }
                    ?>
                    <div class="reaction-overlay" id="reaction-overlay"></div>
                </div>
                <?php if (!empty($live_event->right_banner_url)): ?>
                    <img src="<?php echo esc_url($live_event->right_banner_url); ?>" alt="Right Banner" class="banner right">
                <?php endif; ?>
            </div>
            <div class="chat-container" id="chat-container">
                <div class="chat-header">Live Chat</div>
                <div id="event-chat-messages"></div>
                <form id="event-chat-form" style="margin-top:5px; padding:5px; display:flex; gap:5px; position:relative;">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($live_event->id); ?>">
                    <input type="hidden" name="xrpl_account" value="<?php echo esc_attr($account); ?>">
                    <input type="text" name="message" id="event-chat-message" placeholder="Type your message..." style="flex:1; padding:6px; border:1px solid var(--imu-gold, #d6ba66); font-family:'Montserrat',sans-serif;">
                    <button type="button" class="emoji-toggle" title="Toggle Emoji Picker">👽</button>
                    <div class="emoji-picker" id="emoji-picker">
                        <?php
                        $reactions = ['👍', '👏', '🤘', '🚀', '👽', '🕺', '💥', '❤️‍🔥', '💃', '🎤', '🎸', '🎷', '🎉', '❤️'];
                        foreach ($reactions as $reaction) {
                            echo '<button type="button" class="emoji-btn" data-emoji="' . esc_attr($reaction) . '">' . $reaction . '</button>';
                        }
                        ?>
                    </div>
                    <button type="submit" style="padding:6px 12px; background:var(--imu-gold, #d6ba66); color:#000; border:none; border-radius:5px; font-family:'Montserrat',sans-serif;">Send</button>
                </form>
                <div class="resize-tl"></div>
                <div class="resize-tr"></div>
                <div class="resize-bl"></div>
                <div class="resize-br"></div>
            </div>
            <div class="reactions-container">
                <h3 style="color:var(--imu-gold, #d6ba66); margin:0 0 5px;">Reactions</h3>
                <div id="reactions" style="display:flex; flex-wrap:wrap; gap:5px; justify-content:center;">
                    <?php
                    foreach ($reactions as $reaction) {
                        echo '<button class="reaction-btn" data-reaction="' . esc_attr($reaction) . '" style="padding:5px 10px; font-size:18px; border:1px solid var(--imu-gold, #d6ba66); border-radius:5px;">' . $reaction . '</button>';
                    }
                    ?>
                </div>
                <div id="reaction-counts" style="margin:5px 0; padding:5px;"></div>
            </div>
            <div class="audience-container">
                <h3 style="color:var(--imu-gold, #d6ba66);">Audience (<span id="audience-count">0</span>)</h3>
                <div class="audience-list" id="audience-list"></div>
            </div>
            <?php if ($tip_accounts): ?>
                <div class="tip-instructions">
                    <h3>Tip Instructions</h3>
                    <p>Show your support by sending a tip in XRP or XFT to an artist or IMU! Click the "Send Tip" button above, select a recipient, choose your currency, and enter an amount (minimum 0.01). You'll need to approve the transaction in your Xaman wallet.</p>
                    <?php if (!$has_xft_trustline): ?>
                        <button class="trustline-button" onclick="generateTrustlineQR()">Set Trustline</button>
                        <div class="trustline-qr" id="trustline-qr"></div>
                    <?php else: ?>
                        <p style="color:#28a745;">XFT trustline already set!</p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($top_banner_final_url)): ?>
                    <img src="<?php echo $top_banner_final_url; ?>" alt="Top Banner" class="bottom-top-banner" onerror="this.src='<?php echo $top_banner_placeholder; ?>';">
                <?php endif; ?>
                <?php if (!empty($bottom_banner_url)): ?>
                    <img src="<?php echo $bottom_banner_url; ?>" alt="Bottom Banner" class="bottom-banner" onerror="this.src='https://via.placeholder.com/1000x100';">
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:#fff;">No live stream available for this event. Please check back later.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Handle chat submission
add_action('wp_ajax_handle_event_chat', 'handle_event_chat');
add_action('wp_ajax_nopriv_handle_event_chat', 'handle_event_chat');
function handle_event_chat() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'event_chat_nonce')) {
            xaman_log("Chat nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        $message = wp_kses_post(stripslashes($_POST['message'] ?? ''));

        if (empty($event_id) || empty($account) || empty($message)) {
            xaman_log("Missing required fields: Event ID=$event_id, Account=$account, Message=$message");
            wp_send_json(['success' => false, 'error' => 'Missing required fields'], 400);
        }

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'xaman_profiles';
        $chat_table = $wpdb->prefix . 'event_comments';

        $profiles_exists = $wpdb->get_var("SHOW TABLES LIKE '$profiles_table'") === $profiles_table;
        xaman_log("Profiles table exists: " . ($profiles_exists ? 'yes' : 'no'));

        $user = $profiles_exists
            ? ($wpdb->get_var($wpdb->prepare("SELECT name FROM $profiles_table WHERE xrpl_account = %s", $account)) ?: substr($account, 0, 8))
            : substr($account, 0, 8);

        $result = $wpdb->insert($chat_table, [
            'event_id' => $event_id,
            'xrpl_account' => $account,
            'message' => $message,
            'created_at' => current_time('mysql')
        ], ['%d', '%s', '%s', '%s']);

        if ($result === false || $wpdb->last_error) {
            xaman_log("Chat insert failed: Event ID=$event_id, Account=$account, Message=$message, Error: " . $wpdb->last_error);
            wp_send_json(['success' => false, 'error' => 'Failed to save message'], 500);
        }

        // Update cache with the latest 50 comments
        $transient_key = 'event_comments_' . $event_id;
        $query = $profiles_exists
            ? "SELECT c.message, IFNULL(p.name, LEFT(c.xrpl_account, 8)) AS user, c.created_at
               FROM $chat_table c
               LEFT JOIN $profiles_table p ON c.xrpl_account = p.xrpl_account
               WHERE c.event_id = %d
               ORDER BY c.created_at DESC
               LIMIT 50"
            : "SELECT c.message, LEFT(c.xrpl_account, 8) AS user, c.created_at
               FROM $chat_table c
               WHERE c.event_id = %d
               ORDER BY c.created_at DESC
               LIMIT 50";
        $comments = $wpdb->get_results($wpdb->prepare($query, $event_id));
        $comments = array_reverse($comments);
        $comments = array_map(function($msg) {
            return [
                'user' => esc_html($msg->user),
                'message' => $msg->message,
                'created_at' => $msg->created_at
            ];
        }, $comments);
        set_transient($transient_key, ['success' => true, 'comments' => $comments], 5);
        xaman_log("Updated comments cache for Event ID=$event_id with " . count($comments) . " comments");

        xaman_log("Comment saved: Event ID=$event_id, User=$user, Message=$message");
        wp_send_json(['success' => true, 'user' => esc_html($user), 'message' => $message]);
    } catch (Exception $e) {
        xaman_log("Chat error: Event ID=$event_id, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Fetch chat comments
add_action('wp_ajax_get_event_chat', 'get_event_chat');
add_action('wp_ajax_nopriv_get_event_chat', 'get_event_chat');
function get_event_chat() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'event_chat_nonce')) {
            xaman_log("Chat fetch nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (empty($event_id)) {
            xaman_log("Missing event ID: Event ID=$event_id");
            wp_send_json(['success' => false, 'error' => 'Missing event ID'], 400);
        }

        $transient_key = 'event_comments_' . $event_id;
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            xaman_log("Serving cached comments for Event ID=$event_id");
            wp_send_json($cached);
        }

        global $wpdb;
        $chat_table = $wpdb->prefix . 'event_comments';
        $profiles_table = $wpdb->prefix . 'xaman_profiles';

        $chat_exists = $wpdb->get_var("SHOW TABLES LIKE '$chat_table'") === $chat_table;
        $profiles_exists = $wpdb->get_var("SHOW TABLES LIKE '$profiles_table'") === $profiles_table;
        xaman_log("Chat table exists: " . ($chat_exists ? 'yes' : 'no') . ", Profiles table exists: " . ($profiles_exists ? 'yes' : 'no'));

        if (!$chat_exists) {
            xaman_log("Chat table missing: $chat_table");
            $response = ['success' => true, 'comments' => []];
            set_transient($transient_key, $response, 5);
            wp_send_json($response);
        }

        $query = $profiles_exists
            ? "SELECT c.message, IFNULL(p.name, LEFT(c.xrpl_account, 8)) AS user, c.created_at
               FROM $chat_table c
               LEFT JOIN $profiles_table p ON c.xrpl_account = p.xrpl_account
               WHERE c.event_id = %d
               ORDER BY c.created_at DESC
               LIMIT 50"
            : "SELECT c.message, LEFT(c.xrpl_account, 8) AS user, c.created_at
               FROM $chat_table c
               WHERE c.event_id = %d
               ORDER BY c.created_at DESC
               LIMIT 50";

        xaman_log("Executing comment query for Event ID=$event_id");
        $comments = $wpdb->get_results($wpdb->prepare($query, $event_id));

        if ($wpdb->last_error) {
            xaman_log("Comment query failed: Event ID=$event_id, SQL Error: " . $wpdb->last_error . ", Query: " . $wpdb->last_query);
            $fallback_query = "SELECT c.message, LEFT(c.xrpl_account, 8) AS user, c.created_at
                              FROM $chat_table c
                              WHERE c.event_id = %d
                              ORDER BY c.created_at DESC
                              LIMIT 50";
            xaman_log("Attempting fallback comment query for Event ID=$event_id");
            $comments = $wpdb->get_results($wpdb->prepare($fallback_query, $event_id));
            if ($wpdb->last_error) {
                xaman_log("Comment fallback query failed: Event ID=$event_id, SQL Error: " . $wpdb->last_error . ", Query: " . $wpdb->last_query);
                wp_send_json(['success' => false, 'error' => 'Database error'], 500);
            }
        }

        $comments = array_reverse($comments);
        $comments = array_map(function($msg) {
            return [
                'user' => esc_html($msg->user),
                'message' => $msg->message,
                'created_at' => $msg->created_at
            ];
        }, $comments);

        xaman_log("Fetched " . count($comments) . " comments for Event ID=$event_id");
        $response = ['success' => true, 'comments' => $comments ?: []];
        set_transient($transient_key, $response, 5);
        wp_send_json($response);
    } catch (Exception $e) {
        xaman_log("Comment fetch exception: Event ID=$event_id, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Handle reaction submission
add_action('wp_ajax_handle_event_reaction', 'handle_event_reaction');
add_action('wp_ajax_nopriv_handle_event_reaction', 'handle_event_reaction');
function handle_event_reaction() {
    header('Content-Type: application/json');
    try {
        xaman_log("Raw POST data for handle_event_reaction: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'event_reaction_nonce')) {
            xaman_log("Reaction nonce verification failed: Received=" . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        $reaction = $_POST['reaction'] ?? '';

        $unicode = mb_str_split($reaction, 1, 'UTF-8');
        $unicode_codes = array_map(function($char) {
            return 'U+' . strtoupper(dechex(mb_ord($char, 'UTF-8')));
        }, $unicode);
        $byte_hex = bin2hex($reaction);
        xaman_log("Validated inputs: Event ID=$event_id, Account=$account, Reaction=$reaction, Unicode=" . implode(' ', $unicode_codes) . ", Bytes=$byte_hex, Byte Length=" . strlen($reaction) . ", Char Length=" . mb_strlen($reaction, 'UTF-8'));

        if (empty($event_id) || empty($account) || empty($reaction)) {
            xaman_log("Missing required fields: Event ID=$event_id, Account=$account, Reaction=$reaction");
            wp_send_json(['success' => false, 'error' => 'Missing required fields'], 400);
        }

        $allowed_reactions = ['👍', '👏', '🤘', '🚀', '👽', '🕺', '💥', '❤️‍🔥', '💃', '🎤', '🎸', '🎷', '🎉', '❤️'];
        if (!in_array($reaction, $allowed_reactions, true)) {
            xaman_log("Invalid reaction: Reaction=$reaction, Unicode=" . implode(' ', $unicode_codes));
            wp_send_json(['success' => false, 'error' => 'Invalid reaction'], 400);
        }

        global $wpdb;
        $reactions_table = $wpdb->prefix . 'event_reactions';

        xaman_log("Inserting reaction: Reaction=$reaction, Unicode=" . implode(' ', $unicode_codes) . ", Hex=$byte_hex");

        $result = $wpdb->insert($reactions_table, [
            'event_id' => $event_id,
            'xrpl_account' => $account,
            'reaction' => $reaction,
            'created_at' => current_time('mysql')
        ], ['%d', '%s', '%s', '%s']);

        if ($result === false || $wpdb->last_error) {
            xaman_log("Reaction insert failed: Event ID=$event_id, Account=$account, Reaction=$reaction, Unicode=" . implode(' ', $unicode_codes) . ", Error: " . $wpdb->last_error);
            wp_send_json(['success' => false, 'error' => 'Failed to save reaction: ' . $wpdb->last_error], 500);
        }

        // Verify insertion
        $last_insert = $wpdb->get_row($wpdb->prepare(
            "SELECT reaction, HEX(reaction) AS hex, created_at
             FROM $reactions_table
             WHERE event_id = %d AND xrpl_account = %s
             ORDER BY created_at DESC
             LIMIT 1",
            $event_id, $account
        ));
        xaman_log("Last inserted reaction: " . json_encode($last_insert, JSON_UNESCAPED_UNICODE));

        // Clear cache
        delete_transient("event_reactions_$event_id");

        // Fetch updated counts
        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT reaction, HEX(reaction) AS hex, COUNT(*) AS count
             FROM $reactions_table
             WHERE event_id = %d
             GROUP BY reaction COLLATE utf8mb4_bin",
            $event_id
        ));
        $counts_array = [];
        foreach ($counts as $row) {
            $unicode = mb_str_split($row->reaction, 1, 'UTF-8');
            $unicode_codes = array_map(function($char) {
                return 'U+' . strtoupper(dechex(mb_ord($char, 'UTF-8')));
            }, $unicode);
            $counts_array[$row->reaction] = (int)$row->count;
            xaman_log("Handle reaction count: Reaction={$row->reaction}, Unicode=" . implode(' ', $unicode_codes) . ", Hex={$row->hex}, Count={$row->count}");
        }

        xaman_log("Reaction saved: Event ID=$event_id, Account=$account, Reaction=$reaction, Unicode=" . implode(' ', $unicode_codes) . ", Counts: " . json_encode($counts_array, JSON_UNESCAPED_UNICODE));
        wp_send_json(['success' => true, 'counts' => $counts_array]);
    } catch (Exception $e) {
        xaman_log("Reaction error: Event ID=$event_id, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// Fetch reaction counts
add_action('wp_ajax_get_event_reactions', 'get_event_reactions');
add_action('wp_ajax_nopriv_get_event_reactions', 'get_event_reactions');
function get_event_reactions() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'event_reaction_nonce')) {
            xaman_log("Reaction fetch nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (empty($event_id)) {
            xaman_log("Missing event ID in get_event_reactions");
            wp_send_json(['success' => false, 'error' => 'Missing event ID'], 400);
        }

        global $wpdb;
        $reactions_table = $wpdb->prefix . 'event_reactions';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$reactions_table'") === $reactions_table;
        if (!$table_exists) {
            xaman_log("Reactions table missing: $reactions_table");
            wp_send_json(['success' => true, 'counts' => [], 'recent' => []]);
        }

        $transient_key = "event_reactions_$event_id";
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            xaman_log("Serving cached reactions for Event ID=$event_id: " . json_encode($cached, JSON_UNESCAPED_UNICODE));
            wp_send_json($cached);
            return;
        }

        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT reaction, HEX(reaction) AS hex, COUNT(*) AS count
             FROM $reactions_table
             WHERE event_id = %d
             GROUP BY reaction COLLATE utf8mb4_bin",
            $event_id
        ));
        $counts_array = [];
        foreach ($counts as $row) {
            $unicode = mb_str_split($row->reaction, 1, 'UTF-8');
            $unicode_codes = array_map(function($char) {
                return 'U+' . strtoupper(dechex(mb_ord($char, 'UTF-8')));
            }, $unicode);
            $counts_array[$row->reaction] = (int)$row->count;
            xaman_log("Get reactions count: Reaction={$row->reaction}, Unicode=" . implode(' ', $unicode_codes) . ", Hex={$row->hex}, Count={$row->count}");
        }

        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT reaction, created_at
             FROM $reactions_table
             WHERE event_id = %d AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT 10",
            $event_id,
            date('Y-m-d H:i:s', strtotime('-5 seconds'))
        ));
        $recent_array = array_map(function($row) {
            return [
                'reaction' => $row->reaction,
                'created_at' => $row->created_at
            ];
        }, $recent);

        $response = [
            'success' => true,
            'counts' => $counts_array,
            'recent' => $recent_array
        ];

        set_transient($transient_key, $response, 2);
        xaman_log("Fetched reaction counts for Event ID=$event_id: " . json_encode($counts_array, JSON_UNESCAPED_UNICODE) . ", Recent: " . json_encode($recent_array, JSON_UNESCAPED_UNICODE));
        wp_send_json($response);
    } catch (Exception $e) {
        xaman_log("Reaction fetch error: Event ID=$event_id, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// Fetch viewers
add_action('wp_ajax_get_event_viewers', 'get_event_viewers');
add_action('wp_ajax_nopriv_get_event_viewers', 'get_event_viewers');
function get_event_viewers() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'event_viewer_nonce')) {
            xaman_log("Viewer fetch nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (empty($event_id)) {
            xaman_log("Missing event ID in get_event_viewers");
            wp_send_json(['success' => false, 'error' => 'Missing event ID'], 400);
        }

        global $wpdb;
        $viewers_table = $wpdb->prefix . 'event_viewers';
        $profiles_table = $wpdb->prefix . 'xaman_profiles';

        $viewers_exists = $wpdb->get_var("SHOW TABLES LIKE '$viewers_table'") === $viewers_table;
        $profiles_exists = $wpdb->get_var("SHOW TABLES LIKE '$profiles_table'") === $profiles_table;
        xaman_log("Viewers table exists: " . ($viewers_exists ? 'yes' : 'no') . ", Profiles table exists: " . ($profiles_exists ? 'yes' : 'no'));

        if (!$viewers_exists) {
            xaman_log("Viewers table missing: $viewers_table");
            wp_send_json(['success' => true, 'viewers' => []]);
        }

        $has_profile_pic_url = false;
        if ($profiles_exists) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $profiles_table LIKE 'profile_pic_url'");
            $has_profile_pic_url = !empty($columns);
            xaman_log("Profile pic url column exists: " . ($has_profile_pic_url ? 'yes' : 'no'));
        }

        $query = $profiles_exists && $has_profile_pic_url
            ? "SELECT v.xrpl_account, IFNULL(p.name, LEFT(v.xrpl_account, 8)) AS name, p.profile_pic_url
               FROM $viewers_table v
               LEFT JOIN $profiles_table p ON v.xrpl_account = p.xrpl_account
               WHERE v.event_id = %d AND v.joined_at >= %s
               ORDER BY v.joined_at DESC
               LIMIT 50"
            : "SELECT v.xrpl_account, LEFT(v.xrpl_account, 8) AS name, NULL AS profile_pic_url
               FROM $viewers_table v
               WHERE v.event_id = %d AND v.joined_at >= %s
               ORDER BY v.joined_at DESC
               LIMIT 50";

        xaman_log("Executing viewer query for Event ID=$event_id");
        $viewers = $wpdb->get_results($wpdb->prepare(
            $query,
            $event_id,
            date('Y-m-d H:i:s', strtotime('-10 minutes'))
        ));

        if ($wpdb->last_error) {
            xaman_log("Viewer fetch failed: Event ID=$event_id, SQL Error: " . $wpdb->last_error . ", Query: " . $wpdb->last_query);
            wp_send_json(['success' => false, 'error' => 'Database error'], 500);
        }

        $viewers_array = array_map(function($viewer) {
            return [
                'xrpl_account' => esc_html($viewer->xrpl_account),
                'name' => esc_html($viewer->name),
                'profile_pic_url' => esc_url($viewer->profile_pic_url ?: '')
            ];
        }, $viewers);

        xaman_log("Fetched " . count($viewers_array) . " viewers for Event ID=$event_id");
        wp_send_json(['success' => true, 'viewers' => $viewers_array]);
    } catch (Exception $e) {
        xaman_log("Viewer fetch error: Event ID=$event_id, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Handle tip submission (REVISED - Fixed nonce, enhanced logging)
// Handle tip submission (REVISED - Fixed payload structure and error handling)
// Handle tip submission (REVISED - Enhanced logging, set HTTP codes, clear trustline transient on failure)
add_action('wp_ajax_send_tip', 'send_tip');
add_action('wp_ajax_nopriv_send_tip', 'send_tip');
function send_tip() {
    header('Content-Type: application/json');
    $log_file = __DIR__ . '/logs/xumm-webhook.log'; // Use same log as proxy for consistency
    try {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Tip submission started: POST=" . json_encode($_POST, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'xrpl_marketplace_nonce')) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid nonce: " . ($_POST['_wpnonce'] ?? 'not set') . "\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        $tip_account_id = absint($_POST['tip_account_id'] ?? 0);
        $xrp_address = sanitize_text_field($_POST['xrp_address'] ?? '');
        $currency = strtoupper(sanitize_text_field($_POST['currency'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $memo = sanitize_textarea_field($_POST['memo'] ?? '');
        // v91: Accept issuer from request for multi-currency support
        $issuer = sanitize_text_field($_POST['issuer'] ?? '');

        if (!empty($event_id) && !empty($account) && !empty($tip_account_id) && !empty($xrp_address) && !empty($currency) && $amount >= 0.01 && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrp_address)) {
            // Proceed (validation passed)
        } else {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Missing/invalid fields: event_id=$event_id, account=$account, tip_account_id=$tip_account_id, xrp_address=$xrp_address, currency=$currency, amount=$amount\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Missing or invalid fields']);
        }

        // v91: For IOU tokens, require issuer - look up from Token Manager or fallback
        if ($currency !== 'XRP' && !$issuer) {
            if (function_exists('imc_get_token_by_ticker')) {
                $token_info = imc_get_token_by_ticker($currency);
                if ($token_info && !empty($token_info['issuer'])) {
                    $issuer = $token_info['issuer'];
                }
            }
            // Fallback for known tokens
            if (!$issuer) {
                $known_issuers = [
                    'XFT' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
                    'RLUSD' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
                    'SOLO' => 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
                    'SCHMECKLES' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
                    'XMEME' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW'
                ];
                $issuer = $known_issuers[$currency] ?? '';
            }
            if (!$issuer) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Missing issuer for currency: $currency\n", FILE_APPEND);
                http_response_code(400);
                wp_send_json(['success' => false, 'error' => 'Missing issuer for token: ' . $currency]);
            }
        }

        if (strlen($memo) > 255) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Memo too long: " . strlen($memo) . " chars\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Message too long']);
        }

        global $wpdb;
        $tip_accounts_table = $wpdb->prefix . 'tip_accounts';
        $events_table = $wpdb->prefix . 'events';

        // Validate recipient
        $tip_account = $wpdb->get_row($wpdb->prepare(
            "SELECT xrp_address, status FROM $tip_accounts_table WHERE id = %d",
            $tip_account_id
        ));

        if (!$tip_account) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "No tip account found for tip_account_id=$tip_account_id\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Invalid recipient']);
        }

        if ($tip_account->xrp_address !== $xrp_address) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Recipient address mismatch: tip_account_id=$tip_account_id, xrp_address=$xrp_address, db_address={$tip_account->xrp_address}\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Invalid recipient']);
        }

        if ($tip_account->status !== 'active') {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Recipient not active: tip_account_id=$tip_account_id, status={$tip_account->status}\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Recipient account is not active']);
        }

        // Validate event existence
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $events_table WHERE id = %d",
            $event_id
        ));
        if (!$event) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid event: event_id=$event_id\n", FILE_APPEND);
            http_response_code(400);
            wp_send_json(['success' => false, 'error' => 'Invalid event']);
        }

        // v92: Validate trustlines for BOTH sender AND recipient if currency is not XRP
        if ($currency !== 'XRP') {
            $xrpl_api_url = "https://s1.ripple.com:51234/";
            
            // === CHECK SENDER TRUSTLINE (can they send this token?) ===
            $sender_transient_key = 'tip_sender_' . strtolower($currency) . '_' . md5($account) . '_trustline';
            $sender_has_trustline = get_transient($sender_transient_key);
            if ($sender_has_trustline === false) {
                $request = ['method' => 'account_lines', 'params' => [['account' => $account, 'peer' => $issuer, 'ledger_index' => 'current']]];
                $response = wp_remote_post($xrpl_api_url, [
                    'body' => json_encode($request),
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 10
                ]);
                $sender_has_trustline = false;
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['result']['lines'])) {
                        foreach ($body['result']['lines'] as $line) {
                            if ($line['account'] === $issuer && strtoupper($line['currency']) === $currency) {
                                $sender_has_trustline = true;
                                break;
                            }
                        }
                    }
                } else {
                    $error_message = is_wp_error($response) ? $response->get_error_message() : "HTTP " . wp_remote_retrieve_response_code($response);
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "XRPL API error for sender trustline check: account=$account, currency=$currency, error=$error_message\n", FILE_APPEND);
                }
                set_transient($sender_transient_key, $sender_has_trustline, 300);
            }
            if (!$sender_has_trustline) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Sender $account missing $currency trustline\n", FILE_APPEND);
                delete_transient($sender_transient_key);
                http_response_code(400);
                wp_send_json(['success' => false, 'error' => "You need a $currency trustline to send this tip"]);
            }
            
            // === CHECK RECIPIENT TRUSTLINE (can artist receive this token?) ===
            $recipient_transient_key = 'tip_recipient_' . strtolower($currency) . '_' . md5($xrp_address) . '_trustline';
            $recipient_has_trustline = get_transient($recipient_transient_key);
            if ($recipient_has_trustline === false) {
                $request = ['method' => 'account_lines', 'params' => [['account' => $xrp_address, 'peer' => $issuer, 'ledger_index' => 'current']]];
                $response = wp_remote_post($xrpl_api_url, [
                    'body' => json_encode($request),
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 10
                ]);
                $recipient_has_trustline = false;
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['result']['lines'])) {
                        foreach ($body['result']['lines'] as $line) {
                            if ($line['account'] === $issuer && strtoupper($line['currency']) === $currency) {
                                $recipient_has_trustline = true;
                                break;
                            }
                        }
                    }
                } else {
                    $error_message = is_wp_error($response) ? $response->get_error_message() : "HTTP " . wp_remote_retrieve_response_code($response);
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "XRPL API error for recipient trustline check: recipient=$xrp_address, currency=$currency, error=$error_message\n", FILE_APPEND);
                }
                set_transient($recipient_transient_key, $recipient_has_trustline, 300);
            }
            if (!$recipient_has_trustline) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Recipient $xrp_address missing $currency trustline\n", FILE_APPEND);
                delete_transient($recipient_transient_key);
                http_response_code(400);
                wp_send_json(['success' => false, 'error' => "The recipient cannot receive $currency tips (no trustline set)"]);
            }
            
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Trustline check passed: sender=$account, recipient=$xrp_address, currency=$currency\n", FILE_APPEND);
        }

        $xumm_api_url = 'https://imcollectibles.io/xumm-proxy.php';
        // v91: Build amount based on currency type
        if ($currency === 'XRP') {
            $tip_amount = strval(intval($amount * 1000000)); // drops
        } else {
            $tip_amount = [
                'currency' => $currency,
                'value' => strval($amount),
                'issuer' => $issuer
            ];
        }
        $payload = [
            'txjson' => [
                'TransactionType' => 'Payment',
                'Destination' => $xrp_address,
                'Amount' => $tip_amount,
                'SourceTag' => 2606240013,
                'Memos' => $memo ? [[
                    'Memo' => [
                        'MemoData' => bin2hex($memo)
                    ]
                ]] : []
            ],
            'custom_meta' => [
                'instruction' => 'Approve the tip in Xaman',
                'blob' => [
                    'type' => 'tip',
                    'event_id' => $event_id,
                    'tip_account_id' => $tip_account_id,
                    'account' => $account
                ]
            ]
        ];

        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Sending XUMM request: URL=$xumm_api_url, Payload=" . json_encode(['action' => 'create-tip', '_wpnonce' => wp_create_nonce('xrpl_marketplace_nonce'), 'payload' => $payload], JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        $response = wp_remote_post($xumm_api_url, [
            'body' => json_encode([
                'action' => 'create-tip',
                '_wpnonce' => wp_create_nonce('xrpl_marketplace_nonce'),
                'payload' => $payload
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "XUMM API error: $error_message\n", FILE_APPEND);
            http_response_code(500);
            wp_send_json(['success' => false, 'error' => 'Failed to create tip transaction: ' . $error_message]);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "XUMM response: Code=$response_code, Body=" . json_encode($body, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        if ($response_code !== 200 || !isset($body['success']) || !$body['success']) {
            $error = $body['error'] ?? 'Invalid XUMM response';
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid XUMM response: $error\n", FILE_APPEND);
            http_response_code($response_code !== 200 ? $response_code : 500);
            wp_send_json(['success' => false, 'error' => $error]);
        }

        // Insert to DB for fallback
        $table_name = $wpdb->prefix . 'xumm_status';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE uuid = %s", $body['uuid']));
        if ($exists == 0) {
            $inserted = $wpdb->insert($table_name, [
                'uuid' => $body['uuid'],
                'account' => $account,
                'signed' => 0,
                'timestamp' => time(),
                'claimed' => 0,
                'type' => 'tip',
                'tip_event_id' => $event_id,
                'tip_account_id' => $tip_account_id,
                'tip_amount' => $amount,
                'tip_currency' => $currency
            ]);
            if ($inserted === false) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "DB insert failed for UUID {$body['uuid']}: " . $wpdb->last_error . "\n", FILE_APPEND);
            } else {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "DB fallback inserted for tip UUID {$body['uuid']}, event_id=$event_id, tip_account_id=$tip_account_id, amount=$amount, currency=$currency\n", FILE_APPEND);
            }
        }

        wp_send_json([
            'success' => true,
            'uuid' => $body['uuid'],
            'qr' => $body['qr'],
            'deeplink' => $body['deeplink']
        ]);
    } catch (Exception $e) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Tip exception: " . $e->getMessage() . "\n", FILE_APPEND);
        http_response_code(500);
        wp_send_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}

// Replace the entire check_tip_status function (lines ~1310-1330)
add_action('wp_ajax_check_tip_status', 'check_tip_status');
add_action('wp_ajax_nopriv_check_tip_status', 'check_tip_status');
function check_tip_status() {
    header('Content-Type: application/json');
    $log_file = __DIR__ . '/logs/xumm-webhook.log';
    try {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Tip status check: UUID=" . ($_POST['uuid'] ?? 'none') . "\n", FILE_APPEND);
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'xrpl_marketplace_nonce')) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid nonce\n", FILE_APPEND);
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $uuid = sanitize_text_field($_POST['uuid'] ?? '');
        if (empty($uuid)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Missing UUID\n", FILE_APPEND);
            wp_send_json(['success' => false, 'error' => 'Missing UUID'], 400);
        }

        $xumm_api_url = 'https://imcollectibles.io/xumm-proxy.php?check_uuid=' . urlencode($uuid);
        $response = wp_remote_get($xumm_api_url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Proxy error: " . $response->get_error_message() . "\n", FILE_APPEND);
            wp_send_json(['success' => false, 'error' => 'Failed to check status'], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Status response: " . json_encode($body) . "\n", FILE_APPEND);

        wp_send_json([
            'success' => $body['signed'] ?? false,
            'tx_hash' => $body['tx_hash'] ?? null
        ]);
    } catch (Exception $e) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Status exception: " . $e->getMessage() . "\n", FILE_APPEND);
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// v92: Get available tip currencies for a recipient (checks their trustlines)
add_action('wp_ajax_get_recipient_tip_currencies', 'get_recipient_tip_currencies');
add_action('wp_ajax_nopriv_get_recipient_tip_currencies', 'get_recipient_tip_currencies');
function get_recipient_tip_currencies() {
    header('Content-Type: application/json');
    
    $recipient_address = sanitize_text_field($_GET['recipient'] ?? $_POST['recipient'] ?? '');
    $sender_address = sanitize_text_field($_GET['sender'] ?? $_POST['sender'] ?? '');
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $recipient_address)) {
        wp_send_json(['success' => false, 'error' => 'Invalid recipient address']);
        return;
    }
    
    // Get known tokens from Token Manager
    $known_tokens = [];
    if (function_exists('imc_get_supported_tokens')) {
        $tokens = imc_get_supported_tokens('offers');
        foreach ($tokens as $t) {
            $known_tokens[$t['ticker']] = [
                'ticker' => $t['ticker'],
                'name' => $t['display_name'] ?? $t['ticker'],
                'issuer' => $t['issuer'] ?? '',
                'icon' => $t['icon_emoji'] ?? '🪙'
            ];
        }
    } else {
        // Fallback if Token Manager not available
        $known_tokens = [
            'XRP' => ['ticker' => 'XRP', 'name' => 'XRP', 'issuer' => '', 'icon' => '💧'],
            'XFT' => ['ticker' => 'XFT', 'name' => 'XFT Token', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt', 'icon' => '🎵'],
            'RLUSD' => ['ticker' => 'RLUSD', 'name' => 'RLUSD', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', 'icon' => '💵'],
            'SCHMECKLES' => ['ticker' => 'SCHMECKLES', 'name' => 'Schmeckles', 'issuer' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu', 'icon' => '🪙'],
            'XMEME' => ['ticker' => 'XMEME', 'name' => 'XMEME', 'issuer' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW', 'icon' => '🐸'],
        ];
    }
    
    // XRP is always available (no trustline needed)
    $available_currencies = [
        'XRP' => ['ticker' => 'XRP', 'name' => 'XRP', 'issuer' => '', 'icon' => '💧', 'recipient_can_receive' => true, 'sender_can_send' => true]
    ];
    
    // Check recipient's trustlines
    $xrpl_api_url = "https://s1.ripple.com:51234/";
    $request = ['method' => 'account_lines', 'params' => [['account' => $recipient_address, 'ledger_index' => 'current', 'limit' => 400]]];
    $response = wp_remote_post($xrpl_api_url, [
        'body' => json_encode($request),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10
    ]);
    
    $recipient_trustlines = [];
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['result']['lines'])) {
            foreach ($body['result']['lines'] as $line) {
                $currency = strtoupper($line['currency'] ?? '');
                $issuer = $line['account'] ?? '';
                // Check if it's a known token
                foreach ($known_tokens as $ticker => $token) {
                    if ($ticker !== 'XRP' && strtoupper($currency) === strtoupper($ticker) && $issuer === $token['issuer']) {
                        $recipient_trustlines[$ticker] = true;
                    }
                }
            }
        }
    }
    
    // Check sender's trustlines (if provided)
    $sender_trustlines = ['XRP' => true]; // XRP always available
    if (!empty($sender_address) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $sender_address)) {
        $request = ['method' => 'account_lines', 'params' => [['account' => $sender_address, 'ledger_index' => 'current', 'limit' => 400]]];
        $response = wp_remote_post($xrpl_api_url, [
            'body' => json_encode($request),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['result']['lines'])) {
                foreach ($body['result']['lines'] as $line) {
                    $currency = strtoupper($line['currency'] ?? '');
                    $issuer = $line['account'] ?? '';
                    foreach ($known_tokens as $ticker => $token) {
                        if ($ticker !== 'XRP' && strtoupper($currency) === strtoupper($ticker) && $issuer === $token['issuer']) {
                            $sender_trustlines[$ticker] = true;
                        }
                    }
                }
            }
        }
    }
    
    // Build final currency list with availability flags
    foreach ($known_tokens as $ticker => $token) {
        if ($ticker === 'XRP') continue; // Already added
        
        $recipient_can = isset($recipient_trustlines[$ticker]);
        $sender_can = isset($sender_trustlines[$ticker]);
        
        $available_currencies[$ticker] = [
            'ticker' => $ticker,
            'name' => $token['name'],
            'issuer' => $token['issuer'],
            'icon' => $token['icon'],
            'recipient_can_receive' => $recipient_can,
            'sender_can_send' => $sender_can,
            'available' => $recipient_can && $sender_can // Both must have trustline
        ];
    }
    
    wp_send_json([
        'success' => true,
        'recipient' => $recipient_address,
        'sender' => $sender_address,
        'currencies' => array_values($available_currencies)
    ]);
}

// Handle trustline submission
add_action('wp_ajax_send_trustline', 'send_trustline');
add_action('wp_ajax_nopriv_send_trustline', 'send_trustline');
function send_trustline() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'send_trustline_nonce')) {
            xaman_log("Trustline nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        if (empty($account)) {
            xaman_log("Missing XRPL account for trustline");
            wp_send_json(['success' => false, 'error' => 'Missing XRPL account'], 400);
        }

        $xumm_api_url = 'https://imcollectibles.io/xumm-proxy.php';
        $payload = [
            // Flags 131072 = tfSetNoRipple - see the note in price-oracle.php.
            // Rippling must be off on a holder trustline, and the protocol default
            // cannot be relied on; Xaman flags our older XFT lines as suboptimal.
            'txjson' => [
                'TransactionType' => 'TrustSet',
                'Flags' => 131072,
                'LimitAmount' => [
                    'currency' => 'XFT',
                    'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
                    'value' => '1000000'
                ]
            ],
            'custom_meta' => [
                'instruction' => 'Set XFT trustline in Xaman',
                'blob' => ['type' => 'trustline']
            ],
            'user_token' => null
        ];

        $response = wp_remote_post($xumm_api_url, [
            'body' => json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            xaman_log("XUMM trustline error: " . $response->get_error_message());
            wp_send_json(['success' => false, 'error' => 'Failed to create trustline transaction'], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['uuid']) || !isset($body['refs']['qr_png'])) {
            xaman_log("Invalid XUMM trustline response: " . json_encode($body));
            wp_send_json(['success' => false, 'error' => 'Invalid XUMM response'], 500);
        }

        xaman_log("Trustline payload created: UUID={$body['uuid']}, Account=$account");
        wp_send_json([
            'success' => true,
            'uuid' => $body['uuid'],
            'qr' => $body['refs']['qr_png'],
            'deeplink' => $body['next']['always']
        ]);
    } catch (Exception $e) {
        xaman_log("Trustline error: Account=$account, Error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Check Trustline Status (REVISED - Enhanced logging, simplified response)
add_action('wp_ajax_check_trustline_status', 'check_trustline_status');
add_action('wp_ajax_nopriv_check_trustline_status', 'check_trustline_status');
function check_trustline_status() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'send_trustline_nonce')) {
            xaman_log("Trustline status nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        $uuid = sanitize_text_field($_POST['uuid'] ?? '');
        if (empty($uuid)) {
            xaman_log("Missing UUID for trustline status check");
            wp_send_json(['success' => false, 'error' => 'Missing UUID'], 400);
        }

        $xumm_api_url = 'https://imcollectibles.io/xumm-proxy.php?check_uuid=' . urlencode($uuid);
        $response = wp_remote_get($xumm_api_url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            xaman_log("XUMM trustline status check error for UUID $uuid: " . $response->get_error_message());
            wp_send_json(['success' => false, 'error' => 'Failed to check status'], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        xaman_log("Trustline status response for UUID $uuid: " . json_encode($body));

        $is_signed = isset($body['signed']) && $body['signed'] && isset($body['tx_hash']);
        wp_send_json([
            'success' => $is_signed,
            'tx_hash' => $body['tx_hash'] ?? null
        ]);
    } catch (Exception $e) {
        xaman_log("Trustline status error for UUID $uuid: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Check live events
add_action('wp_ajax_check_live_events', 'check_live_events');
add_action('wp_ajax_nopriv_check_live_events', 'check_live_events');
function check_live_events() {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'check_live_events')) {
            xaman_log("Live events check nonce verification failed: " . ($_POST['_wpnonce'] ?? 'not set'));
            wp_send_json(['success' => false, 'error' => 'Invalid nonce'], 400);
        }

        global $wpdb;
        $events_table = $wpdb->prefix . 'events';
        $live_events = $wpdb->get_results(
            "SELECT id FROM $events_table WHERE status = 'live' ORDER BY event_date DESC"
        );

        $live_event_ids = array_map(function($event) {
            return (int)$event->id;
        }, $live_events);

        xaman_log("Checked live events: Found " . count($live_event_ids) . " live events");
        wp_send_json(['success' => true, 'data' => ['live_event_ids' => $live_event_ids]]);
    } catch (Exception $e) {
        xaman_log("Live events check error: " . $e->getMessage());
        wp_send_json(['success' => false, 'error' => 'Server error'], 500);
    }
}

// Cleanup reactions and viewers for ended events
add_action('wp_cron_cleanup_event_data', 'cleanup_event_data');
function cleanup_event_data() {
    global $wpdb;
    $events_table = $wpdb->prefix . 'events';
    $reactions_table = $wpdb->prefix . 'event_reactions';
    $viewers_table = $wpdb->prefix . 'event_viewers';
    $chat_table = $wpdb->prefix . 'event_comments';

    try {
        // v79: Fixed SQL prepare syntax
        $ended_events = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $events_table 
             WHERE status != 'live' 
             OR (end_time IS NOT NULL AND end_time < %s)",
            current_time('mysql')
        ));

        foreach ($ended_events as $event) {
            $event_id = (int)$event->id;

            // Delete reactions
            $reactions_deleted = $wpdb->delete($reactions_table, ['event_id' => $event_id], ['%d']);
            xaman_log("Cleaned up $reactions_deleted reactions for ended event ID=$event_id");

            // Delete viewers
            $viewers_deleted = $wpdb->delete($viewers_table, ['event_id' => $event_id], ['%d']);
            xaman_log("Cleaned up $viewers_deleted viewers for ended event ID=$event_id");

            // Delete comments
            $comments_deleted = $wpdb->delete($chat_table, ['event_id' => $event_id], ['%d']);
            xaman_log("Cleaned up $comments_deleted comments for ended event ID=$event_id");

            // Clear transients
            delete_transient("event_reactions_$event_id");
            delete_transient("event_comments_$event_id");
        }
    } catch (Exception $e) {
        xaman_log("Event data cleanup error: " . $e->getMessage());
    }
}

// Schedule cleanup cron if not already scheduled
if (!wp_next_scheduled('wp_cron_cleanup_event_data')) {
    wp_schedule_event(time(), 'hourly', 'wp_cron_cleanup_event_data');
}


add_action('admin_menu', function() {
    add_menu_page(
        'Test Notifications',
        'Test Notifications',
        'manage_options',
        'test-notifications',
        function() {
            global $wpdb;
            $profiles_table = $wpdb->prefix . 'xaman_profiles';
            $events_table = $wpdb->prefix . 'events';
            $notice = '';

            // Handle form submissions
            if (isset($_POST['action'])) {
                $account = sanitize_text_field($_POST['account'] ?? '');
                $nonce = $_POST['_wpnonce'] ?? '';
                $action = $_POST['action'];

                // Validate nonce and permissions
                if (!wp_verify_nonce($nonce, 'test_notification')) {
                    $notice = '<div class="notice notice-error"><p>Invalid nonce</p></div>';
                    xaman_log("Test notification: Invalid nonce for action=$action, account=$account", 'ERROR');
                } elseif (!current_user_can('manage_options')) {
                    $notice = '<div class="notice notice-error"><p>Unauthorized access</p></div>';
                    xaman_log("Test notification: Unauthorized access attempt for action=$action", 'ERROR');
                } elseif (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
                    $notice = '<div class="notice notice-error"><p>Invalid XRPL account</p></div>';
                    xaman_log("Test notification: Invalid XRPL account: $account for action=$action", 'ERROR');
                } else {
                    // Get user profile
                    $user = $wpdb->get_row($wpdb->prepare(
                        "SELECT email, notify_claims, notify_events FROM $profiles_table WHERE xrpl_account = %s",
                        $account
                    ));

                    if (!$user) {
                        $notice = '<div class="notice notice-error"><p>Account not found</p></div>';
                        xaman_log("Test notification: Account not found: $account for action=$action", 'ERROR');
                    } else {
                        $results = [];

                        if ($action === 'test_xft_claim_notification') {
                            // Test XFT Claim Success Notification
                            if (!$user->notify_claims) {
                                $notice = '<div class="notice notice-error"><p>Claim notifications disabled for this account</p></div>';
                                xaman_log("Test notification: Claim notifications disabled for account: $account", 'ERROR');
                            } elseif (!$user->email) {
                                $notice = '<div class="notice notice-error"><p>No email provided for this account</p></div>';
                                xaman_log("Test notification: No email for account: $account", 'ERROR');
                            } else {
                                $rewards = 100; // Simulated rewards
                                $email_result = send_email_notification(
                                    $user->email,
                                    "XFT Claim Successful",
                                    "You’ve successfully claimed $rewards XFT! Check your wallet at: " . home_url('/profile/')
                                );
                                xaman_log($email_result ? "Test claim success email sent to {$user->email}" : "Test claim success email failed for {$user->email}", $email_result ? 'INFO' : 'ERROR');
                                $results[$account] = [
                                    'email_result' => $email_result ? 'Sent' : 'Failed'
                                ];
                                $notice = '<div class="notice notice-success"><p>Test claim success notification triggered for ' . esc_html($account) . '. Results: ' . esc_html(json_encode($results)) . '</p></div>';
                            }
                        } elseif ($action === 'test_xft_claim_reset_notification') {
                            // Test XFT Claim Timer Reset Notification
                            if (!$user->notify_claims) {
                                $notice = '<div class="notice notice-error"><p>Claim notifications disabled for this account</p></div>';
                                xaman_log("Test notification: Claim notifications disabled for account: $account", 'ERROR');
                            } elseif (!$user->email) {
                                $notice = '<div class="notice notice-error"><p>No email provided for this account</p></div>';
                                xaman_log("Test notification: No email for account: $account", 'ERROR');
                            } else {
                                $email_result = send_email_notification(
                                    $user->email,
                                    "XFT Claim Ready",
                                    "Your XFT claim is ready! Visit your profile to claim now: " . home_url('/profile/')
                                );
                                xaman_log($email_result ? "Test claim reset email sent to {$user->email}" : "Test claim reset email failed for {$user->email}", $email_result ? 'INFO' : 'ERROR');
                                $results[$account] = [
                                    'email_result' => $email_result ? 'Sent' : 'Failed'
                                ];
                                $notice = '<div class="notice notice-success"><p>Test claim reset notification triggered for ' . esc_html($account) . '. Results: ' . esc_html(json_encode($results)) . '</p></div>';
                            }
                        } elseif ($action === 'test_event_start_notification') {
                            // Test Event Start Notification
                            $event_id = absint($_POST['event_id'] ?? 0);
                            $event = $wpdb->get_row($wpdb->prepare(
                                "SELECT title FROM $events_table WHERE id = %d",
                                $event_id
                            ));

                            if (!$event) {
                                $notice = '<div class="notice notice-error"><p>Event ID not found</p></div>';
                                xaman_log("Test notification: Event ID $event_id not found for account: $account", 'ERROR');
                            } elseif (!$user->notify_events) {
                                $notice = '<div class="notice notice-error"><p>Event notifications disabled for this account</p></div>';
                                xaman_log("Test notification: Event notifications disabled for account: $account", 'ERROR');
                            } elseif (!$user->email) {
                                $notice = '<div class="notice notice-error"><p>No email provided for this account</p></div>';
                                xaman_log("Test notification: No email for account: $account", 'ERROR');
                            } else {
                                $email_result = send_email_notification(
                                    $user->email,
                                    "Live Now: {$event->title}",
                                    "{$event->title} is live now! Join the event at: " . home_url('/live-events/?event_id=' . $event_id)
                                );
                                xaman_log($email_result ? "Test event start email sent to {$user->email} for event ID $event_id" : "Test event start email failed for {$user->email} for event ID $event_id", $email_result ? 'INFO' : 'ERROR');
                                $results[$account] = [
                                    'email_result' => $email_result ? 'Sent' : 'Failed'
                                ];
                                $notice = '<div class="notice notice-success"><p>Test event start notification triggered for ' . esc_html($account) . '. Results: ' . esc_html(json_encode($results)) . '</p></div>';
                            }
                        }
                    }
                }
            }

            // Fetch events for the event start test form
            $events = $wpdb->get_results("SELECT id, title FROM $events_table WHERE status IN ('scheduled', 'live') ORDER BY start_time DESC");
            ?>
            <div class="wrap">
                <h1>Test Notifications</h1>
                <?php echo $notice; ?>

                <!-- Test XFT Claim Success Notification -->
                <h2>Test XFT Claim Success Notification</h2>
                <form method="post" action="">
                    <p>
                        <label>XRPL Account: <input type="text" name="account" value="rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH"></label>
                    </p>
                    <input type="hidden" name="action" value="test_xft_claim_notification">
                    <?php wp_nonce_field('test_notification', '_wpnonce'); ?>
                    <input type="submit" value="Send Test Claim Success Notification" class="button button-primary">
                </form>

                <!-- Test XFT Claim Timer Reset Notification -->
                <h2>Test XFT Claim Timer Reset Notification</h2>
                <form method="post" action="">
                    <p>
                        <label>XRPL Account: <input type="text" name="account" value="rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH"></label>
                    </p>
                    <input type="hidden" name="action" value="test_xft_claim_reset_notification">
                    <?php wp_nonce_field('test_notification', '_wpnonce'); ?>
                    <input type="submit" value="Send Test Claim Reset Notification" class="button button-primary">
                </form>

                <!-- Test Event Start Notification -->
                <h2>Test Event Start Notification</h2>
                <form method="post" action="">
                    <p>
                        <label>XRPL Account: <input type="text" name="account" value="rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH"></label>
                    </p>
                    <p>
                        <label>Event:
                            <select name="event_id">
                                <option value="0">Select an event</option>
                                <?php foreach ($events as $event) : ?>
                                    <option value="<?php echo esc_attr($event->id); ?>"><?php echo esc_html($event->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <input type="hidden" name="action" value="test_event_start_notification">
                    <?php wp_nonce_field('test_notification', '_wpnonce'); ?>
                    <input type="submit" value="Send Test Event Start Notification" class="button button-primary">
                </form>
            </div>
            <?php
        }
    );
});
?>