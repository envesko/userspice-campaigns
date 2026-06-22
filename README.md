# Email Campaigns (UserSpice plugin)

Send segmented email campaigns to your users with per-recipient tracking and one-click
unsubscribe suppression. Uses UserSpice's core `email()` mailer, so it respects whatever
transport you have configured (PHPMailer/SMTP, Brevo, SendGrid, …).

## Install
1. Copy this folder to `usersc/plugins/campaigns/`.
2. Admin → Plugin Manager → activate **Email Campaigns**.
3. Configure a From name/email and batch size on the config page.

## Use
On the plugin's config page:
- **New campaign** - name, subject, HTML body, and a **segment** built from safe, whitelisted
  criteria (permission level, join date, active, has-logged-in). No raw SQL is ever accepted.
- **Queue** - builds the recipient list (skips suppressed/unsubscribed + dupes).
- **Send batch** - sends up to your batch size per click, or point a scheduler at
  `files/cron_send.php` to drain the queue automatically (gated by Cron Heartbeat if installed).

Every email gets an unsubscribe link (`files/unsubscribe.php`) that adds the address to the
suppression list. Suppressed addresses are excluded from all future sends.

**Personalization:** use `{{fname}}` `{{lname}}` `{{username}}` `{{email}}` in the subject or body -
each is substituted (HTML-escaped) per recipient at send time.

**Abuse controls:** the public unsubscribe endpoint and the manual "Send batch" action are both
rate-limited via the core `RateLimit` class. The first send of a batch shows a confirm with the
recipient count.

## Tables
`campaigns`, `campaign_sends` (per-recipient status), `campaign_suppression`, `campaigns_config`.
Retained on uninstall so send history + suppression list survive a reinstall.

## Integrations
- **Cron Heartbeat** - `files/cron_send.php` records a heartbeat each run.
- Email transport - any plugin that overrides the core `email()` function (Brevo, SendGrid).

---

© 2026 **Envesko** - released under the MIT License (see `LICENSE`). Author, maintainer, and code owner: Envesko.
