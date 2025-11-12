<?php

// ================================
// HELPERS: Site Label & Recipients
// ================================
function wts_site_label() {
    return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
}

// Parse a string of emails (commas/semicolons/newlines) into a validated array
function wts_parse_emails($raw) {
    $raw = (string) $raw;
    $parts = preg_split('/[,\s;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $valid = [];
    if ($parts) {
        foreach ($parts as $p) {
            $p = trim($p);
            if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $p;
            }
        }
    }
    // de-dup
    return array_values(array_unique($valid));
}

// Return the configured recipients, or fall back to admin emails
function wts_get_notification_recipients() {
    $saved = get_option('wts_notification_recipients', '');
    $emails = wts_parse_emails($saved);

    if (!empty($emails)) {
        return $emails;
    }

    // Fallback to admins if nothing configured
    $admin_users = get_users(['role' => 'Administrator']);
    return wp_list_pluck($admin_users, 'user_email');
}


// ================================
// HELPERS: Fetch & Validate Names
// ================================

// Get Builder Names (Title + Alternate Field)
function wts_get_builder_names() {
    $builder_query = new WP_Query([
        'post_type'      => 'post_builders',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    $builder_names = [];

    if ($builder_query->have_posts()) {
        foreach ($builder_query->posts as $builder_id) {
            $builder_names[] = trim(strtolower(get_the_title($builder_id)));

            $alt_title = get_post_meta($builder_id, 'cf_legalname_alternate_title', true);
            if (!empty($alt_title)) {
                $builder_names[] = trim(strtolower($alt_title));
            }
        }
    }

    wp_reset_postdata();
    return $builder_names;
}

// Get Subdivision Names (Title + Alternate Field)
function wts_get_subdivision_names() {
    $subdivision_query = new WP_Query([
        'post_type'      => 'post_communities',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    $subdivision_names = [];

    if ($subdivision_query->have_posts()) {
        foreach ($subdivision_query->posts as $subdivision_id) {
            $subdivision_names[] = trim(strtolower(get_the_title($subdivision_id)));

            $alt_title = get_post_meta($subdivision_id, 'cf_legalname_alternate_title', true);
            if (!empty($alt_title)) {
                $subdivision_names[] = trim(strtolower($alt_title));
            }
        }
    }

    wp_reset_postdata();
    return $subdivision_names;
}

// Validate Builder
function wts_validate_builder($builder_value) {
    $builder_value = trim(strtolower($builder_value));
    if ($builder_value === '') return 'N/A';

    static $builder_names = null;
    if ($builder_names === null) $builder_names = wts_get_builder_names();

    return in_array($builder_value, $builder_names, true) ? ucwords($builder_value) : 'N/A';
}

// Validate Subdivision
function wts_validate_subdivision($subdivision_value) {
    $subdivision_value = trim(strtolower($subdivision_value));
    if ($subdivision_value === '') return 'N/A';

    static $subdivision_names = null;
    if ($subdivision_names === null) $subdivision_names = wts_get_subdivision_names();

    return in_array($subdivision_value, $subdivision_names, true) ? ucwords($subdivision_value) : 'N/A';
}


// ================================
// QUEUE NOTIFICATIONS ON CREATE/UPDATE
// (Stores status label too; and uses Status column later)
// ================================
function wts_get_property_status_label($post_id) {
    $terms = get_the_terms($post_id, 'es_status');
    if (is_wp_error($terms) || empty($terms)) return 'N/A';
    // If multiple terms, join names
    return implode(', ', wp_list_pluck($terms, 'name'));
}

function wts_queue_post_notification($post_ID, $post_after, $post_before) {
    if (!in_array($post_after->post_type, ['post', 'properties'], true)) return;

    $is_new = ($post_before->post_date === '0000-00-00 00:00:00');
    $action = $is_new ? 'created' : 'updated';

    $builder_raw     = get_post_meta($post_ID, 'es_property_builder', true);
    $builder         = wts_validate_builder($builder_raw);
    $subdivision_raw = get_post_meta($post_ID, 'es_property_subdivisionname', true);
    $subdivision     = wts_validate_subdivision($subdivision_raw);

    $match_status = ($builder !== 'N/A' && $subdivision !== 'N/A') ? 'Yes' : 'No';

    // Capture human-friendly status if this is a property
    $status_label = ($post_after->post_type === 'properties') ? wts_get_property_status_label($post_ID) : '-';

    $notifications   = get_transient('wts_post_notifications') ?: [];
    $notifications[] = [
        'type'            => ucfirst($post_after->post_type),
        'address'         => get_the_title($post_ID),
        'builder_raw'     => $builder_raw ?: 'N/A',
        'builder'         => $builder,
        'subdivision_raw' => $subdivision_raw ?: 'N/A',
        'subdivision'     => $subdivision,
        'status'          => $status_label,
        'action'          => $action,
        'match'           => $match_status,
    ];

    set_transient('wts_post_notifications', $notifications, 5 * MINUTE_IN_SECONDS);
}
add_action('post_updated', 'wts_queue_post_notification', 10, 3);


// ================================
// AUTO-DRAFT NEWLY PUBLISHED PROPERTIES (ONE-TIME)
// ================================
function revert_properties_to_draft_on_first_publish($new_status, $old_status, $post) {
    if ($post->post_type !== 'properties' || $new_status !== 'publish') return;

    if (!get_post_meta($post->ID, '_auto_drafted_initially', true)) {
        add_action('shutdown', function () use ($post) {
            wp_update_post([
                'ID'          => $post->ID,
                'post_status' => 'draft',
            ]);
            update_post_meta($post->ID, '_auto_drafted_initially', 'yes');
        });
    }
}
add_action('transition_post_status', 'revert_properties_to_draft_on_first_publish', 10, 3);


// ================================
// NOTIFICATIONS FOR AUTO IMPORTS (FALLBACK)
// ================================
function wts_queue_property_creation_fallback($post_ID, $post, $update) {
    if ($post->post_type !== 'properties' || $update) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $builder_raw     = get_post_meta($post_ID, 'es_property_builder', true);
    $builder         = wts_validate_builder($builder_raw);
    $subdivision_raw = get_post_meta($post_ID, 'es_property_subdivisionname', true);
    $subdivision     = wts_validate_subdivision($subdivision_raw);

    $match_status = ($builder !== 'N/A' && $subdivision !== 'N/A') ? 'Yes' : 'No';
    $status_label = wts_get_property_status_label($post_ID);

    $notifications = get_transient('wts_post_notifications') ?: [];
    $notifications[] = [
        'address'         => get_the_title($post_ID),
        'builder_raw'     => $builder_raw ?: 'N/A',
        'builder'         => $builder,
        'subdivision_raw' => $subdivision_raw ?: 'N/A',
        'subdivision'     => $subdivision,
        'status'          => $status_label,
        'type'            => 'Property',
        'action'          => 'created (re-import)',
        'match'           => $match_status,
    ];
    set_transient('wts_post_notifications', $notifications, 5 * MINUTE_IN_SECONDS);
}
add_action('save_post', 'wts_queue_property_creation_fallback', 20, 3);


// ================================
// SEND DIGEST EMAIL (TABLE FORMAT)
// ================================
function wts_send_post_notification_digest() {
    $notifications = get_transient('wts_post_notifications');
    if (!$notifications) return;

    delete_transient('wts_post_notifications');

    $emails = wts_get_notification_recipients();

    $subject = wts_site_label() . ', Post/Property Digest: ' . count($notifications) . ' changes detected';

    $rows = '';
    foreach ($notifications as $note) {
        $rows .= "<tr>
            <td>" . esc_html($note['match']) . "</td>
            <td>" . esc_html($note['address']) . "</td>
            <td>" . esc_html($note['builder_raw']) . "</td>
            <td>" . esc_html($note['builder']) . "</td>
            <td>" . esc_html($note['subdivision_raw']) . "</td>
            <td>" . esc_html($note['subdivision']) . "</td>
            <td>" . esc_html($note['status']) . "</td>
            <td>" . esc_html($note['action']) . "</td>
        </tr>";
    }

    $message  = "<p>The following posts/properties were created or updated:</p>
        <table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>
            <thead>
                <tr style='background:#f2f2f2;'>
                     <th align='left'>Match (Yes/No)</th>
                     <th align='left'>Address</th>
                     <th align='left'>Builder (Imported)</th>
                     <th align='left'>Builder (From Site)</th>
                     <th align='left'>Subdivision (Imported)</th>
                     <th align='left'>Subdivision (From Site)</th>
                     <th align='left'>Status</th>
                     <th align='left'>Action</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
        <p>This message was generated automatically.</p>";

    add_filter('wp_mail_content_type', function(){ return 'text/html'; });
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <wordpress@' . $_SERVER['SERVER_NAME'] . '>'
    ];
    foreach ($emails as $email) {
        wp_mail($email, $subject, $message, $headers);
    }
    remove_filter('wp_mail_content_type', function(){ return 'text/html'; });
}
add_action('shutdown', 'wts_send_post_notification_digest');


// ================================
// MANUAL CHECKER (and used by Cron)
// ================================
function wts_check_for_new_property_posts_to_notify() {
    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => [[ 'key' => '_wts_notification_sent', 'compare' => 'NOT EXISTS' ]],
        'date_query'     => [[ 'after' => '1 day ago' ]],
        'fields'         => 'ids',
    ];

    $query = new WP_Query($args);
    $count = 0;
    $rows  = '';

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            $builder_raw     = get_post_meta($post_id, 'es_property_builder', true);
            $builder         = wts_validate_builder($builder_raw);
            $subdivision_raw = get_post_meta($post_id, 'es_property_subdivisionname', true);
            $subdivision     = wts_validate_subdivision($subdivision_raw);
            $status_label    = wts_get_property_status_label($post_id);

            $match_status = ($builder !== 'N/A' && $subdivision !== 'N/A') ? 'Yes' : 'No';

            $rows .= "<tr>
                  <td>" . esc_html($match_status) . "</td>
                  <td>" . esc_html(get_the_title($post_id)) . "</td>
                  <td>" . esc_html($builder_raw ?: 'N/A') . "</td>
                  <td>" . esc_html($builder) . "</td>
                  <td>" . esc_html($subdivision_raw ?: 'N/A') . "</td>
                  <td>" . esc_html($subdivision) . "</td>
                  <td>" . esc_html($status_label) . "</td>
                  <td>created</td>
            </tr>";

            update_post_meta($post_id, '_wts_notification_sent', 'yes');
            $count++;
        }

        if ($count > 0) {
            $emails  = wts_get_notification_recipients();
            $subject = wts_site_label() . ", Property Digest: {$count} new properties added";
            $message = "<p>The following new Property posts have been added in the last 24 hours:</p>
                <table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>
                    <thead>
                        <tr style='background:#f2f2f2;'>
                           <th align='left'>Match (Yes/No)</th>
                           <th align='left'>Address</th>
                           <th align='left'>Builder (Imported)</th>
                           <th align='left'>Builder (From Site)</th>
                           <th align='left'>Subdivision (Imported)</th>
                           <th align='left'>Subdivision (From Site)</th>
                           <th align='left'>Status</th>
                           <th align='left'>Action</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <p>This message was generated automatically.</p>";

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <wordpress@' . $_SERVER['SERVER_NAME'] . '>'
            ];
            foreach ($emails as $email) {
                wp_mail($email, $subject, $message, $headers);
            }
        }
    }

    wp_reset_postdata();
    return $count;
}


// ================================
// CRON SCHEDULE
// ================================
function wts_add_cron_interval($schedules) {
    $schedules['every15'] = [
        'interval' => 15 * 60,
        'display'  => __('Every 15 Minutes'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'wts_add_cron_interval');

function wts_schedule_property_notification_checker() {
    if (!wp_next_scheduled('wts_run_property_notification_checker')) {
        wp_schedule_event(time(), 'every15', 'wts_run_property_notification_checker');
    }
}
add_action('wp', 'wts_schedule_property_notification_checker');

add_action('wts_run_property_notification_checker', 'wts_check_for_new_property_posts_to_notify');


// ================================
// ADMIN PAGE WITH BUTTONS (+ recipients field)
// ================================
function wts_check_properties_notification_page() {
    // Save recipients
    if (isset($_POST['wts_save_recipients']) && check_admin_referer('wts_save_recipients_action')) {
        $raw = isset($_POST['wts_notification_recipients']) ? wp_unslash($_POST['wts_notification_recipients']) : '';
        $clean_list = implode("\n", wts_parse_emails($raw)); // store pretty (one per line)
        update_option('wts_notification_recipients', $clean_list);
        echo '<div class="notice notice-success is-dismissible"><p>Notification recipients updated.</p></div>';
    }

    if (isset($_POST['wts_run_notification_check']) && check_admin_referer('wts_notification_check_action')) {
        $count = wts_check_for_new_property_posts_to_notify();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($count) . ' new properties were processed for notifications.</p></div>';
    }

    if (isset($_POST['wts_run_notification_cron']) && check_admin_referer('wts_notification_cron_action')) {
        $count = wts_check_for_new_property_posts_to_notify();
        echo '<div class="notice notice-info is-dismissible"><p>Simulated cron run: ' . esc_html($count) . ' new properties were processed.</p></div>';
    }

    if (isset($_POST['wts_send_test_email']) && check_admin_referer('wts_send_test_email_action')) {
        wts_send_test_notification_email();
        echo '<div class="notice notice-info is-dismissible"><p>A test notification email has been sent to the configured recipients.</p></div>';
    }

    $current_recipients = esc_textarea(get_option('wts_notification_recipients', ''));
    ?>
    <div class="wrap">
        <h1>Check Property Notifications</h1>

        <h2>Notification Recipients</h2>
        <p>Enter one or more email addresses, separated by commas, semicolons, or new lines. If left blank, emails go to all Admin users.</p>
        <form method="post" style="max-width:600px;margin-bottom:20px;">
            <?php wp_nonce_field('wts_save_recipients_action'); ?>
            <textarea name="wts_notification_recipients" rows="4" style="width:100%;"><?php echo $current_recipients; ?></textarea>
            <p><button type="submit" name="wts_save_recipients" class="button">Save Recipients</button></p>
        </form>

        <hr>

        <p><strong>Manual Checker:</strong> Checks <code>properties</code> posts created in the last day that haven’t triggered a notification and emails a digest to recipients.</p>
        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field('wts_notification_check_action'); ?>
            <input type="submit" name="wts_run_notification_check" class="button button-primary" value="Check and Notify">
        </form>

        <hr>

        <p><strong>Simulate Cron Run (Local Testing):</strong> Runs the same function the cron job executes every 15 minutes—right now.</p>
        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field('wts_notification_cron_action'); ?>
            <input type="submit" name="wts_run_notification_cron" class="button button-secondary" value="Run Cron Job Now">
        </form>

        <hr>

        <p><strong>Send Test Email:</strong> Sends a sample digest email to the configured recipients.</p>
        <form method="post">
            <?php wp_nonce_field('wts_send_test_email_action'); ?>
            <input type="submit" name="wts_send_test_email" class="button" value="Send Test Email">
        </form>
    </div>
    <?php
}

function wts_register_notification_checker_page() {
    add_submenu_page(
        'tools.php',
        'Property Notifications',
        'Property Notifications',
        'manage_options',
        'wts-check-property-notifications',
        'wts_check_properties_notification_page'
    );
}
add_action('admin_menu', 'wts_register_notification_checker_page');


// ================================
// SEND TEST NOTIFICATION EMAIL
// ================================
function wts_send_test_notification_email() {
    $emails = wts_get_notification_recipients();

    $subject = wts_site_label() . ', Property Digest: Test Email';

    $rows = "
        <tr>
            <td>Yes</td>
            <td>1234 N Test Ave</td>
            <td>BuilderX</td>
            <td>BuilderX</td>
            <td>Subdivision A</td>
            <td>Subdivision A</td>
            <td>Active</td>
            <td>created</td>
        </tr>
        <tr>
            <td>No</td>
            <td>5678 W Example St</td>
            <td>Unknown Builder</td>
            <td>N/A</td>
            <td>Subdivision Z</td>
            <td>N/A</td>
            <td>Expired</td>
            <td>updated</td>
        </tr>
    ";

    $message  = "<p>This is a <strong>test notification email</strong>. It shows how property updates will appear:</p>
        <table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>
            <thead>
                <tr style='background:#f2f2f2;'>
                     <th align='left'>Match (Yes/No)</th>
                     <th align='left'>Address</th>
                     <th align='left'>Builder (Imported)</th>
                     <th align='left'>Builder (From Site)</th>
                     <th align='left'>Subdivision (Imported)</th>
                     <th align='left'>Subdivision (From Site)</th>
                     <th align='left'>Status</th>
                     <th align='left'>Action</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
        <p>This test message was generated automatically.</p>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <wordpress@' . $_SERVER['SERVER_NAME'] . '>'
    ];

    @ob_end_flush();
    @flush();

    foreach ($emails as $email) {
        wp_mail($email, $subject, $message, $headers);
    }
}