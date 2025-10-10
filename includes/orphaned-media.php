<?php
// ======================================
// Orphaned Property Images (smart scan)
// Tools → Property Images
// ======================================

// --- Helpers: build the “used image IDs” set ---

/**
 * Gather attachment IDs that are referenced anywhere we care about:
 *  - Attached images (post_parent > 0)
 *  - Featured images (_thumbnail_id)
 *  - Estatik/ACF/custom fields that store IDs (single, comma-list, or serialized arrays)
 * You can extend the scanned meta keys with the filter 'wts_image_reference_meta_keys'.
 */
function wts_collect_used_attachment_ids_smart() {
    global $wpdb;

    $used = [];

    // 1) Attached images
    $attached = $wpdb->get_col("
        SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_mime_type LIKE 'image/%'
          AND post_parent > 0
    ");
    foreach ((array)$attached as $id) { $used[(int)$id] = true; }

    // 2) Featured images
    $thumbs = $wpdb->get_col("
        SELECT pm.meta_value
        FROM {$wpdb->postmeta} pm
        WHERE pm.meta_key = '_thumbnail_id'
          AND pm.meta_value REGEXP '^[0-9]+$'
    ");
    foreach ((array)$thumbs as $id) { $used[(int)$id] = true; }

    // 3) Estatik / ACF / custom meta that might hold attachment IDs
    $meta_keys = apply_filters('wts_image_reference_meta_keys', [
        'es_property_gallery',
        'es_property_photos',
        'es_floor_plans',
        'es_documents',
        '_thumbnail_id', // redundant but harmless
    ]);

    if (!empty($meta_keys)) {
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $sql = $wpdb->prepare("
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ($placeholders)
              AND p.post_status IN ('publish','draft','pending','private')
        ", $meta_keys);

        $rows = (array) $wpdb->get_col($sql);

        foreach ($rows as $raw) {
            $val = maybe_unserialize($raw);

            // Arrays (ACF/Estatik often store arrays of IDs)
            if (is_array($val)) {
                array_walk_recursive($val, function($v) use (&$used) {
                    if (is_numeric($v)) {
                        $used[(int)$v] = true;
                    } elseif (is_string($v) && preg_match_all('/\b\d+\b/', $v, $m)) {
                        foreach ($m[0] as $id) $used[(int)$id] = true;
                    }
                });
                continue;
            }

            // Single numeric
            if (is_numeric($val)) {
                $used[(int)$val] = true;
                continue;
            }

            // Comma-separated list
            if (is_string($val) && preg_match_all('/\b\d+\b/', $val, $m)) {
                foreach ($m[0] as $id) $used[(int)$id] = true;
            }
        }
    }

    // 4) (Optional) scan content for wp-image-123 (OFF by default; heavy on big sites)
    /*
    $content_ids = $wpdb->get_col("
        SELECT DISTINCT CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(p.post_content, 'wp-image-', -1), '\"', 1) AS UNSIGNED)
        FROM {$wpdb->posts} p
        WHERE p.post_status IN ('publish','draft','private')
          AND p.post_content LIKE '%wp-image-%'
    ");
    foreach ((array)$content_ids as $id) { if ($id) $used[(int)$id] = true; }
    */

    return array_keys($used);
}

/** Return IDs of image attachments that are NOT used anywhere we consider “in use”. */
function wts_get_orphan_image_ids_smart() {
    global $wpdb;

    $used_ids = wts_collect_used_attachment_ids_smart();
    $in = !empty($used_ids) ? implode(',', array_map('intval', $used_ids)) : '0';

    // All image attachments not in the used set
    $orphans = $wpdb->get_col("
        SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_mime_type LIKE 'image/%'
          AND ID NOT IN ($in)
    ");

    return array_map('intval', (array)$orphans);
}

// ======================================
// ADMIN PAGE: FIND/DELETE ORPHANED MEDIA
// ======================================

function wts_find_orphaned_property_media() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.'));
    }

    echo '<div class="wrap"><h1>Orphaned Property Images</h1>';

    $deleted_count = null;

    // Handle delete ALL
    if (isset($_POST['wts_delete_orphans']) && check_admin_referer('wts_delete_orphans_action')) {
        $orphans = wts_get_orphan_image_ids_smart();

        if (!empty($orphans)) {
            $deleted = 0;
            foreach ($orphans as $id) {
                if (wp_delete_attachment($id, true)) { // true = skip trash
                    $deleted++;
                }
            }
            $deleted_count = $deleted;
            echo '<div class="notice notice-success is-dismissible"><p>Deleted ' . esc_html($deleted_count) . ' orphaned images.</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>No orphaned images found to delete.</p></div>';
        }
    }

    // Scan (always refresh list on load)
    $orphans = wts_get_orphan_image_ids_smart();

    echo '<p>This tool considers an image “in use” if it’s attached, set as a featured image, or referenced in Estatik/ACF fields (arrays or IDs). Everything else is treated as an orphan.</p>';

    // Rescan button
    echo '<form method="post" style="margin:10px 0;">';
    wp_nonce_field('wts_scan_orphans_action');
    echo '<button class="button button-primary" name="wts_scan_orphans" value="1">Scan for Orphaned Images</button>';
    echo '</form>';

    if (empty($orphans)) {
        echo '<div class="notice notice-success"><p>No orphaned images found.</p></div></div>';
        return;
    }

    // Delete button
    echo '<form method="post" style="margin:10px 0;">';
    wp_nonce_field('wts_delete_orphans_action');
    echo '<button class="button button-danger" name="wts_delete_orphans" value="1" onclick="return confirm(\'Delete ALL currently listed orphaned images? This cannot be undone.\')">Delete All Orphaned Images</button>';
    echo '</form>';

    // Grid of orphans
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;">';
    foreach ($orphans as $id) {
        $thumb = wp_get_attachment_image($id, 'thumbnail', false, ['style'=>'display:block;border:1px solid #e1e1e1;']);
        echo '<div style="font-size:12px;line-height:1.35;">';
        echo $thumb ? $thumb : '<div style="width:150px;height:150px;background:#f5f5f5;border:1px solid #ddd;"></div>';
        echo '<div style="margin-top:6px;">ID: ' . esc_html($id) . '</div>';
        echo '</div>';
    }
    echo '</div></div>';
}

function wts_register_orphaned_media_page() {
    add_submenu_page(
        'tools.php',
        'Property Images',
        'Property Images',
        'manage_options',
        'wts-orphaned-property-images',
        'wts_find_orphaned_property_media'
    );
}
add_action('admin_menu', 'wts_register_orphaned_media_page');

/**
 * Extendable: add_filter('wts_image_reference_meta_keys', function($keys){ $keys[]='my_gallery_meta'; return $keys; });
 */