<?php
// ================================
// BATCH DRAFT: Properties by es_status (Expired/Sold/Withdrawn…)
// ================================
function draft_properties_with_expired_status_batch($batch_size = 100, $page_num = 1, $status_slugs = []) {
    if (empty($status_slugs)) {
        $status_slugs = [
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

    $args = [
        'post_type'              => 'properties',
        'post_status'            => 'publish', // Only Published -> Draft
        'posts_per_page'         => max(1, (int)$batch_size),
        'paged'                  => max(1, (int)$page_num),
        'fields'                 => 'ids',
        'no_found_rows'          => false,     // we want found_posts/max pages
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query'              => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_title', $status_slugs),
            ],
        ],
    ];

    // Be more forgiving for long-running batches
    if (function_exists('set_time_limit')) @set_time_limit(60);

    $q = new WP_Query($args);

    $changed = 0;
    if (!empty($q->posts)) {
        foreach ($q->posts as $post_id) {
            $res = wp_update_post([
                'ID'          => $post_id,
                'post_status' => 'draft',
            ], true);
            if (!is_wp_error($res)) {
                $changed++;
            }
        }
    }

    wp_reset_postdata();

    return [
        'changed'     => $changed,
        'has_more'    => ($q->max_num_pages > $page_num),
        'total_found' => (int) $q->found_posts,
        'page'        => (int) $page_num,
        'batch_size'  => (int) $batch_size,
    ];
}


// ================================
// BATCH DELETE: Draft Properties by es_status
// ================================
function delete_draft_properties_with_status_batch($batch_size = 100, $page_num = 1, $status_slugs = []) {
    if (empty($status_slugs)) {
        $status_slugs = [
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

    $args = [
        'post_type'              => 'properties',
        'post_status'            => 'draft',   // Only Drafts -> Delete
        'posts_per_page'         => max(1, (int)$batch_size),
        'paged'                  => max(1, (int)$page_num),
        'fields'                 => 'ids',
        'no_found_rows'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query'              => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_title', $status_slugs),
            ],
        ],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);

    $q = new WP_Query($args);

    $deleted = 0;
    if (!empty($q->posts)) {
        foreach ($q->posts as $post_id) {
            if (wp_delete_post($post_id, true)) { // true = skip trash
                $deleted++;
            }
        }
    }

    wp_reset_postdata();

    return [
        'deleted'    => $deleted,
        'has_more'   => ($q->max_num_pages > $page_num),
        'total_found'=> (int) $q->found_posts,
        'page'       => (int) $page_num,
        'batch_size' => (int) $batch_size,
    ];
}


// ================================
// ADMIN PAGE
// ================================
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
    // Defaults (can be changed in the form)
    $default_status_slugs = 'expired,withdrawn,cancelled,contingent,sold-inner-office,sold-co-op-w/mbr,sold-before-input,sold-other';

    // --- Handle Draft Batch ---
    $draft_result = null;
    $draft_ran    = false;

    if (isset($_POST['wts_run_draft_expired']) && check_admin_referer('wts_draft_expired_action')) {
        $draft_batch_size = max(1, (int) ($_POST['wts_draft_batch_size'] ?? 100));
        $draft_page       = max(1, (int) ($_POST['wts_draft_page'] ?? 1));
        $auto_continue_d  = !empty($_POST['wts_draft_auto_continue']);
        $status_input_d   = trim($_POST['wts_draft_status_slugs'] ?? $default_status_slugs);
        $status_slugs_d   = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $status_input_d))));

        $draft_result = draft_properties_with_expired_status_batch($draft_batch_size, $draft_page, $status_slugs_d);
        $draft_ran    = true;
    }

    // --- Handle Delete Batch ---
    $delete_result = null;
    $delete_ran    = false;

    if (isset($_POST['wts_run_delete_draft_expired']) && check_admin_referer('wts_delete_draft_expired_action')) {
        $delete_batch_size = max(1, (int) ($_POST['wts_delete_batch_size'] ?? 100));
        $delete_page       = max(1, (int) ($_POST['wts_delete_page'] ?? 1));
        $auto_continue_del = !empty($_POST['wts_delete_auto_continue']);
        $status_input_del  = trim($_POST['wts_delete_status_slugs'] ?? $default_status_slugs);
        $status_slugs_del  = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $status_input_del))));

        $delete_result = delete_draft_properties_with_status_batch($delete_batch_size, $delete_page, $status_slugs_del);
        $delete_ran    = true;
    }

    ?>
    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties (Batch Mode)</h1>

        <!-- STEP 1: Move Published to Draft -->
        <h2>Step 1: Move to Draft (by Status)</h2>
        <p>Processes <code>properties</code> that are <strong>Published</strong> and have matching <code>es_status</code> terms. Runs in batches to avoid timeouts.</p>

        <?php if ($draft_ran && $draft_result): ?>
            <div class="notice notice-info">
                <p><strong>Draft Batch Results</strong></p>
                <ul style="margin-left:1em;">
                    <li>Batch page: <?php echo esc_html($draft_result['page']); ?></li>
                    <li>Batch size: <?php echo esc_html($draft_result['batch_size']); ?></li>
                    <li>Total matching (estimate): <?php echo esc_html($draft_result['total_found']); ?></li>
                    <li>Changed to Draft this batch: <strong><?php echo esc_html($draft_result['changed']); ?></strong></li>
                    <li>More batches available: <?php echo $draft_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('wts_draft_expired_action'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wts_draft_status_slugs">Status slugs</label></th>
                    <td>
                        <input type="text" class="regular-text" id="wts_draft_status_slugs" name="wts_draft_status_slugs"
                               value="<?php echo esc_attr($_POST['wts_draft_status_slugs'] ?? $default_status_slugs); ?>">
                        <p class="description">Comma-separated slugs in taxonomy <code>es_status</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wts_draft_batch_size">Batch size</label></th>
                    <td><input type="number" id="wts_draft_batch_size" name="wts_draft_batch_size" min="1" step="1"
                               value="<?php echo esc_attr($_POST['wts_draft_batch_size'] ?? 100); ?>"></td>
                </tr>
                <tr>
                    <th><label for="wts_draft_page">Batch page</label></th>
                    <td><input type="number" id="wts_draft_page" name="wts_draft_page" min="1" step="1"
                               value="<?php echo esc_attr($_POST['wts_draft_page'] ?? 1); ?>"></td>
                </tr>
                <tr>
                    <th>Auto-continue</th>
                    <td>
                        <label><input type="checkbox" name="wts_draft_auto_continue" value="1"
                            <?php checked(!empty($_POST['wts_draft_auto_continue'])); ?>> Run next batch automatically</label>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="wts_run_draft_expired" class="button button-primary" value="Run Draft Batch"></p>
        </form>

        <?php if ($draft_ran && $draft_result && $draft_result['has_more']): ?>
            <form method="post" id="wts-draft-next-form" style="display:none;">
                <?php wp_nonce_field('wts_draft_expired_action'); ?>
                <input type="hidden" name="wts_draft_status_slugs" value="<?php echo esc_attr($_POST['wts_draft_status_slugs'] ?? $default_status_slugs); ?>">
                <input type="hidden" name="wts_draft_batch_size"  value="<?php echo esc_attr($_POST['wts_draft_batch_size'] ?? 100); ?>">
                <input type="hidden" name="wts_draft_page"        value="<?php echo esc_attr(($draft_result['page'] + 1)); ?>">
                <input type="hidden" name="wts_draft_auto_continue" value="<?php echo !empty($_POST['wts_draft_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_draft_auto_continue'])): ?>
                <script>setTimeout(function(){document.getElementById('wts-draft-next-form').submit();}, 2000);</script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next draft batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_draft_expired_action'); ?>
                    <input type="hidden" name="wts_draft_status_slugs" value="<?php echo esc_attr($_POST['wts_draft_status_slugs'] ?? $default_status_slugs); ?>">
                    <input type="hidden" name="wts_draft_batch_size"  value="<?php echo esc_attr($_POST['wts_draft_batch_size'] ?? 100); ?>">
                    <input type="hidden" name="wts_draft_page"        value="<?php echo esc_attr(($draft_result['page'] + 1)); ?>">
                    <input type="hidden" name="wts_run_draft_expired" value="1">
                    <p><button class="button button-secondary">Run Next Draft Batch</button></p>
                </form>
            <?php endif; ?>
        <?php elseif ($draft_ran && $draft_result && !$draft_result['has_more']): ?>
            <div class="notice notice-success"><p><strong>Drafting complete.</strong></p></div>
        <?php endif; ?>

        <hr>

        <!-- STEP 2: Delete Drafts -->
        <h2>Step 2: Delete Drafted Properties (by Status)</h2>
        <p>Deletes <code>properties</code> that are currently <strong>Draft</strong> and have the selected <code>es_status</code> terms. Runs in batches for safety.</p>

        <?php if ($delete_ran && $delete_result): ?>
            <div class="notice notice-warning">
                <p><strong>Delete Batch Results</strong></p>
                <ul style="margin-left:1em;">
                    <li>Batch page: <?php echo esc_html($delete_result['page']); ?></li>
                    <li>Batch size: <?php echo esc_html($delete_result['batch_size']); ?></li>
                    <li>Total matching (estimate): <?php echo esc_html($delete_result['total_found']); ?></li>
                    <li>Deleted this batch: <strong><?php echo esc_html($delete_result['deleted']); ?></strong></li>
                    <li>More batches available: <?php echo $delete_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Are you sure? This permanently deletes matching draft posts.');">
            <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wts_delete_status_slugs">Status slugs</label></th>
                    <td>
                        <input type="text" class="regular-text" id="wts_delete_status_slugs" name="wts_delete_status_slugs"
                               value="<?php echo esc_attr($_POST['wts_delete_status_slugs'] ?? $default_status_slugs); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="wts_delete_batch_size">Batch size</label></th>
                    <td><input type="number" id="wts_delete_batch_size" name="wts_delete_batch_size" min="1" step="1"
                               value="<?php echo esc_attr($_POST['wts_delete_batch_size'] ?? 100); ?>"></td>
                </tr>
                <tr>
                    <th><label for="wts_delete_page">Batch page</label></th>
                    <td><input type="number" id="wts_delete_page" name="wts_delete_page" min="1" step="1"
                               value="<?php echo esc_attr($_POST['wts_delete_page'] ?? 1); ?>"></td>
                </tr>
                <tr>
                    <th>Auto-continue</th>
                    <td>
                        <label><input type="checkbox" name="wts_delete_auto_continue" value="1"
                            <?php checked(!empty($_POST['wts_delete_auto_continue'])); ?>> Run next batch automatically</label>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="wts_run_delete_draft_expired" class="button button-danger" value="Run Delete Batch"></p>
        </form>

        <?php if ($delete_ran && $delete_result && $delete_result['has_more']): ?>
            <form method="post" id="wts-delete-next-form" style="display:none;">
                <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                <input type="hidden" name="wts_delete_status_slugs" value="<?php echo esc_attr($_POST['wts_delete_status_slugs'] ?? $default_status_slugs); ?>">
                <input type="hidden" name="wts_delete_batch_size"  value="<?php echo esc_attr($_POST['wts_delete_batch_size'] ?? 100); ?>">
                <input type="hidden" name="wts_delete_page"        value="<?php echo esc_attr(($delete_result['page'] + 1)); ?>">
                <input type="hidden" name="wts_delete_auto_continue" value="<?php echo !empty($_POST['wts_delete_auto_continue']) ? '1' : ''; ?>">
                <input type="hidden" name="wts_run_delete_draft_expired" value="1">
            </form>
            <?php if (!empty($_POST['wts_delete_auto_continue'])): ?>
                <script>setTimeout(function(){document.getElementById('wts-delete-next-form').submit();}, 2000);</script>
                <div class="notice notice-info"><p>Auto-continue is ON. Next delete batch will run automatically…</p></div>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
                    <input type="hidden" name="wts_delete_status_slugs" value="<?php echo esc_attr($_POST['wts_delete_status_slugs'] ?? $default_status_slugs); ?>">
                    <input type="hidden" name="wts_delete_batch_size"  value="<?php echo esc_attr($_POST['wts_delete_batch_size'] ?? 100); ?>">
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