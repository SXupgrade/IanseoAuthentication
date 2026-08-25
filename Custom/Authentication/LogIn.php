<?php
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
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
    if (!authCsrfCheck()) {
        $error = authText('ErrCsrf');
    } elseif ($isInitialSetup) {
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($password !== $confirmPassword) {
            $error = authText('ErrPasswordsMismatch');
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

$PAGE_TITLE = $isInitialSetup ? authText('PageTitleSetup') : authText('PageTitleLogin');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-auth-shell{max-width:860px;margin:22px auto;padding:0 14px}.cp-auth-hero{border:1px solid #d8e3f0;border-radius:18px;background:linear-gradient(135deg,#f7fbff 0%,#ffffff 55%,#eef7f3 100%);box-shadow:0 18px 40px rgba(30,55,90,.08);overflow:hidden}.cp-auth-brand{display:flex;gap:14px;align-items:center;padding:18px 20px;border-bottom:1px solid #e7edf5}.cp-auth-logo{width:48px;height:48px;border-radius:14px;background:#10233f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;letter-spacing:-.04em}.cp-auth-title{margin:0;font-size:24px;line-height:1.15}.cp-auth-subtitle{margin:4px 0 0;color:#5f6f83}.cp-auth-body{padding:20px}.cp-auth-note{margin-top:14px;padding:11px 13px;border-radius:12px;background:#f3f7fb;border:1px solid #dde8f3;color:#3e4f63}.cp-auth-note a{font-weight:700}.cp-auth-footer{padding:12px 20px;border-top:1px solid #e7edf5;background:#fbfdff;color:#6a7787;font-size:.95em}.cp-auth-footer a{font-weight:700}.cp-auth-form table.Tabella{width:100%}.cp-auth-form input[type=text],.cp-auth-form input[type=password]{max-width:340px;width:90%}.cp-btn{display:inline-block;padding:.5rem 1.1rem;border-radius:8px;text-decoration:none;font-size:.9rem;font-weight:600;background:#10233f;color:#fff}.cp-btn:hover{background:#1c3a63}.cp-lang-switch{text-align:right;font-size:.8rem;margin-bottom:10px}.cp-lang-switch a{color:#5f6f83;text-decoration:none}.cp-lang-switch a:hover{text-decoration:underline}@media(max-width:720px){.cp-auth-brand{align-items:flex-start}.cp-auth-title{font-size:21px}}
</style>
<div class="cp-auth-shell">
  <?php echo authLanguageSwitcherHtml(); ?>
  <div class="cp-auth-hero">
    <div class="cp-auth-brand">
      <div class="cp-auth-logo">C+</div>
      <div>
        <h1 class="cp-auth-title"><?php echo htmlspecialchars($isInitialSetup ? authText('PageTitleSetup') : authText('PageTitleLogin')); ?></h1>
        <p class="cp-auth-subtitle"><?php echo htmlspecialchars(authText('Subtitle')); ?></p>
      </div>
    </div>
    <div class="cp-auth-body cp-auth-form">
    <?php if ($error) { ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php } ?>

    <?php if ($isInitialSetup) { ?>
        <div class="warning">
            <?php echo htmlspecialchars(authText('SetupWarning')); ?>
        </div>
        <form method="post">
            <?php echo authCsrfField(); ?>
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl); ?>">
            <table class="Tabella">
                <tr><th colspan="2"><?php echo htmlspecialchars(authText('CreateFirstAdmin')); ?></th></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldUser')); ?></td><td><input type="text" name="username" maxlength="16" autocomplete="username" required> <small><?php echo htmlspecialchars(authText('HintUsername')); ?></small></td></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldName')); ?></td><td><input type="text" name="name" maxlength="100" autocomplete="name"></td></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldPassword')); ?></td><td><input type="password" name="password" autocomplete="new-password" minlength="8" required></td></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldConfirmPassword')); ?></td><td><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></td></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldGrantedRights')); ?></td><td><?php echo htmlspecialchars(authText('GrantedRightsValue')); ?></td></tr>
                <tr><td colspan="2" class="Center"><input type="submit" value="<?php echo htmlspecialchars(authText('BtnCreateAdmin')); ?>"></td></tr>
            </table>
        </form>
    <?php } else { ?>
        <form method="post">
            <?php echo authCsrfField(); ?>
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnUrl); ?>">
            <table class="Tabella">
                <tr><th colspan="2"><?php echo htmlspecialchars(authText('SectionLogin')); ?></th></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldUser')); ?></td><td><input type="text" name="username" autocomplete="username" required></td></tr>
                <tr><td><?php echo htmlspecialchars(authText('FieldPassword')); ?></td><td><input type="password" name="password" autocomplete="current-password" required></td></tr>
                <tr><td colspan="2" class="Center"><input type="submit" value="<?php echo htmlspecialchars(authText('BtnLogin')); ?>"></td></tr>
            </table>
        </form>
        <?php if (authCompetplusEnabled()) { ?>
        <p style="text-align:center;color:#5f6f83;margin:14px 0"><?php echo htmlspecialchars(authText('Or')); ?></p>
        <p style="text-align:center">
            <a class="cp-btn" href="CompetplusStart.php<?php echo $returnUrl !== '' ? '?return=' . rawurlencode($returnUrl) : ''; ?>"><?php echo htmlspecialchars(authText('BtnLoginWithCompetplus')); ?></a>
        </p>
        <?php } ?>
    <?php } ?>
    <div class="cp-auth-note"><?php echo authText('NoteMadeAvailable', array('a' => '<a href="https://competplus.fr" target="_blank" rel="noopener noreferrer">Compet+</a>')); ?></div>
    </div>
    <div class="cp-auth-footer"><?php echo authText('FooterOpenSource', array('license' => '<code>LICENSE</code>', 'notice' => '<code>NOTICE</code>')); ?></div>
  </div>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
