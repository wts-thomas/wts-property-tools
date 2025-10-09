<?php
// ================================
// DRAFT EXPIRED/WITHDRAWN PROPERTIES (IN BATCHES)
// ================================
function draft_properties_with_expired_status_batch() {
    $batch_size = 150; // process this many per run

    // Query all published 'properties' with specified es_status terms
    $args = [
        'post_type'              => 'properties',
        'post_status'            => 'publish',
        'posts_per_page'         => $batch_size,
        'fields'                 => 'ids',
        'no_found_rows'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query'              => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => [
                    'expired',
                    'withdrawn',
                    'cancelled',
                    'contingent',
                    'sold-inner-office',
                    'sold-co-op-w/mbr',
                    'sold-before-input',
                    'sold-other'
                ],
            ],
        ],
    ];

    $query = new WP_Query($args);
    $changed = 0;

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
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
        'has_more'    => ($query->max_num_pages > 1),
        'total_found' => intval($query->found_posts),
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
    $batch_run = false;
    $batch_result = null;
    $auto_continue = !empty($_POST['wts_auto_continue']);
    $batch_page = max(1, intval($_POST['wts_batch_page'] ?? 1));

    if (isset($_POST['wts_run_draft_expired']) && check_admin_referer('wts_draft_expired_action')) {
        $batch_result = draft_properties_with_expired_status_batch();
        $batch_run = true;
    }

    if (isset($_POST['wts_run_delete_draft_expired']) && check_admin_referer('wts_delete_draft_expired_action')) {
        $deleted = delete_draft_properties_with_expired_or_withdrawn_status();
        echo '<div class="notice notice-warning is-dismissible"><p>' .
             esc_html($deleted) .
             ' draft properties with status Expired, Sold, or Withdrawn were deleted.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties (Batch Mode)</h1>

        <p>This tool processes properties in smaller chunks (default 150 per batch) to avoid timeouts.</p>

        <?php if ($batch_run && $batch_result): ?>
            <div class="notice notice-info">
                <p><strong>Batch Results</strong></p>
                <ul style="margin-left:1em;">
                    <li>Batch page: <?php echo esc_html($batch_page); ?></li>
                    <li>Properties found: <?php echo esc_html($batch_result['total_found']); ?></li>
                    <li>Changed to Draft this batch: <strong><?php echo esc_html($batch_result['changed']); ?></strong></li>
                    <li>More batches available: <?php echo $batch_result['has_more'] ? '<strong>Yes</strong>' : 'No'; ?></li>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('wts_draft_expired_action'); ?>
            <input type="hidden" name="wts_batch_page" value="<?php echo esc_attr($batch_page); ?>" />
            <p><strong>Step 1:</strong> Move all <code>Published</code> properties with <strong>Expired, Sold</strong> or <strong>Withdrawn</strong> status to <strong>Draft</strong>.</p>
            <p>
                <label>
                    <input type="checkbox" name="wts_auto_continue" value="1" <?php checked(!empty($_POST['wts_auto_continue'])); ?> />
                    Automatically continue through all batches
                </label>
            </p>
            <input type="submit" name="wts_run_draft_expired" class="button button-primary" value="Run Batch">
        </form>

        <?php if ($batch_run && $batch_result && $batch_result['has_more']): ?>
            <form method="post" id="wts-next-batch-form" style="display:none;">
                <?php wp_nonce_field('wts_draft_expired_action'); ?>
                <input type="hidden" name="wts_batch_page" value="<?php echo esc_attr($batch_page + 1); ?>" />
                <input type="hidden" name="wts_run_draft_expired" value="1" />
                <?php if ($auto_continue): ?><input type="hidden" name="wts_auto_continue" value="1"><?php endif; ?>
            </form>

            <?php if ($auto_continue): ?>
                <div class="notice notice-info"><p>Auto-continue is ON. Next batch will start automatically.</p></div>
                <script>setTimeout(function(){document.getElementById('wts-next-batch-form').submit();},1000);</script>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('wts_draft_expired_action'); ?>
                    <input type="hidden" name="wts_batch_page" value="<?php echo esc_attr($batch_page + 1); ?>" />
                    <input type="hidden" name="wts_run_draft_expired" value="1" />
                    <p><button class="button button-secondary">Run Next Batch</button></p>
                </form>
            <?php endif; ?>
        <?php elseif ($batch_run && $batch_result && !$batch_result['has_more']): ?>
            <div class="notice notice-success"><p><strong>All done!</strong> No more matching published properties found.</p></div>
        <?php endif; ?>

        <hr>

        <p><strong>Step 2:</strong> Permanently delete all <code>Draft</code> properties with <strong>Expired, Sold</strong> or <strong>Withdrawn</strong> status.</p>
        <form method="post">
            <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
            <input type="submit" name="wts_run_delete_draft_expired" class="button button-danger"
                   value="Delete Drafted Properties"
                   onclick="return confirm('Are you sure? This will permanently delete all matching draft posts.')" />
        </form>
    </div>
    <?php
}


// ================================
// DELETE DRAFT PROPERTIES
// ================================
function delete_draft_properties_with_expired_or_withdrawn_status() {
    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'draft',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => [
                    'expired',
                    'withdrawn',
                    'cancelled',
                    'contingent',
                    'sold-inner-office',
                    'sold-co-op-w/mbr',
                    'sold-before-input',
                    'sold-other'
                ],
            ],
        ],
    ];

    $query = new WP_Query($args);
    $count = 0;

    if ($query->have_posts()) {
        foreach ($query->posts as $post_id) {
            wp_delete_post($post_id, true);
            $count++;
        }
    }

    wp_reset_postdata();
    return $count;
}