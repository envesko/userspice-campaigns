<?php
// Email Campaigns - admin config page. Rendered inside admin.php?view=plugins_config&plugin=campaigns
if (!in_array($user->data()->id, $master_account)) {
    die('You do not have permission to configure this plugin.');
}
global $db;

$selfUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=campaigns';

// ---- POST actions ----
if (Input::exists()) {
    if (!Token::check(Input::get('csrf'))) {
        usError('Invalid security token. Please refresh and try again.');
        Redirect::to($selfUrl);
    }
    $do = Input::get('do');

    if ($do === 'save_config') {
        $db->update('campaigns_config', 1, [
            'FromEmail' => Input::get('from_email'),
            'FromName'  => Input::get('from_name'),
            'BatchSize' => max(1, (int)Input::get('batch_size')),
        ]);
        usSuccess('Settings saved.');
        Redirect::to($selfUrl);
    }

    if ($do === 'create') {
        $segment = [
            'permission'     => (int)Input::get('seg_permission'),
            'joined_after'   => Input::get('seg_joined_after'),
            'joined_before'  => Input::get('seg_joined_before'),
            'active_only'    => Input::get('seg_active') ? 1 : 0,
            'logged_in_only' => Input::get('seg_loggedin') ? 1 : 0,
        ];
        $name = Input::get('name');
        $subject = Input::get('subject');
        // Body may contain HTML on purpose (admin-authored) - store raw, render with trustedHtml.
        $body = $_POST['body'] ?? '';
        if (trim($name) === '' || trim($subject) === '') {
            usError('Name and subject are required.');
            Redirect::to($selfUrl);
        }
        $db->insert('campaigns', [
            'Name'        => $name,
            'Subject'     => $subject,
            'Body'        => $body,
            'SegmentJson' => json_encode($segment),
            'Status'      => 'draft',
            'Created'     => date('Y-m-d H:i:s'),
            'CreatedBy'   => (int)$user->data()->id,
        ]);
        logger($user->data()->id, 'Campaigns', "Created campaign '{$name}'");
        usSuccess('Campaign created as a draft.');
        Redirect::to($selfUrl);
    }

    if ($do === 'queue') {
        $n = campaignQueue((int)Input::get('id'));
        usSuccess("Queued {$n} recipient(s).");
        Redirect::to($selfUrl);
    }

    if ($do === 'send') {
        if (function_exists('campaignRateAllow') && !campaignRateAllow('campaign_send', ['user_id' => $user->data()->id])) {
            usError('You are sending batches too quickly. Please wait a moment.');
            Redirect::to($selfUrl);
        }
        $res = campaignSendBatch((int)Input::get('id'));
        usSuccess("Batch sent: {$res['sent']} ok, {$res['failed']} failed, {$res['remaining']} remaining.");
        Redirect::to($selfUrl);
    }

    if ($do === 'delete') {
        $id = (int)Input::get('id');
        $db->query('DELETE FROM campaigns WHERE id = ?', [$id]);
        $db->query('DELETE FROM campaign_sends WHERE fkCampaignID = ?', [$id]);
        usSuccess('Campaign deleted.');
        Redirect::to($selfUrl);
    }

    if ($do === 'suppress') {
        campaignSuppress(Input::get('email'), 'manual');
        logger($user->data()->id, 'Campaigns', 'Manually suppressed ' . Input::get('email'));
        usSuccess('Email added to suppression list.');
        Redirect::to($selfUrl);
    }
}

$cfg = campaignsConfig();
$permissions = $db->query('SELECT id, name FROM permissions ORDER BY id ASC')->results();
$campaignRows = $db->query('SELECT * FROM campaigns ORDER BY id DESC')->results();
$suppressed = $db->query('SELECT * FROM campaign_suppression ORDER BY Created DESC')->results();
?>
<style>
  .ehb-hero { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); color:#fff; border-radius:.6rem; padding:1rem 1.25rem; margin-bottom:1rem; }
  .ehb-hero h2 { color:#fff; margin:0; font-weight:600; }
  .ehb-hero p { margin:.25rem 0 0; opacity:.95; }
  .ehb-btn { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); border:0; color:#fff; }
  .ehb-btn:hover { filter:brightness(1.07); color:#fff; }
</style>
<div class="container-fluid">
  <div class="ehb-hero">
    <h2><i class="fa fa-envelope me-2"></i>Email Campaigns <span style="font-weight:400;opacity:.85;font-size:1rem">by Envesko</span></h2>
    <p>Segmented bulk email with per-recipient tracking and one-click unsubscribe. Uses your configured UserSpice mailer.</p>
  </div>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-campaigns" type="button">Campaigns</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-new" type="button">New campaign</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button">Settings</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-suppress" type="button">Suppression (<?= count($suppressed) ?>)</button></li>
  </ul>

  <div class="tab-content">
    <!-- Campaigns list -->
    <div class="tab-pane fade show active" id="tab-campaigns">
      <?php if (empty($campaignRows)) { ?>
        <p class="text-muted">No campaigns yet. Create one in the “New campaign” tab.</p>
      <?php } else { ?>
        <table class="table table-striped align-middle">
          <thead><tr><th>Campaign</th><th>Status</th><th>Sent / Failed / Queued</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($campaignRows as $c) {
              $stats = campaignStats($c->id);
              $segment = json_decode($c->SegmentJson, true) ?: [];
              $estimate = campaignRecipientCount($segment);
          ?>
            <tr>
              <td>
                <strong><?= safeReturn($c->Name) ?></strong><br>
                <small class="text-muted"><?= safeReturn($c->Subject) ?></small><br>
                <small class="text-muted">Segment matches ~<?= (int)$estimate ?> user(s)</small>
              </td>
              <td><span class="badge bg-secondary"><?= safeReturn($c->Status) ?></span></td>
              <td><?= (int)$stats['sent'] ?> / <?= (int)$stats['failed'] ?> / <?= (int)$stats['queued'] ?></td>
              <td>
                <form method="post" action="<?= safeReturn($selfUrl) ?>" class="d-inline">
                  <?= tokenHere() ?><input type="hidden" name="id" value="<?= (int)$c->id ?>">
                  <input type="hidden" name="do" value="queue">
                  <button class="btn btn-sm btn-outline-secondary" title="Build the recipient list">Queue</button>
                </form>
                <form method="post" action="<?= safeReturn($selfUrl) ?>" class="d-inline"
                  onsubmit="return confirm('Send the next batch now? This will email up to your batch size of the <?= (int)$stats['queued'] ?> queued recipient(s).');">
                  <?= tokenHere() ?><input type="hidden" name="id" value="<?= (int)$c->id ?>">
                  <input type="hidden" name="do" value="send">
                  <button class="btn btn-sm btn-primary" title="Send the next batch"
                    <?= $stats['queued'] == 0 ? 'disabled' : '' ?>>Send batch</button>
                </form>
                <form method="post" action="<?= safeReturn($selfUrl) ?>" class="d-inline"
                  onsubmit="return confirm('Delete this campaign and its send log?');">
                  <?= tokenHere() ?><input type="hidden" name="id" value="<?= (int)$c->id ?>">
                  <input type="hidden" name="do" value="delete">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        <p class="text-muted small">“Send batch” sends up to your configured batch size each click. Repeat until Queued reaches 0, or point the Cron Heartbeat example job at <code>files/cron_send.php</code> to drain the queue automatically.</p>
      <?php } ?>
    </div>

    <!-- New campaign -->
    <div class="tab-pane fade" id="tab-new">
      <form method="post" action="<?= safeReturn($selfUrl) ?>">
        <?= tokenHere() ?><input type="hidden" name="do" value="create">
        <div class="row">
          <div class="col-12 col-lg-7">
            <div class="mb-3">
              <label class="form-label">Campaign name (internal)</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Body (HTML allowed)</label>
              <textarea name="body" class="form-control" rows="10" placeholder="<p>Hello {{fname}}!</p>"></textarea>
              <div class="form-text">Personalization tokens (per recipient): <code>{{fname}}</code> <code>{{lname}}</code> <code>{{username}}</code> <code>{{email}}</code>. Usable in the subject too.</div>
            </div>
          </div>
          <div class="col-12 col-lg-5">
            <div class="card">
              <div class="card-header">Segment (who receives it)</div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label">Has permission</label>
                  <select name="seg_permission" class="form-select">
                    <option value="0">Any (all users)</option>
                    <?php foreach ($permissions as $p) { ?>
                      <option value="<?= (int)$p->id ?>"><?= safeReturn($p->name) ?> (#<?= (int)$p->id ?>)</option>
                    <?php } ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Joined after</label>
                  <input type="date" name="seg_joined_after" class="form-control">
                </div>
                <div class="mb-3">
                  <label class="form-label">Joined before</label>
                  <input type="date" name="seg_joined_before" class="form-control">
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="seg_active" id="seg_active" checked>
                  <label class="form-check-label" for="seg_active">Active users only</label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="seg_loggedin" id="seg_loggedin">
                  <label class="form-check-label" for="seg_loggedin">Has logged in at least once</label>
                </div>
                <p class="small text-muted mb-0">Suppressed (unsubscribed) emails are always excluded.</p>
              </div>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-success mt-2">Create draft</button>
      </form>
    </div>

    <!-- Settings -->
    <div class="tab-pane fade" id="tab-settings">
      <form method="post" action="<?= safeReturn($selfUrl) ?>" style="max-width:520px">
        <?= tokenHere() ?><input type="hidden" name="do" value="save_config">
        <div class="mb-3">
          <label class="form-label">From email (optional)</label>
          <input type="email" name="from_email" class="form-control" value="<?= safeReturn($cfg ? $cfg->FromEmail : '') ?>" placeholder="defaults to your UserSpice mailer">
        </div>
        <div class="mb-3">
          <label class="form-label">From name (optional)</label>
          <input type="text" name="from_name" class="form-control" value="<?= safeReturn($cfg ? $cfg->FromName : '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Batch size (emails per send)</label>
          <input type="number" min="1" name="batch_size" class="form-control" style="max-width:140px" value="<?= safeReturn($cfg ? (string)$cfg->BatchSize : '25') ?>">
        </div>
        <button type="submit" class="btn btn-primary">Save settings</button>
      </form>
    </div>

    <!-- Suppression -->
    <div class="tab-pane fade" id="tab-suppress">
      <form method="post" action="<?= safeReturn($selfUrl) ?>" class="row g-2 mb-3" style="max-width:520px">
        <?= tokenHere() ?><input type="hidden" name="do" value="suppress">
        <div class="col-8"><input type="email" name="email" class="form-control" placeholder="email to suppress" required></div>
        <div class="col-4"><button class="btn btn-outline-danger w-100">Suppress</button></div>
      </form>
      <?php if (empty($suppressed)) { ?>
        <p class="text-muted">No suppressed addresses.</p>
      <?php } else { ?>
        <table class="table table-sm">
          <thead><tr><th>Email</th><th>Reason</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($suppressed as $s) { ?>
            <tr><td><?= safeReturn($s->Email) ?></td><td><?= safeReturn($s->Reason) ?></td><td><?= safeReturn($s->Created) ?></td></tr>
          <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>
  </div>
</div>
