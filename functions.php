<?php
/**
 * Email Campaigns - engine. Auto-loaded globally for the active plugin.
 *
 * Segments are described by a small assoc array and translated to SQL through a strict
 * whitelist (campaignSegmentSql). User input never reaches SQL as a string.
 */

if (!function_exists('campaignsConfig')) {
    function campaignsConfig()
    {
        global $db;
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg ?: null;
        }
        try {
            $q = $db->query('SELECT * FROM campaigns_config WHERE id = 1');
            $cfg = $q->count() ? $q->first() : false;
        } catch (Exception $e) {
            $cfg = false;
        }
        return $cfg ?: null;
    }
}

if (!function_exists('campaignSegmentSql')) {
    /**
     * Translate a segment array into a parameterised WHERE clause against `users`.
     * Allowed keys ONLY: permission (int), joined_before (date), joined_after (date),
     * active_only (bool), logged_in_only (bool). Anything else is ignored.
     *
     * Returns ['joins' => '', 'where' => '...', 'params' => [...]].
     */
    function campaignSegmentSql($segment)
    {
        $joins = '';
        $where = ['1=1'];
        $params = [];

        if (!is_array($segment)) {
            $segment = [];
        }

        if (!empty($segment['permission']) && (int)$segment['permission'] > 0) {
            // user must hold this permission via the matches table
            $joins .= ' INNER JOIN user_permission_matches upm ON upm.user_id = u.id ';
            $where[] = 'upm.permission_id = ?';
            $params[] = (int)$segment['permission'];
        }

        if (!empty($segment['joined_after']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $segment['joined_after'])) {
            $where[] = 'u.join_date >= ?';
            $params[] = $segment['joined_after'] . ' 00:00:00';
        }

        if (!empty($segment['joined_before']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $segment['joined_before'])) {
            $where[] = 'u.join_date <= ?';
            $params[] = $segment['joined_before'] . ' 23:59:59';
        }

        if (!empty($segment['active_only'])) {
            $where[] = 'u.active = 1';
        }

        if (!empty($segment['logged_in_only'])) {
            $where[] = 'u.logins > 0';
        }

        // Always require a non-empty email
        $where[] = "u.email IS NOT NULL AND u.email != ''";

        return ['joins' => $joins, 'where' => implode(' AND ', $where), 'params' => $params];
    }
}

if (!function_exists('campaignRecipients')) {
    /** Return distinct recipient rows (id, email, fname) for a segment, excluding suppressed emails. */
    function campaignRecipients($segment)
    {
        global $db;
        $s = campaignSegmentSql($segment);
        $sql = "SELECT DISTINCT u.id, u.email, u.fname
                FROM users u {$s['joins']}
                WHERE {$s['where']}
                AND u.email NOT IN (SELECT Email FROM campaign_suppression)
                ORDER BY u.id ASC";
        try {
            return $db->query($sql, $s['params'])->results();
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('campaignRecipientCount')) {
    function campaignRecipientCount($segment)
    {
        return count(campaignRecipients($segment));
    }
}

if (!function_exists('campaignQueue')) {
    /** Populate campaign_sends with queued rows for a campaign. Skips dupes + suppressed. Returns count queued. */
    function campaignQueue($campaignId)
    {
        global $db;
        $campaignId = (int)$campaignId;
        $c = $db->query('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$c->count()) {
            return 0;
        }
        $segment = json_decode($c->first()->SegmentJson, true) ?: [];
        $recipients = campaignRecipients($segment);
        $queued = 0;
        foreach ($recipients as $r) {
            $exists = $db->query('SELECT id FROM campaign_sends WHERE fkCampaignID = ? AND Email = ?', [$campaignId, $r->email]);
            if ($exists->count()) {
                continue;
            }
            $db->insert('campaign_sends', [
                'fkCampaignID' => $campaignId,
                'fkUserID'     => (int)$r->id,
                'Email'        => $r->email,
                'Status'       => 'queued',
                'Error'        => null,
                'SentAt'       => null,
            ]);
            if (!$db->error()) {
                $queued++;
            }
        }
        $db->update('campaigns', $campaignId, ['Status' => 'queued']);
        return $queued;
    }
}

if (!function_exists('campaignUnsubUrl')) {
    function campaignUnsubUrl($email)
    {
        global $us_url_root;
        $cfg = campaignsConfig();
        $secret = $cfg ? $cfg->Secret : 'changeme';
        $token = hash_hmac('sha256', strtolower(trim($email)), $secret);
        $base = (class_exists('Server') ? (Server::get('HTTPS') ? 'https' : 'http') . '://' . Server::get('HTTP_HOST') : '');
        return $base . $us_url_root . 'usersc/plugins/campaigns/files/unsubscribe.php?e=' . urlencode($email) . '&t=' . $token;
    }
}

if (!function_exists('campaignVerifyUnsub')) {
    function campaignVerifyUnsub($email, $token)
    {
        $cfg = campaignsConfig();
        $secret = $cfg ? $cfg->Secret : 'changeme';
        $expected = hash_hmac('sha256', strtolower(trim($email)), $secret);
        return hash_equals($expected, (string)$token);
    }
}

if (!function_exists('campaignSuppress')) {
    function campaignSuppress($email, $reason = 'unsubscribe')
    {
        global $db;
        $email = trim((string)$email);
        if ($email === '') {
            return false;
        }
        if ($db->query('SELECT id FROM campaign_suppression WHERE Email = ?', [$email])->count()) {
            return true;
        }
        $db->insert('campaign_suppression', [
            'Email'   => $email,
            'Reason'  => substr((string)$reason, 0, 100),
            'Created' => date('Y-m-d H:i:s'),
        ]);
        return !$db->error();
    }
}

if (!function_exists('campaignSendBatch')) {
    /**
     * Send up to $limit queued messages for a campaign via the core email() mailer.
     * Returns ['sent' => n, 'failed' => n, 'remaining' => n].
     */
    function campaignSendBatch($campaignId, $limit = null)
    {
        global $db;
        $campaignId = (int)$campaignId;
        $cfg = campaignsConfig();
        if ($limit === null) {
            $limit = $cfg ? (int)$cfg->BatchSize : 25;
        }
        $limit = max(1, (int)$limit);

        $c = $db->query('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$c->count()) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0];
        }
        $campaign = $c->first();

        $db->update('campaigns', $campaignId, ['Status' => 'sending']);

        $rows = $db->query(
            'SELECT * FROM campaign_sends WHERE fkCampaignID = ? AND Status = ? ORDER BY id ASC LIMIT ' . $limit,
            [$campaignId, 'queued']
        )->results();

        $sent = 0;
        $failed = 0;
        $opts = [];
        if ($cfg && $cfg->FromEmail) {
            $opts['email'] = $cfg->FromEmail;
        }
        if ($cfg && $cfg->FromName) {
            $opts['name'] = $cfg->FromName;
        }

        foreach ($rows as $row) {
            // Personalization tokens - pulled per recipient, HTML-escaped before substitution.
            $tokens = ['{{email}}' => $row->Email, '{{fname}}' => '', '{{lname}}' => '', '{{username}}' => ''];
            if (!empty($row->fkUserID)) {
                $u = $db->query('SELECT fname, lname, username FROM users WHERE id = ?', [(int)$row->fkUserID]);
                if ($u->count()) {
                    $uu = $u->first();
                    $tokens['{{fname}}'] = (string)$uu->fname;
                    $tokens['{{lname}}'] = (string)$uu->lname;
                    $tokens['{{username}}'] = (string)$uu->username;
                }
            }
            $esc = array_map(function ($v) {
                return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            }, $tokens);
            $subject = strtr($campaign->Subject, $esc);
            $body = strtr($campaign->Body, $esc);

            // Append an unsubscribe footer
            $body .= '<br><br><hr><small>If you no longer wish to receive these emails, '
                . '<a href="' . campaignUnsubUrl($row->Email) . '">unsubscribe here</a>.</small>';

            $ok = false;
            try {
                $ok = email($row->Email, $subject, $body, $opts);
            } catch (Exception $e) {
                $ok = false;
            }

            if ($ok) {
                $db->update('campaign_sends', $row->id, ['Status' => 'sent', 'SentAt' => date('Y-m-d H:i:s'), 'Error' => null]);
                $sent++;
            } else {
                $db->update('campaign_sends', $row->id, ['Status' => 'failed', 'Error' => 'Mailer returned failure']);
                $failed++;
            }
        }

        $remaining = (int)$db->query('SELECT COUNT(*) c FROM campaign_sends WHERE fkCampaignID = ? AND Status = ?', [$campaignId, 'queued'])->first()->c;
        if ($remaining === 0) {
            $db->update('campaigns', $campaignId, ['Status' => 'sent', 'SentAt' => date('Y-m-d H:i:s')]);
        }

        // Heartbeat integration (optional)
        if (function_exists('cronBeat')) {
            cronBeat('campaigns-send', 'ok', "campaign {$campaignId}: +{$sent} sent, {$remaining} left");
        }

        if (function_exists('logger')) {
            logger(0, 'Campaigns', "Campaign #{$campaignId} '{$campaign->Name}' batch: {$sent} sent, {$failed} failed, {$remaining} remaining");
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }
}

if (!function_exists('campaignRateAllow')) {
    /**
     * Throttle a campaign action via the core RateLimit class. Reuses UserSpice's DB-backed limiter;
     * we inject our own action definitions into the global $rateLimits config (without editing core).
     * Returns true if allowed, false if the caller should back off.
     */
    function campaignRateAllow($action, $identifiers = [])
    {
        global $rateLimits, $abs_us_root, $us_url_root;
        if (!class_exists('RateLimit')) {
            return true; // limiter unavailable - fail open rather than block legitimate users
        }
        if (!isset($rateLimits)) {
            @require_once $abs_us_root . $us_url_root . 'users/includes/rate_limits.php';
        }
        if (!isset($rateLimits['campaign_unsub'])) {
            $rateLimits['campaign_unsub'] = ['ip_max' => 30, 'ip_window' => 3600, 'total_max' => 60, 'total_window' => 3600];
        }
        if (!isset($rateLimits['campaign_send'])) {
            $rateLimits['campaign_send'] = ['ip_max' => 200, 'ip_window' => 3600, 'total_max' => 300, 'total_window' => 3600];
        }
        try {
            $rl = new RateLimit();
            $ok = $rl->check($action, $identifiers);
            $rl->record($action, $identifiers, $ok);
            return $ok;
        } catch (Exception $e) {
            return true;
        }
    }
}

if (!function_exists('campaignStats')) {
    function campaignStats($campaignId)
    {
        global $db;
        $campaignId = (int)$campaignId;
        $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
        try {
            $rows = $db->query('SELECT Status, COUNT(*) c FROM campaign_sends WHERE fkCampaignID = ? GROUP BY Status', [$campaignId])->results();
            foreach ($rows as $r) {
                $out[$r->Status] = (int)$r->c;
                $out['total'] += (int)$r->c;
            }
        } catch (Exception $e) {
        }
        return $out;
    }
}
