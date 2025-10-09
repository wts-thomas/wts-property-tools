<?php

function draft_properties_with_expired_status() {
    // Query all published 'properties' posts with the 'Expired' es_statuses term
    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'tax_query'      => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => ['expired', 'withdrawn', 'cancelled', 'contingent', 'sold-inner-office', 'sold-co-op-w/mbr', 'sold-before-input', 'sold-other'], // Note: use slug not label
            ],
        ],
    ];

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        foreach ($query->posts as $post) {
            wp_update_post([
                'ID' => $post->ID,
                'post_status' => 'draft',
            ]);
        }

        echo count($query->posts) . " properties with 'Expired, Withdrawn, Sold, etc.' status were marked as draft.";
    } else {
        echo "No published properties found with 'Expired or Sold etc.' status.";
    }

    wp_reset_postdata();
}

/////////

function wts_register_expired_draft_admin_page() {
    add_submenu_page(
        'tools.php', // parent menu (Tools)
        'Property Statuses',
        'Property Statuses',
        'manage_options',
        'wts-draft-expired-properties',
        'wts_expired_draft_admin_page'
    );
}
add_action('admin_menu', 'wts_register_expired_draft_admin_page');

//////

function wts_expired_draft_admin_page() {
    if (isset($_POST['wts_run_draft_expired']) && check_admin_referer('wts_draft_expired_action')) {
        ob_start();
        draft_properties_with_expired_status(); // your existing draft function
        $message = ob_get_clean();

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    if (isset($_POST['wts_run_delete_draft_expired']) && check_admin_referer('wts_delete_draft_expired_action')) {
        $deleted = delete_draft_properties_with_expired_or_withdrawn_status();
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($deleted) . ' draft properties with status Expired, Sold, or etc. were deleted.</p></div>';
    }
    ?>

    <div class="wrap">
        <h1>Manage Expired/Withdrawn Properties</h1>

        <p><strong>Step 1:</strong> Click to move all <code>Published</code> properties with <strong>Expired, Sold</strong> or <strong>Withdrawn Etc.</strong> status to <strong>Draft</strong>.</p>
        <form method="post">
            <?php wp_nonce_field('wts_draft_expired_action'); ?>
            <input type="submit" name="wts_run_draft_expired" class="button button-primary" value="Mark as Draft">
        </form>

        <hr>

        <p><strong>Step 2:</strong> Click to permanently delete all <code>Draft</code> properties with <strong>Expired, Sold</strong> or <strong>Withdrawn Etc.</strong> status.</p>
        <form method="post">
            <?php wp_nonce_field('wts_delete_draft_expired_action'); ?>
            <input type="submit" name="wts_run_delete_draft_expired" class="button button-danger" value="Delete Drafted Properties" onclick="return confirm('Are you sure? This will permanently delete all matching draft posts.')">
        </form>
    </div>
    <?php
}


// DELETE DRAFT POSTS
function delete_draft_properties_with_expired_or_withdrawn_status() {
    $args = [
        'post_type'      => 'properties',
        'post_status'    => 'draft',
        'posts_per_page' => -1,
        'tax_query'      => [
            [
                'taxonomy' => 'es_status',
                'field'    => 'slug',
                'terms'    => ['expired', 'withdrawn', 'cancelled', 'contingent', 'sold-inner-office', 'sold-co-op-w/mbr', 'sold-before-input', 'sold-other'],
            ],
        ],
    ];

    $query = new WP_Query($args);
    $count = 0;

    if ($query->have_posts()) {
        foreach ($query->posts as $post) {
            wp_delete_post($post->ID, true); // true = force delete, skips trash
            $count++;
        }
    }

    wp_reset_postdata();
    return $count;
}
