<?php
/**
 * Tools → Property Statuses
 * Batched Draft & Delete with host-friendly queries
 */

// Common default status slugs used by both steps.
function wts_default_status_slugs() {
    return [
        'expired', 'withdrawn', 'cancelled', 'contingent',
        'sold-inner-office', 'sold-co-op-w/mbr', 'sold-before-input', 'sold-other',
    ];
}

/**
 * Get a batch of PUBLISHED property IDs to move to draft.
 * Host-friendly: no_found_rows true + no cache + fields ids.
 */
function wts_get_publish_ids_for_draft($batch_size, $page_num, $status_slugs = []) {
    if (empty($status_slugs)) $status_slugs = wts_default_status_slugs();

    $args = [
        'post_type'              => 'properties',
        'post_status'            => 'publish',
        'posts_per_page'         => max(1, (int)$batch_size),
        'paged'                  => max(1, (int)$page_num),
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,   // ← avoid COUNT(*)
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'suppress_filters'       => true,
        'tax_query'              => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => $status_slugs,
                'operator' => 'IN',
            ],
        ],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);
    if (function_exists('ignore_user_abort')) @ignore_user_abort(true);

    $q   = new WP_Query($args);
    $ids = !empty($q->posts) ? $q->posts : [];
    wp_reset_postdata();

    $has_more = (count($ids) === (int)$batch_size);
    return [$ids, $has_more];
}

/**
 * Get a batch of DRAFT property IDs to delete.
 * Host-friendly as above.
 */
function wts_get_draft_ids_for_delete($batch_size, $page_num, $status_slugs = []) {
    if (empty($status_slugs)) $status_slugs = wts_default_status_slugs();

    $args = [
        'post_type'              => 'properties',
        'post_status'            => 'draft',
        'posts_per_page'         => max(1, (int)$batch_size),
        'paged'                  => max(1, (int)$page_num),
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,   // ← avoid COUNT(*)
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'suppress_filters'       => true,
        'tax_query'              => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => $status_slugs,
                'operator' => 'IN',
            ],
        ],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);
    if (function_exists('ignore_user_abort')) @ignore_user_abort(true);

    $q   = new WP_Query($args);
    $ids = !empty($q->posts) ? $q->posts : [];
    wp_reset_postdata();

    $has_more = (count($ids) === (int)$batch_size);
    return [$ids, $has_more];
}

/**
 * Run one “move to draft” batch.
 * Returns array: changed, has_more, page.
 */
function draft_properties_with_expired_status_batch($batch_size = 100, $page_num = 1, $status_slugs = []) {
    list($ids, $has_more) = wts_get_publish_ids_for_draft($batch_size, $page_num, $status_slugs);

    $changed = 0;
    foreach ($ids as $post_id) {
        // Minimal update to reduce hooks cost
        $res = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        if (!is_wp_error($res)) $changed++;
    }

    return [
        'changed'  => $changed,
        'has_more' => $has_more,
        'page'     => (int)$page_num,
    ];
}

/**
 * Run one “delete drafts” batch.
 * Returns array: deleted, has_more, page.
 */
function delete_draft_properties_with_status_batch($batch_size = 5, $page_num = 1, $status_slugs = []) {
    list($ids, $has_more) = wts_get_draft_ids_for_delete($batch_size, $page_num, $status_slugs);

    $deleted = 0;
    foreach ($ids as $post_id) {
        if (wp_delete_post($post_id, true)) $deleted++;
    }

    return [
        'deleted'  => $deleted,
        'has_more' => $has_more,
        'page'     => (int)$page_num,
    ];
}

/* ---------------------------
 * Admin page & forms
 * --------------------------*/
add_action('admin_menu', 'wts_register_expired_draft_admin_page');
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

function wts_expired_draft_admin_page() {
    $default_status_slugs = implode(',', wts_default_status_slugs());

    // Draft batch
    $draft_result = null; $draft_ran = false;
    if (isset($_POST['wts_run_draft_expired']) && check_admin_referer('wts_draft_expired_action')) {
        $page = isset($_POST['wts_draft_page']) ? (int) $_POST['wts_draft_page'] : 1;
        $draft_result = draft_properties_with_expired_status_batch(100, $page);
        $draft_ran = true;
    }

    // Delete batch
    $delete_result = null; $delete_ran = false;
    if (isset($_POST['wts_run_delete_draft_expired']) && check_admin_referer('wts_delete_draft_expired_action')) {
        $page = isset($_POST['wts_delete_page']) ? (int) $_POST['wts_delete_page'] : 1;
        $delete_result = delete_draft_properties_with_status_batch(5, $page);
        $delete_ran = true;
    }
    ?>
    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties</h1>

        <!-- STEP 1: Move to Draft -->
        <h2>Step 1: Move to Draft (Batch Mode)</h2>
        <p>Moves all <strong>Published</strong> properties with matching statuses to <strong>Draft</strong> in batches of <strong>100</strong>.</p>
        <p><code><?php echo esc_html($default_status_slugs); ?></code></p>

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
            <input type="hidden" name="wts_draft_page" value="<?php echo esc_attr($_POST['wts_draft_page'] ?? 1); ?>">
            <p>
                <label>
                    <input type="checkbox" name="wts_draft_auto_continue" value="1" <?php checked(!empty($_POST['wts_draft_auto_continue'])); ?>>
                    Automatically continue through all batches
                </label>
            </p>
            <p><input type="submit" name="wts_run_draft_expired" class="button button-primary" value="Run Draft Batch"></p>
        </form>

        <?php if ($draft_ran && $draft_result && $draft_result['has_more']): ?>
            <form method="post" id="wts-draft-next-form" style="display:none;">
                <?php wp_nonce_field('wts_draft_expired_action'); ?>
                <input type="hidden" name="wts_draft_page" value="<?php echo esc_attr($draft_result['page'] + 1); ?>">
                <input type="hidden" name="wts_draft_auto_continue" value="<?php echo !empty($_POST['wts_draft_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_draft_auto_continue'])): ?>
                <script>setTimeout(function(){document.getElementById('wts-draft-next-form').submit();}, 2000);</script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next draft batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_draft_expired_action'); ?>
                    <input type="hidden" name="wts_draft_page" value="<?php echo esc_attr($draft_result['page'] + 1); ?>">
                    <input type="hidden" name="wts_run_draft_expired" value="1">
                    <p><button class="button button-secondary">Run Next Draft Batch</button></p>
                </form>
            <?php endif; ?>
        <?php elseif ($draft_ran && $draft_result && !$draft_result['has_more']): ?>
            <div class="notice notice-success"><p><strong>Drafting complete.</strong></p></div>
        <?php endif; ?>

        <hr>

        <!-- STEP 2: Delete Drafts -->
        <h2>Step 2: Delete Drafted Properties (Batch Mode)</h2>
        <p>Deletes all <strong>Draft</strong> properties with matching statuses in batches of <strong>5</strong>.</p>
        <p><code><?php echo esc_html($default_status_slugs); ?></code></p>

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

        <form method="post" onsubmit="return confirm('Are you sure? This permanently deletes matching draft posts.')">
            <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
            <input type="hidden" name="wts_delete_page" value="<?php echo esc_attr($_POST['wts_delete_page'] ?? 1); ?>">
            <p>
                <label>
                    <input type="checkbox" name="wts_delete_auto_continue" value="1" <?php checked(!empty($_POST['wts_delete_auto_continue'])); ?>>
                    Automatically continue through all batches
                </label>
            </p>
            <p><input type="submit" name="wts_run_delete_draft_expired" class="button button-danger" value="Run Delete Batch"></p>
        </form>

        <?php if ($delete_ran && $delete_result && $delete_result['has_more']): ?>
            <form method="post" id="wts-delete-next-form" style="display:none;">
                <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                <input type="hidden" name="wts_delete_page" value="<?php echo esc_attr($delete_result['page'] + 1); ?>">
                <input type="hidden" name="wts_delete_auto_continue" value="<?php echo !empty($_POST['wts_delete_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_delete_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_delete_auto_continue'])): ?>
                <script>setTimeout(function(){document.getElementById('wts-delete-next-form').submit();}, 2000);</script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next delete batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                    <input type="hidden" name="wts_delete_page" value="<?php echo esc_attr($delete_result['page'] + 1); ?>">
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