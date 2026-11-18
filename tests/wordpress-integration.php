<?php
/**
 * Run ONLY on a disposable WordPress installation, with this plugin inactive:
 * wp eval-file /absolute/path/tests/wordpress-integration.php
 * Real WordPress hooks/options/cron/SQL; simulated native operations, no cache deletion.
 */
namespace ClearNginxForWebuzo {
    function time() { return $GLOBALS['wcn_fixture_time'] ?? \time(); }
    final class Native_Panel {
        public function check_connection() { return $this->request('status'); }
        public function purge() { return $this->request('purge'); }
        private function request($operation) {
            $GLOBALS['wcn_requests'][] = $operation;
            if (isset($GLOBALS['wcn_on_request'])) { ($GLOBALS['wcn_on_request'])(); }
            return $GLOBALS['wcn_response'] ?? ['ok' => true, 'account' => 'fixture', 'scope' => 'account', 'backend' => 'webuzo-native'];
        }
    }
}
namespace {
    global $wcn_requests, $wcn_response, $wcn_on_request, $checks;
    if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Requires disposable WordPress under WP-CLI'); }
    $base = dirname(__DIR__) . '/clear-nginx-for-webuzo/';
    foreach (['settings', 'state', 'cache-service', 'auto-purge', 'site-health', 'plugin', 'cli'] as $part) {
        require_once $base . 'includes/class-' . $part . '.php';
    }
    use ClearNginxForWebuzo\Auto_Purge;
    use ClearNginxForWebuzo\Settings;
    use ClearNginxForWebuzo\State;
    use ClearNginxForWebuzo\Plugin;
    $checks = [];
    function check($condition, $label) { if (!$condition) { throw new RuntimeException($label); } $GLOBALS['checks'][] = $label; }
    function prefs($changes = []) { update_option(Settings::OPTION, array_merge(Settings::defaults(), ['automatic_purge' => true, 'purge_comments' => true], $changes), false); }
    function reset_work() {
        Auto_Purge::cancel(); delete_option('clear_nginx_for_webuzo_worker');
        $GLOBALS['wcn_requests'] = []; $GLOBALS['wcn_response'] = null; $GLOBALS['wcn_on_request'] = null;
    }
    function pending() { return is_array(get_option(Auto_Purge::PENDING)); }
    function run_work() { Auto_Purge::run(); }
    function tick($seconds = 70) { $GLOBALS['wcn_fixture_time'] += $seconds; }
    $GLOBALS['wcn_fixture_time'] = time();
    Plugin::boot($base . 'clear-nginx-for-webuzo.php'); Auto_Purge::boot(); Settings::register();
    try {
        reset_work(); delete_option(Settings::OPTION);
        check(is_wp_error(ClearNginxForWebuzo\Cache_Service::run('unsupported')) && !$wcn_requests, 'Unknown service operation cannot fall through to a purge');
        check(Settings::get() === Settings::defaults(), 'Existing installations retain toolbar and manual-only defaults');
        Auto_Purge::queue(); check(!pending(), 'Disabled automatic purge creates no work');
        $sanitized = Settings::sanitize(['automatic_purge' => ['1'], 'purge_comments' => 'yes', 'show_toolbar' => '1', 'toolbar_label' => '<b>Καθαρισμός</b>', 'key' => 'forbidden']);
        check(!$sanitized['automatic_purge'] && !$sanitized['purge_comments'] && $sanitized['show_toolbar'], 'Strict boolean sanitization rejects arrays and arbitrary strings');
        check($sanitized['toolbar_label'] === 'Καθαρισμός' && !isset($sanitized['key']), 'Labels sanitized; transport fields not accepted');
        check(mb_strlen(Settings::sanitize(['toolbar_label' => str_repeat('α', 100)])['toolbar_label']) === 80, 'Multibyte toolbar label truncates safely');
        prefs();

        wp_set_current_user(0);
        $post = wp_insert_post(['post_title' => 'Parity fixture', 'post_content' => 'Initial', 'post_status' => 'draft']);
        check(!pending(), 'Draft creation by a system process does not purge');
        wp_update_post(['ID' => $post, 'post_content' => 'Draft edit']);
        check(!pending(), 'Draft edits do not purge');
        wp_update_post(['ID' => $post, 'post_status' => 'publish']);
        check(pending(), 'Real publication queues without an authenticated user');
        wp_update_post(['ID' => $post, 'post_content' => 'Public edit one']);
        wp_update_post(['ID' => $post, 'post_content' => 'Public edit two']);
        run_work(); check($wcn_requests === ['purge'] && !pending(), 'Multiple real public updates consolidate into one purge');
        reset_work();
        $revision = _wp_put_post_revision($post);
        check(!pending(), 'Core revision insertion ignored');
        wp_update_post(['ID' => $post, 'post_status' => 'draft']); check(pending(), 'Withdrawal invalidates formerly public content');
        reset_work(); wp_update_post(['ID' => $post, 'post_status' => 'publish']); reset_work();
        wp_trash_post($post); check(pending(), 'Trash invalidates public content');
        reset_work(); wp_untrash_post($post);
        // WordPress restores to draft by default; explicitly publish to cover public restoration.
        wp_update_post(['ID' => $post, 'post_status' => 'publish']); check(pending(), 'Public restoration queues a purge');
        reset_work(); wp_delete_post($post, true); check(pending(), 'Permanent deletion of published content queues a purge');

        reset_work();
        $scheduled = wp_insert_post(['post_title' => 'Scheduled fixture', 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + 3600), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        check(!pending(), 'Scheduling future content does not purge early');
        wp_publish_post($scheduled); check(pending(), 'Core scheduled-publication path queues purge');
        reset_work();
        _wp_put_post_revision($scheduled, true); check(!pending(), 'Core autosave insertion ignored');

        $draft = wp_insert_post(['post_title' => 'Private parent', 'post_status' => 'draft']);
        $attachment = wp_insert_attachment(['post_title' => 'Image', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_parent' => $draft]);
        check(!pending(), 'Attachment on draft parent ignored');
        wp_update_post(['ID' => $attachment, 'post_parent' => $scheduled]); check(pending(), 'Attachment reparented to public content queues purge');
        reset_work(); wp_update_post(['ID' => $attachment, 'post_title' => 'New image caption']); check(pending(), 'Public attachment edit queues purge');
        reset_work(); update_post_meta($attachment, '_wp_attachment_image_alt', 'Public alternative text'); check(pending(), 'Attachment alternative-text metadata queues purge');
        reset_work(); update_post_meta($attachment, '_wp_attachment_metadata', ['width' => 120, 'height' => 120]); check(pending(), 'Attachment image metadata queues purge');
        reset_work(); wp_delete_attachment($attachment, true); check(pending(), 'Public attachment deletion queues purge');
        reset_work();

        $unattached = wp_insert_attachment(['post_title' => 'Unattached public media', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg']);
        check(pending(), 'Unattached public attachment changes are covered');
        wp_delete_attachment($unattached, true); reset_work();
        $comment = wp_insert_comment(['comment_post_ID' => $scheduled, 'comment_content' => 'Held', 'comment_approved' => '0']);
        check(!pending(), 'Unapproved comment insertion ignored');
        wp_update_comment(['comment_ID' => $comment, 'comment_content' => 'Still held']); check(!pending(), 'Unapproved comment edit ignored');
        wp_set_comment_status($comment, 'spam'); check(!pending(), 'Unapproved-to-spam transition ignored');
        wp_set_comment_status($comment, 'approve'); check(pending(), 'Approval queues public comment invalidation');
        reset_work(); wp_update_comment(['comment_ID' => $comment, 'comment_content' => 'Edited publicly']); check(pending(), 'Approved comment content edit queues purge');
        reset_work(); wp_set_comment_status($comment, 'hold'); check(pending(), 'Unapproval invalidates previously public comment');
        reset_work(); wp_set_comment_status($comment, 'approve'); reset_work();
        wp_delete_comment($comment, true); check(pending(), 'Permanent approved-comment deletion queues purge');
        reset_work(); prefs(['purge_comments' => false]);
        $muted = wp_insert_comment(['comment_post_ID' => $scheduled, 'comment_content' => 'Muted', 'comment_approved' => '1']);
        check(!pending(), 'Comment automation can be disabled independently');
        prefs();
        wp_delete_comment($muted, true); reset_work();
        $moved = wp_insert_comment(['comment_post_ID' => $scheduled, 'comment_content' => 'Public moved comment', 'comment_approved' => '1']);
        reset_work(); wp_update_comment(['comment_ID' => $moved, 'comment_post_ID' => $draft]);
        check(pending(), 'Moving approved comment off a public post invalidates its old location');
        reset_work(); wp_update_comment(['comment_ID' => $moved, 'comment_content' => 'Private comment edit']);
        check(!pending(), 'Approved comment on draft post remains non-public');
        wp_delete_comment($moved, true);
        $menu = wp_create_nav_menu('CNW integration menu');
        reset_work(); wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Fixture link', 'menu-item-url' => home_url('/'), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish']);
        check(pending(), 'Real classic navigation item changes queue a purge');
        reset_work(); wp_delete_nav_menu($menu);
        check(pending(), 'Real classic menu deletion queues a purge');
        foreach (['wp_update_nav_menu', 'switch_theme', 'permalink_structure_changed', 'customize_save_after'] as $hook) {
            reset_work(); do_action($hook, 0); check(pending(), 'Rendering change queues: ' . $hook);
        }
        reset_work();
        $template = wp_insert_post(['post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Fixture block template']);
        check(pending(), 'Block template changes invalidate public rendering');

        reset_work();
        Auto_Purge::queue(); $first = get_option(Auto_Purge::PENDING);
        $wcn_on_request = function() { Auto_Purge::queue(); };
        run_work(); $wcn_on_request = null;
        check(pending() && get_option(Auto_Purge::PENDING)['token'] !== $first['token'], 'Change arriving during native execution survives old completion');
        tick(); run_work(); check(!pending() && count($wcn_requests) === 2, 'Surviving change receives a subsequent purge');
        reset_work();
        $lock = State::lock(); check(is_array($lock) && State::lock() === false, 'Database worker lock excludes concurrent execution');
        wp_cache_delete('clear_nginx_for_webuzo_worker', 'options');
        wp_cache_set('notoptions', ['clear_nginx_for_webuzo_worker' => true], 'options');
        check(State::lock() === false && get_option('clear_nginx_for_webuzo_worker') === $lock, 'Stale cached option miss cannot overwrite an active worker lock');
        Auto_Purge::queue(); run_work(); check(!$wcn_requests && pending(), 'Busy local worker retains pending work without native request');
        tick(); $replacement = State::lock(); check(is_array($replacement), 'Expired worker lock can be recovered');
        check(!State::compare('clear_nginx_for_webuzo_worker', $lock), 'Old worker cannot release replacement lock');
        State::compare('clear_nginx_for_webuzo_worker', $replacement); run_work(); check(!pending(), 'Recovered worker completes retained work');

        foreach (['rate_limited', 'busy'] as $temporary) {
            reset_work(); Auto_Purge::queue();
            $wcn_response = new WP_Error('clear_nginx_for_webuzo_native_rejected', 'Temporary fixture rejection', ['native_code' => $temporary, 'retry_after' => 10]);
            run_work(); check(pending() && get_option(Auto_Purge::PENDING)['attempts'] === 1, 'Explicit temporary failure remains pending: ' . $temporary);
            run_work(); check(count($wcn_requests) === 1, 'Cooldown prevents repeated native call: ' . $temporary);
            $wcn_response = null; tick(); do_action(Auto_Purge::CRON);
            check(!pending() && count($wcn_requests) === 2, 'WP-Cron retry completes after cooldown: ' . $temporary);
        }
        reset_work(); Auto_Purge::queue();
        $wcn_response = new WP_Error('clear_nginx_for_webuzo_native_rejected', 'Persistent busy', ['native_code' => 'busy']);
        for ($i = 0; $i < 5; ++$i) { run_work(); tick(); }
        check(!pending() && count($wcn_requests) === 5 && !get_option('clear_nginx_for_webuzo_auto_result')['ok'], 'Temporary failures stop after four retries and persist visible failure');
        foreach (['native_failed', 'identity_changed', 'cache_unavailable', 'transport_unconfirmed'] as $permanent) {
            reset_work(); Auto_Purge::queue();
            $wcn_response = new WP_Error('fixture_' . $permanent, 'Do not retry', ['native_code' => $permanent]);
            run_work(); check(!pending() && count($wcn_requests) === 1, 'Permanent or ambiguous failure is not automatically retried: ' . $permanent);
        }
        reset_work(); Auto_Purge::queue(); prefs(['automatic_purge' => false]);
        check(!pending() && !wp_next_scheduled(Auto_Purge::CRON), 'Disabling automation removes queued work and wakeups');
        prefs(); reset_work();

        require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
        $admin = get_user_by('login', 'fixtureadmin'); wp_set_current_user($admin->ID);
        $bar = new WP_Admin_Bar(); $bar->initialize(); Plugin::bar($bar);
        check(strpos($bar->get_node(Plugin::SLUG)->title, '>Clear Cache</button>') !== false, 'Real toolbar retains default POST control');
        prefs(['toolbar_label' => 'Custom <unsafe> & label']);
        $bar = new WP_Admin_Bar(); $bar->initialize(); Plugin::bar($bar);
        check(strpos($bar->get_node(Plugin::SLUG)->title, 'Custom &amp; label') !== false, 'Toolbar label sanitized and escaped');
        prefs(['show_toolbar' => false]);
        $bar = new WP_Admin_Bar(); $bar->initialize(); Plugin::bar($bar);
        check(!$bar->get_node(Plugin::SLUG), 'Toolbar visibility preference respected');
        set_transient('clear_nginx_for_webuzo_notice_' . $admin->ID, ['type' => 'success', 'text' => '<script>fixture</script>'], 120);
        ob_start(); Plugin::frontend_notice(); $html = ob_get_clean();
        check(strpos($html, 'role="status"') !== false && strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false, 'Frontend result is visible and escaped');
        ob_start(); Plugin::frontend_notice(); check(ob_get_clean() === '', 'Frontend result consumed once');
        check(count(Plugin::settings_link([])) === 1, 'Administrator gets plugin-list Settings link');
        wp_set_current_user(0);
        set_transient('clear_nginx_for_webuzo_notice_0', ['text' => 'private'], 120);
        ob_start(); Plugin::frontend_notice(); check(ob_get_clean() === '' && Plugin::settings_link([]) === [], 'Visitor receives no notice or Settings action');
        $before = count($wcn_requests);
        $info = ClearNginxForWebuzo\Site_Health::information([]);
        check(count($wcn_requests) === $before && isset($info[Plugin::SLUG]['fields']['checked']), 'Site Health reads timestamped saved information without native requests');
        check(strpos(wp_json_encode($info), 'key') === false && strpos(wp_json_encode($info), 'nonce') === false, 'Site Health exports no credential fields');
        echo wp_json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    } finally {
        reset_work(); delete_option(Settings::OPTION);
        if (isset($scheduled)) { wp_delete_post($scheduled, true); }
        if (isset($draft)) { wp_delete_post($draft, true); }
        if (isset($template)) { wp_delete_post($template, true); }
        Auto_Purge::cancel();
    }
}
