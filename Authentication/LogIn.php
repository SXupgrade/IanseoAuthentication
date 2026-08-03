<?php
require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/AuthFunctions.php');

authEnsureTables();
$error = !empty($_GET['competplus_error']) ? (string)$_GET['competplus_error'] : '';
$isInitialSetup = authIsInitialSetupRequired();

function authLoginReturnUrl()
{
    $return = (string)($_POST['return'] ?? ($_GET['return'] ?? ''));
    if ($return === '') {
        return '';
    }
    $return = rawurldecode($return);
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $return) || substr($return, 0, 2) === '//') {
        return '';
    }
    if (stripos($return, '/Modules/Authentication/') !== false) {
        return '';
    }
    return $return;
}

$returnUrl = authLoginReturnUrl();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isInitialSetup) {
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (authCreateInitialAdmin($_POST['username'] ?? '', $_POST['name'] ?? '', $password, $error)) {
            CD_redirect($returnUrl !== '' ? $returnUrl : ($CFG->ROOT_DIR . 'index.php'));
            die();
        }
    } else {
        if (authLogin($_POST['username'] ?? '', $_POST['password'] ?? '', $error)) {
            CD_redirect($returnUrl !== '' ? $returnUrl : ($CFG->ROOT_DIR . 'index.php'));
            die();
        }
    }
}

$PAGE_TITLE = $isInitialSetup ? 'Authentication setup' : 'Authentication';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-auth-shell{max-width:860px;margin:22px auto;padding:0 14px}.cp-auth-hero{border:1px solid #d8e3f0;border-radius:18px;background:linear-gradient(135deg,#f7fbff 0%,#ffffff 55%,#eef7f3 100%);box-shadow:0 18px 40px rgba(30,55,90,.08);overflow:hidden}.cp-auth-brand{display:flex;gap:14px;align-items:center;padding:18px 20px;border-bottom:1px solid #e7edf5}.cp-auth-logo{width:48px;height:48px;border-radius:14px;background:#10233f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;letter-spacing:-.04em}.cp-auth-title{margin:0;font-size:24px;line-height:1.15}.cp-auth-subtitle{margin:4px 0 0;color:#5f6f83}.cp-auth-body{padding:20px}.cp-auth-note{margin-top:14px;padding:11px 13px;border-radius:12px;background:#f3f7fb;border:1px solid #dde8f3;color:#3e4f63}.cp-auth-note a{font-weight:700}.cp-auth-footer{padding:12px 20px;border-top:1px solid #e7edf5;background:#fbfdff;color:#6a7787;font-size:.95em}.cp-auth-footer a{font-weight:700}.cp-auth-form table.Tabella{width:100%}.cp-auth-form input[type=text],.cp-auth-form input[type=password]{max-width:340px;width:90%}.cp-btn{display:inline-block;padding:.5rem 1.1rem;border-radius:8px;text-decoration:none;font-size:.9rem;font-weight:600;background:#10233f;color:#fff}.cp-btn:hover{background:#1c3a63}@media(max-width:720px){.cp-auth-brand{align-items:flex-start}.cp-auth-title{font-size:21px}}
</style>
<div class="cp-auth-shell">
  <div class="cp-auth-hero">
    <div class="cp-auth-brand">
      <div class="cp-auth-logo">C+</div>
      <div>
        <h1 class="cp-auth-title"><?php echo $isInitialSetup ? 'Authentication setup' : 'Authentication'; ?></h1>
        <p class="cp-auth-subtitle">Secure access management for Ianseo tournaments.</p>
      </div>
    </div>
    <div class="cp-auth-body cp-auth-form">
    <?php if ($error) { ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php } ?>

    <?php if ($isInitialSetup) { ?>
        <div class="warning">
            No authentication user exists yet. Create the first administrator to enable the module safely.
        </div>
        <form method="post">
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl); ?>">
            <table class="Tabella">
                <tr><th colspan="2">Create first administrator</th></tr>
                <tr><td>User</td><td><input type="text" name="username" maxlength="16" autocomplete="username" required> <small>letters, digits, dot, dash, underscore</small></td></tr>
                <tr><td>Name</td><td><input type="text" name="name" maxlength="100" autocomplete="name"></td></tr>
                <tr><td>Password</td><td><input type="password" name="password" autocomplete="new-password" minlength="8" required></td></tr>
                <tr><td>Confirm password</td><td><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></td></tr>
                <tr><td>Granted rights</td><td>Root administrator + all competitions + read/write ACL baseline</td></tr>
                <tr><td colspan="2" class="Center"><input type="submit" value="Create administrator"></td></tr>
            </table>
        </form>
    <?php } else { ?>
        <form method="post">
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl); ?>">
            <table class="Tabella">
                <tr><th colspan="2">Login</th></tr>
                <tr><td>User</td><td><input type="text" name="username" autocomplete="username" required></td></tr>
                <tr><td>Password</td><td><input type="password" name="password" autocomplete="current-password" required></td></tr>
                <tr><td colspan="2" class="Center"><input type="submit" value="Login"></td></tr>
            </table>
        </form>
        <?php if (authCompetplusEnabled()) { ?>
        <p style="text-align:center;color:#5f6f83;margin:14px 0">or</p>
        <p style="text-align:center">
            <a class="cp-btn" href="CompetplusStart.php<?php echo $returnUrl !== '' ? '?return=' . rawurlencode($returnUrl) : ''; ?>">Se connecter avec Compet+</a>
        </p>
        <?php } ?>
    <?php } ?>
    <div class="cp-auth-note">This authentication module is made available by <a href="https://competplus.fr" target="_blank" rel="noopener noreferrer">Compet+</a>.</div>
    </div>
    <div class="cp-auth-footer">Open-source module with attribution. See <code>LICENSE</code> and <code>NOTICE</code>.</div>
  </div>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
