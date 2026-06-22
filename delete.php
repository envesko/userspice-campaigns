<?php

require_once 'init.php';
// Called only when a plugin is actively DELETED (not on deactivate). Drops all campaign data.
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    foreach (['campaign_sends', 'campaign_suppression', 'campaigns_config', 'campaigns'] as $table) {
        $db->query('DROP TABLE IF EXISTS ' . $table);
    }
    logger($user->data()->id, 'USPlugins', $plugin_name . ' tables dropped on delete');
}
