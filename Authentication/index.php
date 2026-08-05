<?php
require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/AuthFunctions.php');

authEnsureTables();
$error = '';
$message = '';

$canAdmin = !empty($_SESSION['AUTH_ROOT']) || ((($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1') && (($_SERVER['SERVER_ADDR'] ?? '') === '127.0.0.1' || ($_SERVER['SERVER_ADDR'] ?? '') === '::1'));
if (!$canAdmin) {
    CD_redirect('./LogIn.php');
    die();
}

function authPostBool($name) { return !empty($_POST[$name]) ? 1 : 0; }
function authSelectedUser() { return authNormalizeUsername($_GET['user'] ?? ($_POST['user'] ?? '')); }
function authKnownSubFeatures()
{
    return array(
        AclCompetition => array(
            'cData' => authText('SubFeat_cData'),
            'cSchedule' => authText('SubFeat_cSchedule'),
            'cFinalReport' => authText('SubFeat_cFinalReport'),
            'cExport' => authText('SubFeat_cExport'),
        ),
        AclParticipants => array(
            'pEntries' => authText('SubFeat_pEntries'),
            'pAdvancedEntries' => authText('SubFeat_pAdvancedEntries'),
            'pTarget' => authText('SubFeat_pTarget'),
            'pAdvancedTarget' => authText('SubFeat_pAdvancedTarget'),
        ),
        AclAccreditation => array(
            'acStandard' => authText('SubFeat_acStandard'),
            'acSetup' => authText('SubFeat_acSetup'),
        ),
        AclInternetPublish => array(
            'ipCredentials' => authText('SubFeat_ipCredentials'),
            'ipSend' => authText('SubFeat_ipSend'),
        ),
        AclISKServer => array(
            'iskManagement' => authText('SubFeat_iskManagement'),
            'iskUser' => authText('SubFeat_iskUser'),
        ),
        AclModules => array(
            'Authentication' => authText('SubFeat_ModAuthentication'),
            'competplus' => authText('SubFeat_ModCompetplus'),
            'Barcodes' => authText('SubFeat_ModBarcodes'),
            'Average' => authText('SubFeat_ModAverage'),
            'UpdateWeb' => authText('SubFeat_ModUpdateWeb'),
        ),
        AclAPI => array(
            'apiRead' => authText('SubFeat_apiRead'),
            'apiWrite' => authText('SubFeat_apiWrite'),
        ),
    );
}

function authBuildFeatureStringFromPost()
{
    $items = array();

    foreach (($_POST['perm'] ?? array()) as $feature => $level) {
        $feature = intval($feature);
        $level = intval($level);
        if ($feature > 0 && $level > AclNoAccess) {
            $items[] = $feature . '||' . min(AclReadWrite, $level);
        }
    }

    foreach (($_POST['subperm'] ?? array()) as $feature => $subRows) {
        $feature = intval($feature);
        if ($feature <= 0 || !is_array($subRows)) {
            continue;
        }
        foreach ($subRows as $subFeature => $level) {
            $subFeature = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$subFeature);
            $level = intval($level);
            if ($subFeature === '') {
                continue;
            }

            // Store explicit 0 too. This allows a subfeature to be denied while the parent remains read/write.
            if ($level >= AclNoAccess && $level <= AclReadWrite) {
                $items[] = $feature . '|' . $subFeature . '|' . $level;
            }
        }
    }

    return implode('#', $items);
}

function authAccessLabel($featureString)
{
    if (trim((string)$featureString) === '') {
        return authText('LabelVisibilityOnly');
    }
    $readOnly = 0;
    $readWrite = 0;
    $deniedSub = 0;
    foreach (authParseFeatureRules($featureString) as $data) {
        $level = authFeatureEffectiveLevel($data);
        if ($level >= AclReadWrite) {
            $readWrite++;
        } elseif ($level >= AclReadOnly) {
            $readOnly++;
        }
        foreach (($data['_sub'] ?? array()) as $subLevel) {
            if (intval($subLevel) === AclNoAccess) {
                $deniedSub++;
            }
        }
    }
    $deniedSuffix = $deniedSub
        ? authText($deniedSub > 1 ? 'DeniedSubSuffixPlural' : 'DeniedSubSuffix', array('count' => $deniedSub))
        : '';
    return authText('AccessSummary', array('write' => $readWrite, 'read' => $readOnly, 'deniedSuffix' => $deniedSuffix));
}

// Small helper for confirm('...') dialogs: a translated string may contain an apostrophe (French
// "d'accès" etc.) -- addslashes() escapes it for the single-quoted JS string literal FIRST, then
// htmlspecialchars() escapes the result for the double-quoted HTML attribute it sits in. Browsers
// decode the HTML entity back to a literal character before JS parses the attribute, so the
// backslash escape reaches the JS parser intact either way.
function authJsConfirmAttr($key)
{
    return htmlspecialchars(addslashes(authText($key)), ENT_QUOTES);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user = authNormalizeUsername($_POST['user'] ?? '');
    if ($user === '') {
        $error = authText('ErrUserRequired');
    } elseif ($action === 'save_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $enabled = authPostBool('enabled');
        $admin = authPostBool('admin');
        $password = (string)($_POST['password'] ?? '');
        $existing = authLoadUser($user);
        if (!$existing && $password === '') {
            $error = authText('ErrPasswordRequiredNew');
        } else {
            if ($password !== '') {
                $pwdSql = ', `AclUsPwd`=' . StrSafe_DB(authHashPassword($password));
                $insertPwd = StrSafe_DB(authHashPassword($password));
            } else {
                $pwdSql = '';
                $insertPwd = StrSafe_DB($existing->AclUsPwd);
            }
            if ($existing) {
                safe_w_SQL("UPDATE `AclUsers` SET `AclUsName`=" . StrSafe_DB($name) . ", `AclUsEnabled`={$enabled}, `AclUsAuthAdmin`={$admin}{$pwdSql} WHERE `AclUsUser`=" . StrSafe_DB($user));
            } else {
                safe_w_SQL("INSERT INTO `AclUsers` (`AclUsUser`, `AclUsName`, `AclUsPwd`, `AclUsEnabled`, `AclUsAuthAdmin`) VALUES (" . StrSafe_DB($user) . ", " . StrSafe_DB($name) . ", {$insertPwd}, {$enabled}, {$admin})");
            }
            authRefreshSessionFromStoredUser();
            $message = authText('MsgUserSaved');
        }
    } elseif ($action === 'delete_user') {
        safe_w_SQL("DELETE FROM `AclUserFeatures` WHERE `AclUFUser`=" . StrSafe_DB($user));
        safe_w_SQL("DELETE FROM `AclUsers` WHERE `AclUsUser`=" . StrSafe_DB($user));
        $message = authText('MsgUserDeleted');
        $user = '';
    } elseif ($action === 'save_rule') {
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        if (!empty($_POST['tournament_code'])) {
            $pattern = trim((string)$_POST['tournament_code']);
        }
        $features = authBuildFeatureStringFromPost();
        if ($pattern === '') {
            $error = authText('ErrPatternRequired');
        } else {
            safe_w_SQL("INSERT INTO `AclUserFeatures` (`AclUFUser`, `AclUFPattern`, `AclUFFeature`) VALUES (" . StrSafe_DB($user) . ", " . StrSafe_DB($pattern) . ", " . StrSafe_DB($features) . ") ON DUPLICATE KEY UPDATE `AclUFFeature`=" . StrSafe_DB($features));
            authRefreshSessionFromStoredUser();
            $message = authText('MsgRuleSaved');
        }
    } elseif ($action === 'delete_rule') {
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        safe_w_SQL("DELETE FROM `AclUserFeatures` WHERE `AclUFUser`=" . StrSafe_DB($user) . " AND `AclUFPattern`=" . StrSafe_DB($pattern));
        authRefreshSessionFromStoredUser();
        $message = authText('MsgRuleDeleted');
    }
}

$users = array();
$q = safe_r_SQL("SELECT * FROM `AclUsers` ORDER BY `AclUsUser`");
while ($r = safe_fetch($q)) { $users[] = $r; }

$selectedUser = authSelectedUser();
if ($selectedUser === '' && count($users)) {
    $selectedUser = (string)$users[0]->AclUsUser;
}
$selectedUserRow = $selectedUser !== '' ? authLoadUser($selectedUser) : null;
$selectedRules = $selectedUser !== '' ? authLoadUserRules($selectedUser) : array();

$tournaments = array();
$q = safe_r_SQL("SELECT ToCode, ToName, ToWhenFrom FROM Tournament ORDER BY ToWhenFrom DESC, ToCode ASC");
while ($r = safe_fetch($q)) { $tournaments[] = $r; }

$PAGE_TITLE = authText('PageTitleAdmin');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-admin-shell{max-width:1180px;margin:18px auto;padding:0 12px}.cp-admin-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:16px;padding:18px 20px;border:1px solid #d8e3f0;border-radius:18px;background:linear-gradient(135deg,#f7fbff 0%,#ffffff 58%,#eef7f3 100%);box-shadow:0 18px 40px rgba(30,55,90,.08)}.cp-admin-brand{display:flex;gap:14px;align-items:center}.cp-admin-logo{width:52px;height:52px;border-radius:15px;background:#10233f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:23px;letter-spacing:-.04em}.cp-admin-title{margin:0;font-size:26px;line-height:1.15}.cp-admin-subtitle{margin:5px 0 0;color:#5f6f83}.cp-admin-credit{font-size:.95em;color:#5f6f83;text-align:right}.cp-admin-credit a{font-weight:700}.auth-grid{display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start}.auth-card{border:1px solid #dde6ef;border-radius:14px;padding:14px;background:#fff;box-shadow:0 10px 24px rgba(30,55,90,.05)}.auth-card h2{margin-top:0}.auth-user{display:block;padding:10px;border-bottom:1px solid #eef2f6;text-decoration:none;border-radius:10px;color:inherit}.auth-user:hover{background:#f6f9fc}.auth-user strong{display:block}.auth-badge{display:inline-block;padding:2px 7px;border-radius:999px;background:#eef3f8;font-size:.85em}.auth-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.auth-permissions{width:100%;border-collapse:collapse}.auth-permissions th,.auth-permissions td{padding:7px;border-bottom:1px solid #edf1f5}.auth-subfeature td{background:#fafcff}.auth-subfeature td:first-child{padding-left:30px}.auth-feature-row td{font-weight:600;background:#fbfdff}.auth-permissions td.Center{text-align:center}.auth-muted{color:#667789}.auth-warning{background:#fff8e7;border:1px solid #ead08a;padding:10px 12px;border-radius:10px}.auth-success{background:#eaf8ef;border:1px solid #9bd0a6;padding:10px 12px;border-radius:10px}.auth-error{background:#fdecec;border:1px solid #e0a0a0;padding:10px 12px;border-radius:10px}.cp-license-note{margin-top:14px;padding:10px 12px;border-radius:10px;background:#f3f7fb;border:1px solid #dde8f3;color:#46586d}.cp-lang-switch{text-align:right;font-size:.8rem;margin-bottom:10px}.cp-lang-switch a{color:#5f6f83;text-decoration:none}.cp-lang-switch a:hover{text-decoration:underline}@media(max-width:900px){.auth-grid{grid-template-columns:1fr}.cp-admin-hero{display:block}.cp-admin-credit{text-align:left;margin-top:12px}}
</style>
<div class="cp-admin-shell">
    <?php echo authLanguageSwitcherHtml(); ?>
    <div class="cp-admin-hero">
        <div class="cp-admin-brand">
            <div class="cp-admin-logo">C+</div>
            <div>
                <h1 class="cp-admin-title"><?php echo htmlspecialchars(authText('PageTitleAdmin')); ?></h1>
                <p class="cp-admin-subtitle"><?php echo htmlspecialchars(authText('AdminSubtitle')); ?></p>
            </div>
        </div>
        <div class="cp-admin-credit"><?php echo htmlspecialchars(authText('ModuleCredit')); ?><br><a href="https://competplus.fr" target="_blank" rel="noopener noreferrer">Compet+</a></div>
    </div>
    <?php if (!empty($_SESSION['AUTH_User'])) { ?><p><?php echo htmlspecialchars(authText('LoggedAs')); ?> <b><?php echo htmlspecialchars($_SESSION['AUTH_User']); ?></b> — <a class="Link" href="LogOut.php"><?php echo htmlspecialchars(authText('BtnLogout')); ?></a></p><?php } ?>
    <?php if ($message) { ?><div class="auth-success"><?php echo htmlspecialchars($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="auth-error"><?php echo htmlspecialchars($error); ?></div><?php } ?>
    <p class="cp-license-note"><?php echo authText('FooterAdminCredit', array('a' => '<a href="https://competplus.fr" target="_blank" rel="noopener noreferrer">Compet+</a>')); ?></p>

    <div class="auth-grid">
        <div class="auth-card">
            <h2><?php echo htmlspecialchars(authText('SectionUsers')); ?></h2>
            <?php foreach ($users as $u) { ?>
                <a class="auth-user" href="?user=<?php echo urlencode($u->AclUsUser); ?>">
                    <strong><?php echo htmlspecialchars($u->AclUsUser); ?></strong>
                    <span><?php echo htmlspecialchars($u->AclUsName); ?></span><br>
                    <span class="auth-badge"><?php echo htmlspecialchars(intval($u->AclUsEnabled) ? authText('StatusEnabled') : authText('StatusDisabled')); ?></span>
                    <?php if (intval($u->AclUsAuthAdmin)) { ?><span class="auth-badge"><?php echo htmlspecialchars(authText('BadgeRoot')); ?></span><?php } ?>
                </a>
            <?php } ?>
        </div>

        <div class="auth-card">
            <h2><?php echo htmlspecialchars($selectedUserRow ? authText('SectionEditUser') : authText('SectionCreateUser')); ?></h2>
            <form method="post">
                <input type="hidden" name="action" value="save_user">
                <table class="Tabella">
                    <tr><td><?php echo htmlspecialchars(authText('FieldUser')); ?></td><td><input name="user" maxlength="16" required value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser ?? ''); ?>"> <small><?php echo htmlspecialchars(authText('HintUsername')); ?></small></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldName')); ?></td><td><input name="name" maxlength="100" value="<?php echo htmlspecialchars($selectedUserRow->AclUsName ?? ''); ?>"></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldPassword')); ?></td><td><input type="password" name="password" autocomplete="new-password"> <small><?php echo htmlspecialchars(authText('HintKeepPassword')); ?></small></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldEnabled')); ?></td><td><input type="checkbox" name="enabled" <?php echo (!$selectedUserRow || intval($selectedUserRow->AclUsEnabled)) ? 'checked' : ''; ?>></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldAdminRoot')); ?></td><td><input type="checkbox" name="admin" <?php echo ($selectedUserRow && intval($selectedUserRow->AclUsAuthAdmin)) ? 'checked' : ''; ?>> <small><?php echo htmlspecialchars(authText('HintRootBypass')); ?></small></td></tr>
                    <tr><td colspan="2" class="Center"><input type="submit" value="<?php echo htmlspecialchars(authText('BtnSaveUser')); ?>"></td></tr>
                </table>
            </form>
            <?php if ($selectedUserRow) { ?>
            <form method="post" onsubmit="return confirm('<?php echo authJsConfirmAttr('ConfirmDeleteUser'); ?>')">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user" value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser); ?>">
                <input type="submit" value="<?php echo htmlspecialchars(authText('BtnDeleteUser')); ?>">
            </form>
            <?php } ?>

            <?php if ($selectedUserRow) { ?>
                <h2><?php echo htmlspecialchars(authText('SectionAccessRules')); ?></h2>
                <table class="Tabella">
                    <tr><th><?php echo htmlspecialchars(authText('ColCompetitionPattern')); ?></th><th><?php echo htmlspecialchars(authText('ColAccessSummary')); ?></th><th><?php echo htmlspecialchars(authText('ColRawRule')); ?></th><th><?php echo htmlspecialchars(authText('ColActions')); ?></th></tr>
                    <?php foreach ($selectedRules as $rule) { ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($rule['pattern']); ?></code></td>
                            <td><?php echo htmlspecialchars(authAccessLabel($rule['features'])); ?></td>
                            <td><small><?php echo htmlspecialchars($rule['features'] === '' ? authText('RawRuleVisibilityOnly') : $rule['features']); ?></small></td>
                            <td>
                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo authJsConfirmAttr('ConfirmDeleteRule'); ?>')">
                                    <input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="user" value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser); ?>">
                                    <input type="hidden" name="pattern" value="<?php echo htmlspecialchars($rule['pattern']); ?>">
                                    <input type="submit" value="<?php echo htmlspecialchars(authText('BtnDelete')); ?>">
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </table>

                <h2><?php echo htmlspecialchars(authText('SectionAddUpdateAccess')); ?></h2>
                <form method="post">
                    <input type="hidden" name="action" value="save_rule">
                    <input type="hidden" name="user" value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser); ?>">
                    <table class="Tabella">
                        <tr>
                            <td><?php echo htmlspecialchars(authText('FieldCompetition')); ?></td>
                            <td>
                                <select name="tournament_code">
                                    <option value=""><?php echo htmlspecialchars(authText('OptionManualPattern')); ?></option>
                                    <?php foreach ($tournaments as $t) { ?>
                                        <option value="<?php echo htmlspecialchars($t->ToCode); ?>"><?php echo htmlspecialchars($t->ToCode . ' — ' . $t->ToName); ?></option>
                                    <?php } ?>
                                </select>
                            </td>
                        </tr>
                        <tr><td><?php echo htmlspecialchars(authText('FieldManualPattern')); ?></td><td><input name="pattern" placeholder="TOURCODE, FR%, FR*, *" maxlength="150"> <small><?php echo authText('HintStarAllCompetitions', array('star' => '<code>*</code>')); ?></small></td></tr>
                    </table>
                    <table class="auth-permissions">
                        <tr><th><?php echo htmlspecialchars(authText('ColFeatureSubfeature')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColNoAccess')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColReadOnly')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColReadWrite')); ?></th></tr>
                        <?php
                        global $listACL;
                        $knownSubFeatures = authKnownSubFeatures();
                        foreach ($listACL as $id => $label) {
                            $id = intval($id);
                            if ($id <= 0) continue;
                        ?>
                            <tr class="auth-feature-row">
                                <td><?php echo htmlspecialchars($label); ?> <span class="auth-muted">#<?php echo $id; ?></span></td>
                                <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="0" checked></td>
                                <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="1"></td>
                                <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="2"></td>
                            </tr>
                            <?php foreach (($knownSubFeatures[$id] ?? array()) as $subCode => $subLabel) { ?>
                                <tr class="auth-subfeature">
                                    <td>↳ <?php echo htmlspecialchars($subLabel); ?> <span class="auth-muted"><code><?php echo htmlspecialchars($subCode); ?></code></span></td>
                                    <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="0" checked></td>
                                    <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="1"></td>
                                    <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="2"></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </table>
                    <p><input type="submit" value="<?php echo htmlspecialchars(authText('BtnSaveAccessRule')); ?>"></p>
                </form>
            <?php } ?>
        </div>
    </div>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
