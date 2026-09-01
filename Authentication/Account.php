<?php
/**
 * Self-service page: link/unlink the CURRENTLY logged-in local account to/from Compet+. Deliberately
 * not gated on AUTH_ROOT -- any authenticated local user manages their own link, no admin
 * involvement needed (this module has no e-mail column on AclUsers to auto-match against, so
 * linking only ever happens from an already-authenticated session, never automatically).
 */
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/AuthFunctions.php');

if (empty($_SESSION['AUTH_User'])) {
    CD_redirect('./LogIn.php');
    die();
}

$username = $_SESSION['AUTH_User'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink') {
    if (!authCsrfCheck()) {
        $error = authText('ErrCsrf');
    } else {
        authUnlinkExternalIdentity($username, 'competplus');
        $message = authText('MsgUnlinked');
    }
}

if (!empty($_GET['competplus_message'])) {
    $message = (string)$_GET['competplus_message'];
}
if (!empty($_GET['competplus_error'])) {
    $error = (string)$_GET['competplus_error'];
}

$linked = authGetLinkedExternalIdentity($username, 'competplus');
$competplusEnabled = authCompetplusEnabled();

$PAGE_TITLE = authText('PageTitleAccount');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-account-shell{max-width:720px;margin:22px auto;padding:0 14px}.cp-account-card{border:1px solid #d8e3f0;border-radius:18px;background:#fff;box-shadow:0 18px 40px rgba(30,55,90,.08);padding:20px}.cp-account-card h1{margin-top:0;font-size:22px}.cp-account-status{padding:11px 13px;border-radius:12px;margin:12px 0}.cp-account-status.linked{background:#eaf8ef;border:1px solid #9bd0a6}.cp-account-status.unlinked{background:#f3f7fb;border:1px solid #dde8f3}.cp-btn{display:inline-block;padding:.5rem 1.1rem;border-radius:8px;text-decoration:none;font-size:.9rem;font-weight:600;background:#004488;color:#fff}.cp-btn:hover{background:#003366}.cp-lang-switch{text-align:right;font-size:.8rem;margin-bottom:10px}.cp-lang-switch a{color:#5f6f83;text-decoration:none}.cp-lang-switch a:hover{text-decoration:underline}
</style>
<div class="cp-account-shell">
  <?php echo authLanguageSwitcherHtml(); ?>
  <div class="cp-account-card">
    <h1><?php echo htmlspecialchars(authText('PageTitleAccount')); ?></h1>
    <p><?php echo htmlspecialchars(authText('LoggedAs')); ?> <b><?php echo htmlspecialchars($username); ?></b> — <a class="Link" href="LogOut.php"><?php echo htmlspecialchars(authText('BtnLogout')); ?></a></p>
    <?php if ($message) { ?><div class="auth-success cp-account-status linked"><?php echo htmlspecialchars($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="auth-error cp-account-status unlinked"><?php echo htmlspecialchars($error); ?></div><?php } ?>

    <h2><?php echo htmlspecialchars(authText('SectionCompetplusLogin')); ?></h2>
    <?php if (!$competplusEnabled) { ?>
        <div class="cp-account-status unlinked"><?php echo htmlspecialchars(authText('NotConfigured')); ?></div>
    <?php } elseif ($linked) { ?>
        <div class="cp-account-status linked"><?php echo htmlspecialchars(authText('LinkedSince', array('date' => $linked->LinkedAt))); ?></div>
        <form method="post" onsubmit="return confirm('<?php echo htmlspecialchars(addslashes(authText('ConfirmUnlink')), ENT_QUOTES); ?>')">
            <?php echo authCsrfField(); ?>
            <input type="hidden" name="action" value="unlink">
            <input type="submit" value="<?php echo htmlspecialchars(authText('BtnUnlink')); ?>">
        </form>
    <?php } else { ?>
        <div class="cp-account-status unlinked"><?php echo htmlspecialchars(authText('NotLinkedYet')); ?></div>
        <?php if (authCompetplusHasRedirectFlow()) { ?>
        <a class="cp-btn" href="CompetplusStart.php?mode=link"><?php echo htmlspecialchars(authText('BtnLinkAccount')); ?></a>
        <?php } ?>
    <?php } ?>
  </div>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
