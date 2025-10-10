<?php

// ================================
// ADMIN PAGE: FIND ORPHANED PROPERTY MEDIA
// ================================
function wts_find_orphaned_property_media() {
    global $wpdb;

    echo '<div class="wrap"><h1>Orphaned Property Images</h1>';

    // Handle delete action
    if (isset($_POST['wts_delete_orphans']) && check_admin_referer('wts_delete_orphans_action')) {
        $orphans = $wpdb->get_col("
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
              AND post_mime_type LIKE 'image/%'
              AND post_parent = 0
              AND post_author = 0
        ");

        if (!empty($orphans)) {
            foreach ($orphans as $id) {
                wp_delete_attachment($id, true); // true = force delete (skip trash)
            }
            echo '<div class="notice notice-success is-dismissible"><p>Deleted ' . count($orphans) . ' orphaned images.</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>No orphaned images found to delete.</p></div>';
        }
    }

    // Find current orphans
    $orphans = $wpdb->get_results("
        SELECT ID, post_title, guid
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_mime_type LIKE 'image/%'
          AND post_parent = 0
          AND post_author = 0
    ");

    if (empty($orphans)) {
        echo '<p>No orphaned images found.</p></div>';
        return;
    }

    echo '<p>The following images are in the Media Library but not linked to any Property or post.</p>';

    // Delete button form
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('wts_delete_orphans_action');
    echo '<input type="submit" name="wts_delete_orphans" class="button button-danger" value="Delete All Orphaned Images" onclick="return confirm(\'Are you sure? This will permanently delete all orphaned images.\')">';
    echo '</form>';

    // Show orphan images
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:20px;">';

    foreach ($orphans as $img) {
        echo '<div style="text-align:center;">';
        echo '<img src="' . esc_url($img->guid) . '" style="max-width:150px;height:auto;"><br>';
        echo esc_html($img->post_title) . '<br>ID: ' . $img->ID . ' (No Author, No Parent)';
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
