<?php
// Public unsubscribe landing page. Themed via the active UserSpice template.
require_once dirname(__DIR__, 4) . '/users/init.php';

$email = Input::get('e');
$token = Input::get('t');
$done = false;
$valid = false;
$throttled = false;

$ip = Server::getClientIp();
if (function_exists('campaignRateAllow') && !campaignRateAllow('campaign_unsub', ['ip' => $ip])) {
    $throttled = true;
} elseif ($email !== '' && $token !== '' && function_exists('campaignVerifyUnsub')) {
    $valid = campaignVerifyUnsub($email, $token);
    if ($valid) {
        campaignSuppress($email, 'unsubscribe');
        $done = true;
    }
}

require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
?>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-6">
      <div class="card">
        <div class="card-body text-center p-4">
          <?php if ($throttled) { ?>
            <h3 class="mb-3">Too many requests</h3>
            <p class="text-muted">Please wait a little while and try your unsubscribe link again.</p>
            <i class="fa fa-clock text-warning" style="font-size:3rem"></i>
          <?php } elseif ($done) { ?>
            <h3 class="mb-3">You're unsubscribed</h3>
            <p class="text-muted"><?= safeReturn($email) ?> will no longer receive campaign emails from this site.</p>
            <i class="fa fa-check-circle text-success" style="font-size:3rem"></i>
          <?php } else { ?>
            <h3 class="mb-3">Unsubscribe link invalid</h3>
            <p class="text-muted">This unsubscribe link is missing or has expired. Please use the link from your most recent email.</p>
            <i class="fa fa-exclamation-triangle text-warning" style="font-size:3rem"></i>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
