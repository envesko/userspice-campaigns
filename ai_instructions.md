# Email Campaigns - AI instructions

Segmented bulk email for UserSpice with per-recipient tracking, suppression, and a safe whitelist
segment builder. Sends through core `email()` (so it respects Brevo/SendGrid/SMTP overrides).

## Public functions (functions.php, auto-loaded when active)
- `campaignSegmentSql($segment)` - translates a whitelisted segment array to a bound WHERE clause.
  Allowed keys ONLY: `permission`, `joined_after`, `joined_before`, `active_only`, `logged_in_only`.
- `campaignRecipients($segment)` / `campaignRecipientCount($segment)` - recipients minus suppressed.
- `campaignQueue($campaignId)` - populate `campaign_sends` (dedupe + skip suppressed).
- `campaignSendBatch($campaignId, $limit=null)` - send a batch via `email()`; personalizes
  `{{fname}}/{{lname}}/{{username}}/{{email}}` (HTML-escaped); returns sent/failed/remaining.
- `campaignSuppress($email, $reason)` · `campaignUnsubUrl($email)` / `campaignVerifyUnsub($email,$token)`
  (HMAC-signed) · `campaignStats($id)` · `campaignRateAllow($action, $ids)` (core RateLimit wrapper).

## Tables
`campaigns` (Name, Subject, Body, SegmentJson, Status) · `campaign_sends` (per-recipient status) ·
`campaign_suppression` (unsubscribed) · `campaigns_config` (FromEmail, FromName, BatchSize, Secret).

## Lifecycle files
install/activate/uninstall (retain data)/delete (drop tables)/migrate/configure. Public unsubscribe
page `files/unsubscribe.php` (themed, HMAC + rate-limited). Optional background sender
`files/cron_send.php` (integrates with Cron Heartbeat).

## Key conventions
NEVER accept raw SQL from the UI - segments are whitelist-only. Unsubscribe is HMAC-signed +
rate-limited. Suppression is always excluded from sends. `email()` requires a configured mailer.
Dashboard widget at `usersc/widgets/campaigns`.
