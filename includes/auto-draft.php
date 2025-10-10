<?php
/**
 * Property Status Tools (Batch Draft + Batch Delete w/ media preserved)
 * Drop this into your plugin as auto-draft.php or similar.
 */

// ---------------------------
// Defaults
// ---------------------------
function wts_status_slugs_default() {
    // Slugs to act on (taxonomy: es_status)
    return [
        'expired',
        'withdrawn',
        'cancelled',
        'contingent',
        'sold-inner-office',
        'sold-co-op-w/mbr',
        'sold-before-input',
        'sold-other',
    ];
}
function wts_draft_batch_size_default()  { return 100; } // draft 100 at a time
function wts_delete_batch_size_default() { return 5;   } // delete 5 at a time (very safe)
function wts_auto_delay_ms()             { return 2000; } // 2s pause between batches

// ---------------------------
// Batch: Move to Draft
// ---------------------------
function draft_properties_with_expired_status_batch($batch_size = null, $page_num = 1, $status_slugs = []) {
    if ($batch_size === null) $batch_size = wts_draft_batch_size_default();
    if (empty($status_slugs)) $status_slugs = wts_status_slugs_default();

    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $batch_size,
        'paged'          => (int) $page_num,
        'fields'         => 'ids',
        'no_found_rows'  => false, // we need max_num_pages
        'tax_query'      => [[
            'taxonomy' => 'es_status',
            'field'    => 'slug',
            'terms'    => $status_slugs,
        ]],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);

    $q = new WP_Query($args);
    $changed = 0;

    if (!empty($q->posts)) {
        foreach ($q->posts as $post_id) {
            $res = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
            if (!is_wp_error($res)) {
                clean_post_cache($post_id);
                $changed++;
            }
        }
    }
    wp_reset_postdata();

    return [
        'changed'     => $changed,
        'has_more'    => ($q->max_num_pages > (int) $page_num),
        'total_found' => (int) $q->found_posts,
        'page'        => (int) $page_num,
    ];
}

// ---------------------------
// Batch: Delete Drafts (preserve media)
//   - removes post row
//   - removes postmeta
//   - removes term relationships
//   - DOES NOT delete attachments
//   - NEW: detaches any attachments first (post_parent=0, post_author=0)
// ---------------------------
function delete_draft_properties_with_status_batch($batch_size = null, $page_num = 1, $status_slugs = []) {
    if ($batch_size === null) $batch_size = wts_delete_batch_size_default();
    if (empty($status_slugs)) $status_slugs = wts_status_slugs_default();

    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'draft',
        'posts_per_page' => (int) $batch_size,
        'paged'          => (int) $page_num,
        'fields'         => 'ids',
        'no_found_rows'  => false, // we need max_num_pages
        'tax_query'      => [[
            'taxonomy' => 'es_status',
            'field'    => 'slug',
            'terms'    => $status_slugs,
        ]],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);

    $q = new WP_Query($args);
    $deleted = 0;

    if (!empty($q->posts)) {
        global $wpdb;

        foreach ($q->posts as $post_id) {
            // Clean cache for the property first
            clean_post_cache($post_id);

            // --- NEW: DETACH ATTACHMENTS (keep media, remove DB link) ---
            // 1) Get IDs of attachments that have this property as parent
            $att_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent=%d",
                (int)$post_id
            ) );
            if (!empty($att_ids)) {
                // 2) Set post_parent=0 and post_author=0 in one go
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_parent=0, post_author=0 WHERE post_type='attachment' AND post_parent=%d",
                    (int)$post_id
                ) );
                // 3) Clear attachment object caches
                foreach ($att_ids as $aid) {
                    clean_post_cache((int)$aid);
                }
            }
            // --- end detach ---

            // Remove term relationships & postmeta (keeps media untouched)
            $wpdb->delete($wpdb->term_relationships, ['object_id' => $post_id]);
            $wpdb->delete($wpdb->postmeta,           ['post_id'   => $post_id]);

            // Remove the post itself (only the property row)
            $wpdb->delete($wpdb->posts, ['ID' => $post_id, 'post_type' => 'properties']);

            // Best effort cache clear
            wp_cache_delete($post_id, 'posts');

            $deleted++;
        }
    }
    wp_reset_postdata();

    return [
        'deleted'     => $deleted,
        'has_more'    => ($q->max_num_pages > (int) $page_num),
        'total_found' => (int) $q->found_posts,
        'page'        => (int) $page_num,
    ];
}

// ---------------------------
// Admin Page
// ---------------------------
function wts_register_expired_draft_admin_page() {
    add_submenu_page(
        'tools.php',
        'Property Statuses',
        'Property Statuses',
        'manage_options',
        'wts-draft-expired-properties',
        'wts_expired_draft_admin_page'
    );
}
add_action('admin_menu', 'wts_register_expired_draft_admin_page');

function wts_expired_draft_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Hidden defaults
    $status_slugs_str   = implode(',', wts_status_slugs_default());
    $draft_batch_size   = wts_draft_batch_size_default();
    $delete_batch_size  = wts_delete_batch_size_default();
    $delay_ms           = wts_auto_delay_ms();

    // ---- Run Draft Batch
    $draft_result = null; $draft_ran = false;
    if (isset($_POST['wts_run_draft_expired']) && check_admin_referer('wts_draft_expired_action')) {
        $page = isset($_POST['wts_draft_page']) ? (int) $_POST['wts_draft_page'] : 1;
        $draft_result = draft_properties_with_expired_status_batch($draft_batch_size, $page);
        $draft_ran = true;
    }

    // ---- Run Delete Batch
    $delete_result = null; $delete_ran = false;
    if (isset($_POST['wts_run_delete_draft_expired']) && check_admin_referer('wts_delete_draft_expired_action')) {
        $page = isset($_POST['wts_delete_page']) ? (int) $_POST['wts_delete_page'] : 1;
        $delete_result = delete_draft_properties_with_status_batch($delete_batch_size, $page);
        $delete_ran = true;
    }

    ?>
    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties</h1>

        <?php if ($delete_ran && $delete_result): ?>
            <div class="notice notice-warning">
                <p><strong>Delete Batch Results</strong></p>
                <ul>
                    <li>Batch page: <?php echo esc_html($delete_result['page']); ?></li>
                    <li>Deleted this batch: <strong><?php echo esc_html($delete_result['deleted']); ?></strong></li>
                    <li>More batches available: <?php echo $delete_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Step 1 -->
        <h2>Step 1: Move to Draft (Batch Mode)</h2>
        <p>Moves all <strong>Published</strong> properties with the target statuses to <strong>Draft</strong>. Batch size: <code><?php echo (int) $draft_batch_size; ?></code></p>

        <?php if ($draft_ran && $draft_result): ?>
            <div class="notice notice-info">
                <p><strong>Draft Batch Results</strong></p>
                <ul>
                    <li>Batch page: <?php echo esc_html($draft_result['page']); ?></li>
                    <li>Changed to Draft this batch: <strong><?php echo esc_html($draft_result['changed']); ?></strong></li>
                    <li>More batches available: <?php echo $draft_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('wts_draft_expired_action'); ?>
            <input type="hidden" name="wts_draft_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
            <input type="hidden" name="wts_draft_batch_size"  value="<?php echo (int) $draft_batch_size; ?>">
            <input type="hidden" name="wts_draft_page"        value="<?php echo esc_attr($_POST['wts_draft_page'] ?? 1); ?>">
            <p>
                <label>
                    <input type="checkbox" name="wts_draft_auto_continue" value="1" <?php checked(!empty($_POST['wts_draft_auto_continue'])); ?>>
                    Automatically continue through all batches
                </label>
            </p>
            <p><input type="submit" name="wts_run_draft_expired" class="button button-primary" value="Run Draft Batch"></p>
        </form>

        <?php if ($draft_ran && $draft_result && $draft_result['has_more']): ?>
            <!-- Auto-continue (hidden form) -->
            <form method="post" id="wts-draft-next-form" style="display:none;">
                <?php wp_nonce_field('wts_draft_expired_action'); ?>
                <input type="hidden" name="wts_draft_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
                <input type="hidden" name="wts_draft_batch_size"  value="<?php echo (int) $draft_batch_size; ?>">
                <input type="hidden" name="wts_draft_page"        value="<?php echo esc_attr($draft_result['page'] + 1); ?>">
                <input type="hidden" name="wts_draft_auto_continue" value="<?php echo !empty($_POST['wts_draft_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_draft_auto_continue'])): ?>
                <script>
                    setTimeout(function(){ document.getElementById('wts-draft-next-form').submit(); }, <?php echo (int) $delay_ms; ?>);
                </script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next draft batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_draft_expired_action'); ?>
                    <input type="hidden" name="wts_draft_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
                    <input type="hidden" name="wts_draft_batch_size"  value="<?php echo (int) $draft_batch_size; ?>">
                    <input type="hidden" name="wts_draft_page"        value="<?php echo esc_attr(($draft_result['page'] + 1)); ?>">
                    <input type="hidden" name="wts_run_draft_expired" value="1">
                    <p><button class="button button-secondary">Run Next Draft Batch</button></p>
                </form>
            <?php endif; ?>
        <?php elseif ($draft_ran && $draft_result && !$draft_result['has_more']): ?>
            <div class="notice notice-success"><p><strong>Drafting complete.</strong></p></div>
        <?php endif; ?>

        <hr>

        <!-- Step 2 -->
        <h2>Step 2: Delete Drafted Properties (Batch Mode)</h2>
        <p>Deletes <strong>Draft</strong> properties with the target statuses in very small, safe batches. Media files are <strong>preserved</strong>. Batch size: <code><?php echo (int) $delete_batch_size; ?></code></p>

        <?php if ($delete_ran && $delete_result): ?>
            <div class="notice notice-warning">
                <p><strong>Delete Batch Results</strong></p>
                <ul>
                    <li>Batch page: <?php echo esc_html($delete_result['page']); ?></li>
                    <li>Deleted this batch: <strong><?php echo esc_html($delete_result['deleted']); ?></strong></li>
                    <li>More batches available: <?php echo $delete_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Are you sure? This permanently deletes the matching draft posts (media is preserved).')">
            <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
            <input type="hidden" name="wts_delete_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
            <input type="hidden" name="wts_delete_batch_size"  value="<?php echo (int) $delete_batch_size; ?>">
            <input type="hidden" name="wts_delete_page"        value="<?php echo esc_attr($_POST['wts_delete_page'] ?? 1); ?>">
            <p>
                <label>
                    <input type="checkbox" name="wts_delete_auto_continue" value="1" <?php checked(!empty($_POST['wts_delete_auto_continue'])); ?>>
                    Automatically continue through all batches
                </label>
            </p>
            <p><input type="submit" name="wts_run_delete_draft_expired" class="button button-danger" value="Run Delete Batch"></p>
        </form>

        <?php if ($delete_ran && $delete_result && $delete_result['has_more']): ?>
            <!-- Auto-continue (hidden form) -->
            <form method="post" id="wts-delete-next-form" style="display:none;">
                <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                <input type="hidden" name="wts_delete_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
                <input type="hidden" name="wts_delete_batch_size"  value="<?php echo (int) $delete_batch_size; ?>">
                <input type="hidden" name="wts_delete_page"        value="<?php echo esc_attr(($delete_result['page'] + 1)); ?>">
                <input type="hidden" name="wts_delete_auto_continue" value="<?php echo !empty($_POST['wts_delete_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_delete_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_delete_auto_continue'])): ?>
                <script>
                    setTimeout(function(){ document.getElementById('wts-delete-next-form').submit(); }, <?php echo (int) $delay_ms; ?>);
                </script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next delete batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                    <input type="hidden" name="wts_delete_status_slugs" value="<?php echo esc_attr($status_slugs_str); ?>">
                    <input type="hidden" name="wts_delete_batch_size"  value="<?php echo (int) $delete_batch_size; ?>">
                    <input type="hidden" name="wts_delete_page"        value="<?php echo esc_attr(($delete_result['page'] + 1)); ?>">
                    <input type="hidden" name="wts_run_delete_draft_expired" value="1">
                    <p><button class="button button-secondary">Run Next Delete Batch</button></p>
                </form>
            <?php endif; ?>
        <?php elseif ($delete_ran && $delete_result && !$delete_result['has_more']): ?>
            <div class="notice notice-success"><p><strong>Deletion complete.</strong></p></div>
        <?php endif; ?>
    </div>
    <?php
}