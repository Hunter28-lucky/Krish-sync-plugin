<?php
/**
 * Admin Settings Page Template for Content Tracker Sync.
 *
 * @package ContentTrackerSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap cts-settings-wrap">
    <h1><?php esc_html_e( 'Content Tracker Sync — Settings', 'content-tracker-sync' ); ?></h1>

    <?php settings_errors(); ?>

    <div class="cts-settings-card">
        <form method="post" action="options.php">
            <?php
                settings_fields( CTS_Admin_Settings::OPTION_GROUP );
                do_settings_sections( 'content-tracker-sync' );
                submit_button( __( 'Save Settings', 'content-tracker-sync' ) );
            ?>
        </form>

        <hr style="margin: 20px 0;">
        <h3><?php esc_html_e( 'Test Connection', 'content-tracker-sync' ); ?></h3>
        <p class="description"><?php esc_html_e( 'After saving, click the button below to verify your settings work.', 'content-tracker-sync' ); ?></p>
        <button type="button" id="cts-test-connection" class="button button-secondary" style="margin-top:8px;">
            <?php esc_html_e( 'Test Connection', 'content-tracker-sync' ); ?>
        </button>
        <span id="cts-test-result" style="margin-left:12px; font-weight:600;"></span>

        <script>
        (function($){
            $('#cts-test-connection').on('click', function(){
                var $btn = $(this);
                var $result = $('#cts-test-result');
                $btn.prop('disabled', true).text('Testing…');
                $result.text('').css('color', '');
                $.post(ctsSettings.ajaxUrl, {
                    action: 'cts_test_connection',
                    nonce: ctsSettings.nonce
                }, function(resp){
                    if(resp.success){
                        $result.text('✓ ' + resp.data.message).css('color', '#00a32a');
                    } else {
                        $result.text('✗ ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error')).css('color', '#d63638');
                    }
                }).fail(function(){
                    $result.text('✗ Request failed. Check your internet connection.').css('color', '#d63638');
                }).always(function(){
                    $btn.prop('disabled', false).text('Test Connection');
                });
            });
        })(jQuery);
        </script>
    </div>

    <div class="cts-settings-card cts-info-card">
        <h2><?php esc_html_e( 'Quick Setup Guide', 'content-tracker-sync' ); ?></h2>
        <ol>
            <li><?php esc_html_e( 'Go to the Google Cloud Console and create a project (or use an existing one).', 'content-tracker-sync' ); ?></li>
            <li><?php esc_html_e( 'Enable the Google Sheets API for your project.', 'content-tracker-sync' ); ?></li>
            <li><?php esc_html_e( 'Create a Service Account and download the JSON key file.', 'content-tracker-sync' ); ?></li>
            <li><?php esc_html_e( 'Share your Google Sheet with the Service Account email (client_email in the JSON).', 'content-tracker-sync' ); ?></li>
            <li><?php esc_html_e( 'Paste the JSON file contents above, enter your Spreadsheet ID and Sheet name, then save.', 'content-tracker-sync' ); ?></li>
        </ol>

        <h3><?php esc_html_e( 'Expected Sheet Columns (in order)', 'content-tracker-sync' ); ?></h3>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Column', 'content-tracker-sync' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'content-tracker-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td><?php echo esc_html( 'A — Post ID' ); ?></td><td><?php esc_html_e( 'WordPress Post ID (used for duplicate detection)', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'B — Topic' ); ?></td><td><?php esc_html_e( 'Post Title', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'C — Post Slug' ); ?></td><td><?php esc_html_e( 'URL slug of the post', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'D — SEO Title' ); ?></td><td><?php esc_html_e( 'Yoast SEO Title', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'E — Keywords' ); ?></td><td><?php esc_html_e( 'WordPress Tags (comma-separated)', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'F — Keywords with tags' ); ?></td><td><?php esc_html_e( 'Tags in hashtag format (#AI #HR)', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'G — Meta Description' ); ?></td><td><?php esc_html_e( 'Yoast Meta Description', 'content-tracker-sync' ); ?></td></tr>
                <tr><td><?php echo esc_html( 'H — Focus Keyphrase' ); ?></td><td><?php esc_html_e( 'Yoast Focus Keyphrase', 'content-tracker-sync' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="cts-settings-card cts-brand-card">
        <p>
            <?php esc_html_e( 'Crafted by Krish Goswami.', 'content-tracker-sync' ); ?>
            <a href="mailto:krrishyogi18@gmail.com">krrishyogi18@gmail.com</a>
        </p>
    </div>
</div>
