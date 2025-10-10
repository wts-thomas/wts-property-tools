<?php

// ------------------------------
// Helpers: batched ID fetchers
// ------------------------------

// PUBLISHED → get IDs to move to DRAFT
function wts_get_published_property_ids_for_draft($limit = 100, $page = 1, $status_slugs = []) {
    if (empty($status_slugs)) {
        $status_slugs = [
            'expired', 'withdrawn', 'cancelled', 'contingent',
            'sold-inner-office', 'sold-co-op-w/mbr', 'sold-before-input', 'sold-other'
        ];
    }

    $args = [
        'post_type'                   => 'properties',
        'post_status'                 => 'publish',
        'posts_per_page'              => max(1, (int) $limit),
        'paged'                       => max(1, (int) $page),
        'fields'                      => 'ids',
        'orderby'                     => 'ID',
        'order'                       => 'ASC',
        'no_found_rows'               => true,   // avoid COUNT(*)
        'cache_results'               => false,
        'update_post_meta_cache'      => false,
        'update_post_term_cache'      => false,
        'suppress_filters'            => true,
        'tax_query'                   => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => $status_slugs,
                'operator' => 'IN',
            ],
        ],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);
    $q = new WP_Query($args);
    $ids = !empty($q->posts) ? $q->posts : [];
    wp_reset_postdata();

    $has_more = (count($ids) === (int) $limit);
    return [$ids, $has_more];
}

// DRAFT → get IDs to DELETE
function wts_get_draft_property_ids_for_delete($limit = 25, $page = 1, $status_slugs = []) {
    if (empty($status_slugs)) {
        $status_slugs = [
            'expired', 'withdrawn', 'cancelled', 'contingent',
            'sold-inner-office', 'sold-co-op-w/mbr', 'sold-before-input', 'sold-other'
        ];
    }

    $args = [
        'post_type'                   => 'properties',
        'post_status'                 => 'draft',
        'posts_per_page'              => max(1, (int) $limit),
        'paged'                       => max(1, (int) $page),
        'fields'                      => 'ids',
        'orderby'                     => 'ID',
        'order'                       => 'ASC',
        'no_found_rows'               => true,   // avoid COUNT(*)
        'cache_results'               => false,
        'update_post_meta_cache'      => false,
        'update_post_term_cache'      => false,
        'suppress_filters'            => true,
        'tax_query'                   => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => $status_slugs,
                'operator' => 'IN',
            ],
        ],
    ];

    if (function_exists('set_time_limit')) @set_time_limit(60);
    $q = new WP_Query($args);
    $ids = !empty($q->posts) ? $q->posts : [];
    wp_reset_postdata();

    $has_more = (count($ids) === (int) $limit);
    return [$ids, $has_more];
}


// ------------------------------
// AJAX: Draft one batch
// ------------------------------
add_action('wp_ajax_wts_draft_props_batch', 'wts_ajax_draft_props_batch');
function wts_ajax_draft_props_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('wts_draft_props_nonce', 'nonce');

    $page      = isset($_POST['page']) ? (int) $_POST['page'] : 1;
    $limit     = 100; // Draft batch size
    $slugs_csv = isset($_POST['slugs']) ? sanitize_text_field($_POST['slugs']) : '';
    $status_slugs = array_filter(array_map('trim', explode(',', $slugs_csv)));

    list($ids, $has_more) = wts_get_published_property_ids_for_draft($limit, $page, $status_slugs);

    $changed = 0;
    foreach ($ids as $post_id) {
        $res = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        if (!is_wp_error($res)) $changed++;
    }

    wp_send_json_success([
        'changed'  => $changed,
        'page'     => $page,
        'has_more' => $has_more,
        'next'     => $has_more ? ($page + 1) : null,
    ]);
}


// ------------------------------
// AJAX: Delete one batch
// ------------------------------
add_action('wp_ajax_wts_delete_props_batch', 'wts_ajax_delete_props_batch');
function wts_ajax_delete_props_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('wts_delete_props_nonce', 'nonce');

    $page      = isset($_POST['page']) ? (int) $_POST['page'] : 1;
    $limit     = 25; // Delete batch size
    $slugs_csv = isset($_POST['slugs']) ? sanitize_text_field($_POST['slugs']) : '';
    $status_slugs = array_filter(array_map('trim', explode(',', $slugs_csv)));

    list($ids, $has_more) = wts_get_draft_property_ids_for_delete($limit, $page, $status_slugs);

    $deleted = 0;
    foreach ($ids as $post_id) {
        if (wp_delete_post($post_id, true)) $deleted++;
    }

    wp_send_json_success([
        'deleted'  => $deleted,
        'page'     => $page,
        'has_more' => $has_more,
        'next'     => $has_more ? ($page + 1) : null,
    ]);
}


// ------------------------------
// Admin page
// ------------------------------
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
    // Default slugs shown to user & used by both AJAX flows
    $default_status_slugs = 'expired,withdrawn,cancelled,contingent,sold-inner-office,sold-co-op-w/mbr,sold-before-input,sold-other';

    $draft_nonce  = wp_create_nonce('wts_draft_props_nonce');
    $delete_nonce = wp_create_nonce('wts_delete_props_nonce');

    ?>
    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties</h1>
        <p>This tool runs in small, safe AJAX batches to avoid host timeouts.</p>

        <!-- STEP 1: Move Published → Draft -->
        <h2>Step 1: Move to Draft (AJAX Batches)</h2>
        <p>Moves all <strong>Published</strong> <code>properties</code> with these statuses to <strong>Draft</strong> in batches of <strong>100</strong>:</p>
        <p><code><?php echo esc_html($default_status_slugs); ?></code></p>

        <div id="wts-draft-status" class="notice" style="display:none;"><p></p></div>
        <p class="actions">
            <button id="wts-draft-start" class="button button-primary">Start Auto Draft</button>
            <button id="wts-draft-one"   class="button">Draft One Batch</button>
            <button id="wts-draft-stop"  class="button">Stop</button>
        </p>

        <hr>

        <!-- STEP 2: Delete Drafts -->
        <h2>Step 2: Delete Drafted Properties (AJAX Batches)</h2>
        <p>Deletes all <strong>Draft</strong> <code>properties</code> with these statuses in batches of <strong>25</strong>:</p>
        <p><code><?php echo esc_html($default_status_slugs); ?></code></p>

        <div id="wts-delete-status" class="notice" style="display:none;"><p></p></div>
        <p class="actions">
            <button id="wts-delete-start" class="button button-danger">Start Auto Delete</button>
            <button id="wts-delete-one"   class="button">Delete One Batch</button>
            <button id="wts-delete-stop"  class="button">Stop</button>
        </p>
    </div>

    <?php
    // Inline JS controller (uses admin jQuery)
    $slugs_js = esc_js($default_status_slugs);
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <script>
    (function($){
        // Shared config
        var delayMs = 2000; // 2 seconds between batches (raise to 3000 if needed)
        var ajaxUrl = "<?php echo esc_js($ajax_url); ?>";
        var slugs   = "<?php echo $slugs_js; ?>";

        // ---------------- Draft controller ----------------
        (function(){
            var running = false, page = 1;
            var nonce  = "<?php echo esc_js($draft_nonce); ?>";
            var $status = $('#wts-draft-status'),
                $start  = $('#wts-draft-start'),
                $one    = $('#wts-draft-one'),
                $stop   = $('#wts-draft-stop');

            function setStatus(msg, type){
                $status.removeClass('notice-info notice-success notice-error notice-warning')
                       .addClass('notice notice-' + (type || 'info'))
                       .show()
                       .find('p').text(msg);
            }
            function toggle(disabled){
                $start.prop('disabled', disabled);
                $one.prop('disabled', disabled);
                $stop.prop('disabled', !disabled ? true : false);
            }
            function runBatch(auto){
                $.post(ajaxUrl, {
                    action : 'wts_draft_props_batch',
                    nonce  : nonce,
                    page   : page,
                    slugs  : slugs
                }, null, 'json').done(function(resp){
                    if (!resp || !resp.success) {
                        setStatus('Draft error. Please try again.', 'error');
                        running = false; toggle(false); return;
                    }
                    var d = resp.data || {};
                    setStatus('Drafted ' + (d.changed||0) + ' items in batch ' + (d.page||'?') + (d.has_more ? ' …continuing' : ' …done'), d.has_more ? 'info' : 'success');

                    if (d.has_more && running && auto){
                        page = d.next || (page + 1);
                        setTimeout(function(){ runBatch(true); }, delayMs);
                    } else {
                        running = false; toggle(false);
                    }
                }).fail(function(){
                    setStatus('Network issue. Auto paused.', 'warning');
                    running = false; toggle(false);
                });
            }
            $start.on('click', function(e){ e.preventDefault();
                if (running) return; page = 1; running = true; toggle(true);
                setStatus('Starting auto draft in 2 seconds…','info');
                setTimeout(function(){ runBatch(true); }, delayMs);
            });
            $one.on('click', function(e){ e.preventDefault();
                if (running) return; running = true; toggle(true); runBatch(false);
            });
            $stop.on('click', function(e){ e.preventDefault();
                running = false; toggle(false); setStatus('Auto draft stopped.','warning');
            });
        })();

        // ---------------- Delete controller ----------------
        (function(){
            var running = false, page = 1;
            var nonce  = "<?php echo esc_js($delete_nonce); ?>";
            var $status = $('#wts-delete-status'),
                $start  = $('#wts-delete-start'),
                $one    = $('#wts-delete-one'),
                $stop   = $('#wts-delete-stop');

            function setStatus(msg, type){
                $status.removeClass('notice-info notice-success notice-error notice-warning')
                       .addClass('notice notice-' + (type || 'info'))
                       .show()
                       .find('p').text(msg);
            }
            function toggle(disabled){
                $start.prop('disabled', disabled);
                $one.prop('disabled', disabled);
                $stop.prop('disabled', !disabled ? true : false);
            }
            function runBatch(auto){
                $.post(ajaxUrl, {
                    action : 'wts_delete_props_batch',
                    nonce  : nonce,
                    page   : page,
                    slugs  : slugs
                }, null, 'json').done(function(resp){
                    if (!resp || !resp.success) {
                        setStatus('Delete error. Please try again.', 'error');
                        running = false; toggle(false); return;
                    }
                    var d = resp.data || {};
                    setStatus('Deleted ' + (d.deleted||0) + ' items in batch ' + (d.page||'?') + (d.has_more ? ' …continuing' : ' …done'), d.has_more ? 'info' : 'success');

                    if (d.has_more && running && auto){
                        page = d.next || (page + 1);
                        setTimeout(function(){ runBatch(true); }, delayMs);
                    } else {
                        running = false; toggle(false);
                    }
                }).fail(function(){
                    setStatus('Network issue. Auto paused.', 'warning');
                    running = false; toggle(false);
                });
            }
            $start.on('click', function(e){ e.preventDefault();
                if (running) return; page = 1; running = true; toggle(true);
                setStatus('Starting auto delete in 2 seconds…','info');
                setTimeout(function(){ runBatch(true); }, delayMs);
            });
            $one.on('click', function(e){ e.preventDefault();
                if (running) return; running = true; toggle(true); runBatch(false);
            });
            $stop.on('click', function(e){ e.preventDefault();
                running = false; toggle(false); setStatus('Auto delete stopped.','warning');
            });
        })();

    })(jQuery);
    </script>
    <?php
}
