<?php
// Email Campaigns dashboard widget.
global $db;
$cmpTotal = 0;
$cmpSending = 0;
$cmpSent30 = 0;
try {
    $cmpTotal = (int)$db->query('SELECT COUNT(*) c FROM campaigns')->first()->c;
    $cmpSending = (int)$db->query('SELECT COUNT(*) c FROM campaigns WHERE Status IN (?,?)', ['queued', 'sending'])->first()->c;
    $cmpSent30 = (int)$db->query('SELECT COUNT(*) c FROM campaign_sends WHERE Status = ? AND SentAt >= (NOW() - INTERVAL 30 DAY)', ['sent'])->first()->c;
} catch (Exception $e) {
}
?>
<div class="card dash-card" data-id="<?= $widgetName ?>" id="<?= $widgetName ?>-card">
  <div class="card-header" id="<?= $widgetName ?>-card-header">
    <span class="collapseCard" data-card="<?= $widgetName ?>" id="<?= $widgetName ?>-caret"><i class="fa fa-caret-down"></i></span>
    <span class="card-title-text">Email Campaigns</span>
    <span class="float-end"><i class="fa-solid fa-grip ps-2 grippy"></i></span>
  </div>
  <div class="card-body" id="<?= $widgetName ?>-card-body">
    <div class="row text-center mb-2">
      <div class="col-4"><div class="h4 mb-0"><?= $cmpTotal ?></div><small class="text-muted">campaigns</small></div>
      <div class="col-4"><div class="h4 mb-0"><?= $cmpSending ?></div><small class="text-muted">in progress</small></div>
      <div class="col-4"><div class="h4 mb-0"><?= $cmpSent30 ?></div><small class="text-muted">sent / 30d</small></div>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="<?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=campaigns">Manage campaigns</a>
  </div>
</div>
