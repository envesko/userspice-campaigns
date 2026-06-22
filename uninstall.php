<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    // Tables (campaigns, campaign_sends, campaign_suppression, campaigns_config) are retained
    // so send history and the suppression list survive a reinstall. Drop manually for a clean wipe.

    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/campaigns/";
    if (file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetDest), ['.', '..']) as $file) {
            @unlink($widgetDest . $file);
        }
        @rmdir($widgetDest);
    }

    $db->query('DELETE FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    $db->query('DELETE FROM us_plugin_hooks WHERE folder = ?', [$plugin_name]);
    logger($user->data()->id, 'USPlugins', $plugin_name . ' uninstalled (data tables retained)');
    err($plugin_name . ' uninstalled. Campaign tables were retained - drop them manually to wipe history + suppression list.');
}
