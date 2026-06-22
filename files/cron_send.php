<?php
/**
 * Background batch sender. Point your scheduler at:
 *   https://yoursite.com/usersc/plugins/campaigns/files/cron_send.php
 *
 * Drains the queue for every campaign that still has queued recipients, one batch per run.
 * If the Cron Heartbeat plugin is installed, the run is gated by its IP allowlist and recorded.
 */
require_once dirname(__DIR__, 4) . '/users/init.php';

if (function_exists('cronGuard')) {
    cronGuard(['slug' => 'campaigns-send', 'admin_ok' => true]);
} else {
    // Fallback gate when Cron Heartbeat is not installed: master account in browser only.
    if (!isset($user) || !$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
        http_response_code(403);
        exit('Forbidden - install the Cron Heartbeat plugin to allow scheduler access by IP.');
    }
}

global $db;
$pending = $db->query('SELECT DISTINCT fkCampaignID FROM campaign_sends WHERE Status = ?', ['queued'])->results();
$totalSent = 0;
foreach ($pending as $p) {
    $res = campaignSendBatch((int)$p->fkCampaignID);
    $totalSent += $res['sent'];
}
echo "campaigns-send ran: {$totalSent} message(s) sent this batch\n";
