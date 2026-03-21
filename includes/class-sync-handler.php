<?php
/**
 * Sync Handler for Content Tracker Sync.
 *
 * Handles the AJAX sync request: collects post data, converts tags,
 * and writes to Google Sheets via CTS_Google_Sheets_Service.
 *
 * @package ContentTrackerSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class CTS_Sync_Handler
 */
class CTS_Sync_Handler
{

    /**
     * AJAX action name.
     *
     * @var string
     */
    const AJAX_ACTION = 'cts_sync_post';

    /**
     * Nonce action string.
     *
     * @var string
     */
    const NONCE_ACTION = 'cts_sync_nonce';

    /**
     * Constructor — register hooks.
     */
    public function __construct()
    {

        // AJAX handler (logged-in users only).
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'handle_sync'));

        // Enqueue editor assets.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_editor_assets'));

        // Add meta box with sync button for classic editor.
        add_action('add_meta_boxes', array($this, 'add_sync_meta_box'));
    }

    /*--------------------------------------------------------------
     * Meta Box (Classic Editor Sync Button)
     *------------------------------------------------------------*/

    /**
     * Register the Sync to Tracker meta box.
     *
     * @return void
     */
    public function add_sync_meta_box()
    {

        // Only show to users who can edit posts.
        if (!current_user_can('edit_posts')) {
            return;
        }

        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'cts_sync_meta_box',
                __('Content Tracker Sync', 'content-tracker-sync'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the meta box HTML.
     *
     * @param  WP_Post $post Current post object.
     * @return void
     */
    public function render_meta_box($post)
    {

        $last_synced = get_post_meta($post->ID, '_cts_last_synced', true);
?>
        <div id="cts-sync-wrapper">
            <p class="cts-description">
                <?php esc_html_e('Send this post\'s data to your Google Sheets editorial tracker.', 'content-tracker-sync'); ?>
            </p>

            <p class="cts-branding-text">
                <?php esc_html_e('Built by Krish Goswami', 'content-tracker-sync'); ?>
            </p>

            <button type="button" id="cts-sync-button" class="button button-primary button-large" style="width:100%;text-align:center;">
                <?php esc_html_e('Sync to Tracker', 'content-tracker-sync'); ?>
            </button>

            <div id="cts-sync-status" style="margin-top:10px;">
                <?php if ($last_synced): ?>
                    <span class="cts-status-success">
                        ✓ <?php
            /* translators: %s: human-readable time difference */
            printf(
                esc_html__('Last synced %s ago', 'content-tracker-sync'),
                esc_html(human_time_diff(strtotime($last_synced), time()))
            );
?>
                    </span>
                <?php
        endif; ?>
            </div>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * Editor Assets
     *------------------------------------------------------------*/

    /**
     * Enqueue JS and CSS on post editor screens.
     *
     * @param  string $hook_suffix Current admin page.
     * @return void
     */
    public function enqueue_editor_assets($hook_suffix)
    {

        // Only on post-new and post-edit screens.
        if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'cts-admin',
            CTS_PLUGIN_URL . 'assets/admin.css',
            array(),
            CTS_VERSION
        );

        // Include Gutenberg dependencies so PluginPostStatusInfo registers correctly.
        $script_deps = array(
            'jquery',
            'wp-plugins',
            'wp-edit-post',
            'wp-element',
            'wp-components',
            'wp-data',
            'wp-editor',
        );

        wp_enqueue_script(
            'cts-admin',
            CTS_PLUGIN_URL . 'assets/admin.js',
            $script_deps,
            CTS_VERSION,
            true
        );

        // Use get_post() instead of unreliable global $post (may be null on post-new.php).
        $current_post = get_post();
        wp_localize_script('cts-admin', 'ctsData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'postId' => $current_post ? $current_post->ID : 0,
            'strings' => array(
                'syncing' => __('Syncing…', 'content-tracker-sync'),
                'synced' => __('Synced to tracker ✓', 'content-tracker-sync'),
                'error' => __('Sync failed. Please try again.', 'content-tracker-sync'),
                'btnLabel' => __('Sync to Tracker', 'content-tracker-sync'),
            ),
        ));
    }

    /*--------------------------------------------------------------
     * AJAX Handler
     *------------------------------------------------------------*/

    /**
     * Handle the sync AJAX request.
     *
     * @return void Sends JSON response and dies.
     */
    public function handle_sync()
    {

        // ----- Security checks -----
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        // Validate post ID first, before capability check.
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('Invalid post ID.', 'content-tracker-sync'),
            ));
        }

        // Verify the user can edit this specific post.
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to sync this post.', 'content-tracker-sync'),
            ), 403);
        }

        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error(array(
                'message' => __('Post not found.', 'content-tracker-sync'),
            ));
        }

        // ----- Collect credentials -----
        $credentials_json = get_option('cts_credentials_json', '');
        $spreadsheet_id = get_option('cts_spreadsheet_id', '');
        $sheet_name = get_option('cts_sheet_name', 'Sheet1');

        if (empty($credentials_json) || empty($spreadsheet_id)) {
            wp_send_json_error(array(
                'message' => __('Google Sheets is not configured. Go to Settings → Content Tracker Sync and add your credentials, Spreadsheet ID, and Sheet name.', 'content-tracker-sync'),
            ));
        }

        if (empty($sheet_name)) {
            wp_send_json_error(array(
                'message' => __('Sheet name is empty. Go to Settings → Content Tracker Sync and enter the exact tab name from your Google Sheet (e.g. Sheet1).', 'content-tracker-sync'),
            ));
        }

        $credentials = json_decode($credentials_json, true);

        if (!$credentials) {
            wp_send_json_error(array(
                'message' => __('Credentials JSON is corrupted. Go to Settings → Content Tracker Sync, clear the field, and paste the JSON file contents again.', 'content-tracker-sync'),
            ));
        }

        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            wp_send_json_error(array(
                'message' => __('Credentials are missing client_email or private_key. Download a fresh JSON key from Google Cloud Console and paste it in Settings.', 'content-tracker-sync'),
            ));
        }

        // Optional tag names from the Classic Editor UI.
        $incoming_tags = array();
        if (isset($_POST['tags'])) {
            $raw_tags = wp_unslash($_POST['tags']);
            // Handle both array format (jQuery $.ajax) and comma-separated string.
            if (is_string($raw_tags)) {
                $raw_tags = array_filter(array_map('trim', explode(',', $raw_tags)));
            }
            if (is_array($raw_tags)) {
                foreach ($raw_tags as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ('' !== $tag) {
                        $incoming_tags[] = $tag;
                    }
                }
            }
        }

        // Optional tag IDs from the Gutenberg editor store.
        $incoming_tag_ids = array();
        if (isset($_POST['tag_ids'])) {
            $raw_ids = wp_unslash($_POST['tag_ids']);
            // Handle both array format and comma-separated string.
            if (is_string($raw_ids)) {
                $raw_ids = array_filter(array_map('trim', explode(',', $raw_ids)));
            }
            if (is_array($raw_ids)) {
                foreach ($raw_ids as $id) {
                    $id = absint($id);
                    if ($id > 0) {
                        $incoming_tag_ids[] = $id;
                    }
                }
            }
        }

        // ----- Collect post data (as key-value pairs) -----
        $post_data = $this->collect_post_data($post, $incoming_tags, $incoming_tag_ids);

        // ----- Send to Google Sheets with SMART COLUMN MATCHING -----
        $sheets = new CTS_Google_Sheets_Service($credentials, $spreadsheet_id, $sheet_name);

        // Map data keys to spreadsheet columns by reading Row 1 headers.
        $row_data = $sheets->map_data_to_row($post_data);

        if (is_wp_error($row_data)) {
            wp_send_json_error(array(
                'message' => $row_data->get_error_message(),
            ));
        }

        // Check for existing row (duplicate prevention).
        $existing_row = $sheets->find_row_by_post_id($post_id);

        // Handle API errors from the lookup.
        if (is_wp_error($existing_row)) {
            wp_send_json_error(array(
                'message' => $existing_row->get_error_message(),
            ));
        }

        if ($existing_row) {
            $result = $sheets->update_row($existing_row, $row_data);
        } else {
            $result = $sheets->append_row($row_data);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
            ));
        }

        // ----- Google Docs: Create/Update article content doc -----
        $drive_folder_id = get_option('cts_drive_folder_id', '');
        $doc_url = '';

        if (!empty($drive_folder_id) && !empty($credentials)) {

            $docs_service = new CTS_Google_Docs_Service($credentials, $drive_folder_id);

            // Check if we already have a doc for this post.
            $existing_doc_id = get_post_meta($post_id, '_cts_google_doc_id', true);

            $doc_result = $docs_service->sync_post_to_doc(
                $post_id,
                $post->post_title,
                $post->post_content,
                $existing_doc_id ? $existing_doc_id : ''
            );

            if (!is_wp_error($doc_result)) {
                // Save the doc ID for future updates.
                update_post_meta($post_id, '_cts_google_doc_id', $doc_result['doc_id']);
                $doc_url = $doc_result['doc_url'];

                // Write the doc link to the spreadsheet if there's a matching column.
                $link_data = array('content_link' => $doc_url);
                $link_row = $sheets->map_data_to_row($link_data);

                if (!is_wp_error($link_row)) {
                    // Check if any cell actually has data (means a content_link column exists).
                    $has_link_column = false;
                    foreach ($link_row as $cell) {
                        if (null !== $cell) {
                            $has_link_column = true;
                            break;
                        }
                    }

                    if ($has_link_column) {
                        $target_row = $existing_row ? $existing_row : $sheets->find_last_data_row();
                        if ($target_row > 0) {
                            $sheets->update_row($target_row, $link_row);
                        }
                    }
                }
            } else {
                // Log doc creation errors but don't fail the whole sync.
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[CTS] Google Doc error for post #' . $post_id . ': ' . $doc_result->get_error_message());
                }
            }
        }

        // Save sync timestamp.
        update_post_meta($post_id, '_cts_last_synced', current_time('mysql'));

        // Build success message.
        $message = $existing_row
            ? __('Row updated in tracker ✓', 'content-tracker-sync')
            : __('Synced to tracker ✓', 'content-tracker-sync');

        if ($doc_url) {
            $message .= ' ' . __('+ Doc created ✓', 'content-tracker-sync');
        }

        wp_send_json_success(array(
            'message' => $message,
        ));
    }

    /*--------------------------------------------------------------
     * Data Collection
     *------------------------------------------------------------*/

    /**
     * Collect all required post data as an associative array.
     *
     * Keys match the data keys used in CTS_Google_Sheets_Service::$header_aliases
     * so the smart column mapper can place them in the right columns.
     *
     * @param  WP_Post $post The post object.
     * @param  array   $incoming_tags     Tag names from client (Classic Editor).
     * @param  array   $incoming_tag_ids  Tag term IDs from client (Gutenberg).
     * @return array   Associative array: data_key => value.
     */
    private function collect_post_data($post, $incoming_tags = array(), $incoming_tag_ids = array())
    {

        $post_id = $post->ID;
        $title = $post->post_title;
        $slug = $post->post_name;

        // SEO Title — try Yoast, Rank Math, AIOSEO, then fall back to post title.
        $seo_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if (empty($seo_title)) {
            $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        }
        if (empty($seo_title)) {
            $seo_title = get_post_meta($post_id, '_aioseo_title', true);
        }
        if (empty($seo_title)) {
            $seo_title = $title; // fallback to post title
        }

        // Focus Keyphrase — try Yoast, Rank Math, AIOSEO.
        $focus_keyphrase = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if (empty($focus_keyphrase)) {
            $focus_keyphrase = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        }
        if (empty($focus_keyphrase)) {
            $focus_keyphrase = get_post_meta($post_id, '_aioseo_keyphrases', true);
        }

        // Meta Description — try Yoast, Rank Math, AIOSEO.
        $meta_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($post_id, 'rank_math_description', true);
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($post_id, '_aioseo_description', true);
        }

        // ---- Tags — MERGE-ALL approach: gather from every source, then deduplicate ----
        $all_tags = array();

        // Source 1: WordPress API (reliable for already-saved posts).
        $wp_tags = wp_get_post_tags($post_id, array('fields' => 'names'));
        if (is_array($wp_tags) && !empty($wp_tags)) {
            $all_tags = array_merge($all_tags, $wp_tags);
        }

        // Source 2: get_the_terms() — alternative WP function that might bypass caching issues.
        $term_objects = get_the_terms($post_id, 'post_tag');
        if (is_array($term_objects) && !empty($term_objects)) {
            foreach ($term_objects as $term_obj) {
                $all_tags[] = $term_obj->name;
            }
        }

        // Source 3: Client-sent tag names (Classic Editor or Gutenberg-resolved names).
        if (is_array($incoming_tags) && !empty($incoming_tags)) {
            foreach ($incoming_tags as $tag) {
                $tag = sanitize_text_field(trim($tag));
                if ('' !== $tag) {
                    $all_tags[] = $tag;
                }
            }
        }

        // Source 4: Resolve client-sent tag IDs (Gutenberg editor store).
        if (is_array($incoming_tag_ids) && !empty($incoming_tag_ids)) {
            foreach ($incoming_tag_ids as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $term = get_term($tid, 'post_tag');
                    if ($term && !is_wp_error($term)) {
                        $all_tags[] = $term->name;
                    }
                }
            }
        }

        // Source 5: Direct DB query (catches anything WordPress API might miss).
        global $wpdb;
        $db_tags = $wpdb->get_col($wpdb->prepare(
            "SELECT t.name
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy = 'post_tag'
             ORDER BY t.name ASC",
            $post_id
        ));
        if (is_array($db_tags) && !empty($db_tags)) {
            $all_tags = array_merge($all_tags, $db_tags);
        }

        // Deduplicate (case-insensitive) while preserving original casing.
        $tags = array();
        $seen = array();
        foreach ($all_tags as $tag) {
            $tag = trim($tag);
            if ('' === $tag) {
                continue;
            }
            $lower = strtolower($tag);
            if (!isset($seen[$lower])) {
                $seen[$lower] = true;
                $tags[] = $tag;
            }
        }

        // Debug logging (only when WP_DEBUG is on).
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CTS] Post #' . $post_id . ' — wp_get_post_tags: ' . wp_json_encode($wp_tags));
            error_log('[CTS] Post #' . $post_id . ' — incoming_tags: ' . wp_json_encode($incoming_tags));
            error_log('[CTS] Post #' . $post_id . ' — incoming_tag_ids: ' . wp_json_encode($incoming_tag_ids));
            error_log('[CTS] Post #' . $post_id . ' — db_tags: ' . wp_json_encode($db_tags));
            error_log('[CTS] Post #' . $post_id . ' — final merged tags: ' . wp_json_encode($tags));
        }

        $tags_csv = implode(', ', $tags);
        $tags_hashed = $this->tags_to_hashtags($tags);

        // Return as associative array — keys match CTS_Google_Sheets_Service::$header_aliases.
        return array(
            'post_id'            => (string)$post_id,
            'title'              => $title,
            'slug'               => $slug,
            'seo_title'          => $seo_title,
            'keywords'           => $tags_csv,
            'keywords_with_tags' => $tags_hashed,
            'meta_description'   => $meta_desc ? $meta_desc : '',
            'focus_keyphrase'    => $focus_keyphrase ? $focus_keyphrase : '',
        );
    }

    /**
     * Convert an array of tag names into hashtag format.
     *
     * Example: [ 'AI', 'HR', 'automation' ] → '#AI #HR #Automation'
     *
     * @param  array $tags Tag name strings.
     * @return string Space-separated hashtag string.
     */
    private function tags_to_hashtags($tags)
    {

        if (!is_array($tags) || empty($tags)) {
            return '';
        }

        $hashtags = array_map(function ($tag) {
            // Capitalise first letter of each word, prefix with #.
            return '#' . str_replace(' ', '', ucwords(trim($tag)));
        }, $tags);

        return implode(' ', $hashtags);
    }
}