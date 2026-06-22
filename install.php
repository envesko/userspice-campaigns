<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $queries = [
        [
            'Description' => 'Create campaigns table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS campaigns (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                Name VARCHAR(190) NOT NULL,
                Subject VARCHAR(255) NOT NULL,
                Body LONGTEXT NULL,
                SegmentJson TEXT NULL,
                Status VARCHAR(20) NOT NULL DEFAULT "draft",
                Created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                SentAt DATETIME NULL,
                CreatedBy INT(11) UNSIGNED NULL
            )',
        ],
        [
            'Description' => 'Create campaign_sends table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS campaign_sends (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                fkCampaignID INT(11) UNSIGNED NOT NULL,
                fkUserID INT(11) UNSIGNED NULL,
                Email VARCHAR(190) NOT NULL,
                Status VARCHAR(20) NOT NULL DEFAULT "queued",
                Error VARCHAR(255) NULL,
                SentAt DATETIME NULL,
                UNIQUE KEY uniq_campaign_email (fkCampaignID, Email),
                KEY idx_campaign_status (fkCampaignID, Status)
            )',
        ],
        [
            'Description' => 'Create campaign_suppression table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS campaign_suppression (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                Email VARCHAR(190) NOT NULL UNIQUE,
                Reason VARCHAR(100) NULL,
                Created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        ],
        [
            'Description' => 'Create campaigns_config table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS campaigns_config (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                FromEmail VARCHAR(190) NULL,
                FromName VARCHAR(190) NULL,
                BatchSize INT(11) UNSIGNED NOT NULL DEFAULT 25,
                Secret VARCHAR(80) NULL
            )',
        ],
    ];

    foreach ($queries as $query) {
        $db->query($query['SQL']);
        if (!$db->error()) {
            logger($user->data()->id, 'Campaigns', "[INSTALL] [SQL] [SUCCESS] {$query['Description']}");
        } else {
            logger($user->data()->id, 'Campaigns', "[INSTALL] [SQL] [WARNING] {$query['Description']} failed", json_encode(['ERROR' => $db->errorString()]));
        }
    }

    // Seed config with a random unsubscribe-signing secret
    if ($db->query('SELECT id FROM campaigns_config WHERE id = 1')->count() == 0) {
        $db->insert('campaigns_config', [
            'id' => 1,
            'FromEmail' => '',
            'FromName' => '',
            'BatchSize' => 25,
            'Secret' => bin2hex(random_bytes(20)),
        ]);
    }

    // Copy dashboard widget
    $widgetSrc = "{$abs_us_root}{$us_url_root}usersc/plugins/{$plugin_name}/assets/widget/";
    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/campaigns/";
    if (!file_exists($widgetDest)) {
        @mkdir($widgetDest, 0777, true);
    }
    if (file_exists($widgetSrc) && file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetSrc), ['.', '..']) as $file) {
            copy($widgetSrc . $file, $widgetDest . $file);
        }
    }

    $hooks = [];
    registerHooks($hooks, $plugin_name);

    $check = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name])->count();
    if ($check > 0) {
        err($plugin_name . ' has already been installed!');
    } else {
        $db->insert('us_plugins', ['plugin' => $plugin_name, 'status' => 'installed']);
        if (!$db->error()) {
            err($plugin_name . ' installed');
            logger($user->data()->id, 'USPlugins', $plugin_name . ' installed');
        } else {
            err($plugin_name . ' was not installed');
            logger($user->data()->id, 'USPlugins', 'Failed to install plugin, Error: ' . $db->errorString());
        }
    }
}
