<?php
/**
 * "Se connecter avec Compet+ (code)" -- Device Authorization Grant (RFC 8628) login page.
 * Entry point for CompetplusStart.php's redirect-based flow's sibling: an unauthenticated
 * visitor gets a short code + verification link instead of being redirected away from this page
 * at all -- useful when this install's own URL isn't one auth.competplus.fr's redirect_uri
 * whitelisting would ever see reliably (shared hosting, a WAMP box, a dynamic LAN address...).
 * Succeeds only if the resulting Compet+ identity is already linked to a local account (see
 * Account.php); NEVER creates an account -- same rule as CompetplusCallback.php's 'login' mode,
 * whose matching logic (competplusResolveLoginUser(), CompetplusOAuth.php) this page reuses
 * as-is.
 */
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/CompetplusDeviceAuth.php');

define('COMPETPLUS_DEVICE_SESSION_KEY', 'COMPETPLUS_DEVICE');

function competplusDeviceRedirectToLogin($message = '')
{
    $target = './LogIn.php';
    if ($message !== '') {
        $target .= '?competplus_error=' . rawurlencode($message);
    }
    CD_redirect($target);
    die();
}

// ── AJAX poll action ─────────────────────────────────────────────────────────────────────────
// Deliberate, narrow exception to this module's plain-POST-forms/full-page-reload culture (see
// every other page here): a "waiting for approval" screen is inherently poll-until-settled, and
// this is the one page that needs it. Reads the pending device_code from THIS session only --
// never accepts one from the client -- so a visitor can only ever poll their own pending flow.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll') {
    header('Content-Type: application/json');

    $pending = isset($_SESSION[COMPETPLUS_DEVICE_SESSION_KEY]) ? $_SESSION[COMPETPLUS_DEVICE_SESSION_KEY] : null;
    if (!is_array($pending) || empty($pending['deviceCode']) || intval($pending['expire']) < time()) {
        unset($_SESSION[COMPETPLUS_DEVICE_SESSION_KEY]);
        echo json_encode(array('status' => 'expired'));
        exit;
    }

    $config = authCompetplusConfig();
    if ($config === null) {
        unset($_SESSION[COMPETPLUS_DEVICE_SESSION_KEY]);
        echo json_encode(array('status' => 'error'));
        exit;
    }

    try {
        $poll = authCompetplusDevicePoll($pending['deviceCode'], $config);
    } catch (CompetplusOAuthException $e) {
        error_log('[CompetplusDeviceLogin] ' . $e->getMessage());
        echo json_encode(array('status' => 'error'));
        exit;
    }

    if ($poll['status'] === 'approved') {
        unset($_SESSION[COMPETPLUS_DEVICE_SESSION_KEY]);
        $user = competplusResolveLoginUser($poll);
        if (!$user) {
            echo json_encode(array('status' => 'no_account'));
            exit;
        }
        authCreateSession($user, null);
        $redirect = $pending['return'] !== '' ? $pending['return'] : ($CFG->ROOT_DIR . 'index.php');
        echo json_encode(array('status' => 'approved', 'redirect' => $redirect));
        exit;
    }

    if ($poll['status'] === 'denied' || $poll['status'] === 'expired') {
        unset($_SESSION[COMPETPLUS_DEVICE_SESSION_KEY]);
    }

    // 'pending'/'slow_down' -- keep the session entry, the client keeps polling (backing off on
    // slow_down, same convention as compet+ web's own device-auth.service.js).
    echo json_encode(array('status' => $poll['status']));
    exit;
}

// ── Initial page load: start the flow ───────────────────────────────────────────────────────
$returnUrl = authCompetplusSanitizeReturnUrl(rawurldecode((string)($_GET['return'] ?? '')));

$config = authCompetplusConfig();
if ($config === null) {
    competplusDeviceRedirectToLogin(authText('MsgCompetplusUnavailable'));
}

try {
    $start = authCompetplusDeviceStart($config);
} catch (CompetplusOAuthException $e) {
    error_log('[CompetplusDeviceLogin] ' . $e->getMessage());
    competplusDeviceRedirectToLogin(authText('MsgCompetplusUnavailable'));
}

$_SESSION[COMPETPLUS_DEVICE_SESSION_KEY] = array(
    'deviceCode' => $start['deviceCode'],
    'expire' => time() + $start['expiresIn'],
    'return' => $returnUrl,
);

$PAGE_TITLE = authText('DeviceLoginTitle');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-auth-shell{max-width:860px;margin:22px auto;padding:0 14px}.cp-auth-hero{border:1px solid #d8e3f0;border-radius:18px;background:linear-gradient(135deg,#f7fbff 0%,#ffffff 55%,#eef7f3 100%);box-shadow:0 18px 40px rgba(30,55,90,.08);overflow:hidden}.cp-auth-brand{display:flex;gap:14px;align-items:center;padding:18px 20px;border-bottom:1px solid #e7edf5}.cp-auth-logo{width:48px;height:48px;border-radius:14px;background:#eaf1f9;display:flex;align-items:center;justify-content:center;padding:8px;flex-shrink:0}.cp-auth-logo svg{width:100%;height:100%}.cp-auth-title{margin:0;font-size:24px;line-height:1.15}.cp-auth-body{padding:20px;text-align:center}.cp-btn{display:inline-block;padding:.5rem 1.1rem;border-radius:8px;text-decoration:none;font-size:.9rem;font-weight:600;background:#004488;color:#fff}.cp-btn:hover{background:#003366}.cp-lang-switch{text-align:right;font-size:.8rem;margin-bottom:10px}.cp-lang-switch a{color:#5f6f83;text-decoration:none}.cp-lang-switch a:hover{text-decoration:underline}.cp-device-code{font-size:1.9rem;font-weight:700;letter-spacing:.08em;margin:18px 0;padding:14px;border-radius:12px;background:#eef4fb;color:#1a2e44}.cp-device-waiting{color:#5f6f83;font-size:.9rem;margin-top:14px}.cp-device-error{color:#b00020;font-size:.9rem;margin-top:14px}
</style>
<div class="cp-auth-shell">
  <?php echo authLanguageSwitcherHtml(); ?>
  <div class="cp-auth-hero">
    <div class="cp-auth-brand">
      <div class="cp-auth-logo"><?php echo authCompetplusLogoSvg(); ?></div>
      <div>
        <h1 class="cp-auth-title"><?php echo htmlspecialchars(authText('DeviceLoginTitle')); ?></h1>
      </div>
    </div>
    <div class="cp-auth-body">
      <p><?php echo htmlspecialchars(authText('DeviceLoginInstructions')); ?></p>
      <div class="cp-device-code"><?php echo htmlspecialchars($start['userCode']); ?></div>
      <p><a class="cp-btn" href="<?php echo htmlspecialchars($start['verificationUriComplete'] !== '' ? $start['verificationUriComplete'] : $start['verificationUri']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(authText('DeviceLoginOpenLink')); ?></a></p>
      <p class="cp-device-waiting" id="cp-device-waiting"><?php echo htmlspecialchars(authText('DeviceLoginWaiting')); ?></p>
      <p class="cp-device-error" id="cp-device-error" style="display:none" role="alert"></p>
      <p><a href="./LogIn.php"><?php echo htmlspecialchars(authText('DeviceLoginBackToLogin')); ?></a></p>
    </div>
  </div>
</div>
<script>
(function () {
  var intervalMs = <?php echo max(1000, intval($start['interval']) * 1000); ?>;
  var waitingEl = document.getElementById('cp-device-waiting');
  var errorEl = document.getElementById('cp-device-error');

  function showError(message) {
    waitingEl.style.display = 'none';
    errorEl.textContent = message;
    errorEl.style.display = '';
  }

  function poll() {
    fetch('?ajax=poll', { headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.status === 'approved') {
          window.location.href = data.redirect || './';
          return;
        }
        if (data.status === 'no_account') {
          showError(<?php echo json_encode(authText('MsgNoAccountLinked')); ?>);
          return;
        }
        if (data.status === 'denied') {
          showError(<?php echo json_encode(authText('MsgDeviceLoginDenied')); ?>);
          return;
        }
        if (data.status === 'expired') {
          showError(<?php echo json_encode(authText('MsgDeviceLoginExpired')); ?>);
          return;
        }
        if (data.status === 'error') {
          showError(<?php echo json_encode(authText('MsgLoginFailed')); ?>);
          return;
        }
        if (data.status === 'slow_down') {
          intervalMs += 5000;
        }
        setTimeout(poll, intervalMs);
      })
      .catch(function () {
        setTimeout(poll, intervalMs);
      });
  }

  setTimeout(poll, intervalMs);
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
