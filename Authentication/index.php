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

// Human-readable top-level ACL feature names (authText('Feature_AclXxx')) instead of the raw
// Ianseo constant labels $listACL provides (e.g. "AclCompetition") -- mapped by the numeric
// feature id, which is a stable core define() (never reused for a different meaning), not by the
// $listACL label text. Falls back to the raw label for any id core might add later that this
// module doesn't know about yet, rather than showing nothing.
function authFeatureLabel($id, $rawLabel)
{
    static $keys = array(
        0 => 'Feature_AclRoot',
        1 => 'Feature_AclCompetition', 2 => 'Feature_AclInternetPublish', 3 => 'Feature_AclParticipants',
        4 => 'Feature_AclAccreditation', 5 => 'Feature_AclQualification', 6 => 'Feature_AclEliminations',
        7 => 'Feature_AclRobin', 8 => 'Feature_AclIndividuals', 9 => 'Feature_AclTeams',
        10 => 'Feature_AclSpeaker', 11 => 'Feature_AclOutput', 12 => 'Feature_AclISKClient',
        13 => 'Feature_AclISKServer', 14 => 'Feature_AclModules', 15 => 'Feature_AclAPI', 16 => 'Feature_AclOdf',
    );
    return isset($keys[$id]) ? authText($keys[$id]) : $rawLabel;
}

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
        // AclRoot (0) included on purpose -- see the matching note in authParseFeatureRules()
        // (AuthFunctions.php), which is what actually turns this stored string back into a grant.
        if ($feature >= AclRoot && $level > AclNoAccess) {
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

// Feature/subfeature level currently stored for $pattern's rule, for pre-filling the "edit
// access" form radios when opening the rights modal on an EXISTING rule (rather than always
// starting from "no access" on every field, which made "editing" a rule practically impossible
// without memorizing its raw string first).
function authRuleFeatureLevel($parsedRule, $featureId)
{
    return intval($parsedRule[$featureId]['_level'] ?? AclNoAccess);
}
function authRuleSubLevel($parsedRule, $featureId, $subCode)
{
    return intval($parsedRule[$featureId]['_sub'][$subCode] ?? AclNoAccess);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user = authNormalizeUsername($_POST['user'] ?? '');
    // Never let the currently logged-in admin disable or delete their OWN account from this
    // screen -- the module has no separate recovery path if that leaves zero enabled root users.
    $isSelfAction = $user !== '' && $user === ($_SESSION['AUTH_User'] ?? '');
    if (!authCsrfCheck()) {
        $error = authText('ErrCsrf');
    } elseif ($user === '') {
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
    } elseif ($action === 'toggle_enabled') {
        if ($isSelfAction) {
            $error = authText('ErrUserRequired'); // can't happen via the UI, defensive only
        } else {
            $existing = authLoadUser($user);
            if ($existing) {
                $newEnabled = intval($existing->AclUsEnabled) ? 0 : 1;
                safe_w_SQL("UPDATE `AclUsers` SET `AclUsEnabled`=" . $newEnabled . " WHERE `AclUsUser`=" . StrSafe_DB($user));
                authRefreshSessionFromStoredUser();
                $message = $newEnabled ? authText('MsgUserEnabled') : authText('MsgUserDisabled');
            }
        }
    } elseif ($action === 'delete_user') {
        if (!$isSelfAction) {
            safe_w_SQL("DELETE FROM `AclUserFeatures` WHERE `AclUFUser`=" . StrSafe_DB($user));
            safe_w_SQL("DELETE FROM `AclUsers` WHERE `AclUsUser`=" . StrSafe_DB($user));
            $message = authText('MsgUserDeleted');
            $user = '';
        }
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

// No fallback to $users[0] anymore: with the table+modal layout, arriving on the page should
// show just the table -- a modal only opens in response to an explicit click (?user=X&open=...
// or the "+ New user" button), never implicitly for whichever user happens to sort first.
$selectedUser = authSelectedUser();
$selectedUserRow = $selectedUser !== '' ? authLoadUser($selectedUser) : null;
$selectedRules = $selectedUser !== '' ? authLoadUserRules($selectedUser) : array();

$openModal = in_array($_GET['open'] ?? '', array('edit', 'rights'), true) ? $_GET['open'] : '';
// Rights only makes sense for an EXISTING user (nothing to grant access to before the account
// exists) -- silently ignored otherwise rather than showing a broken/empty modal.
if ($openModal === 'rights' && !$selectedUserRow) {
    $openModal = '';
}

// Pre-fill the "add/update access" form when editing an EXISTING rule (clicked from the rights
// list) instead of always starting blank -- see authRuleFeatureLevel()/authRuleSubLevel() above.
$editPattern = trim((string)($_GET['pattern'] ?? ''));
$editParsedRule = array();
if ($editPattern !== '') {
    foreach ($selectedRules as $rule) {
        if ($rule['pattern'] === $editPattern) {
            $editParsedRule = authParseFeatureRules($rule['features']);
            break;
        }
    }
}

$tournaments = array();
$q = safe_r_SQL("SELECT ToCode, ToName, ToWhenFrom FROM Tournament ORDER BY ToWhenFrom DESC, ToCode ASC");
while ($r = safe_fetch($q)) { $tournaments[] = $r; }

$PAGE_TITLE = authText('PageTitleAdmin');
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
.cp-admin-shell{max-width:1180px;margin:18px auto;padding:0 12px}.cp-admin-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:16px;padding:18px 20px;border:1px solid #d8e3f0;border-radius:18px;background:linear-gradient(135deg,#f7fbff 0%,#ffffff 58%,#eef7f3 100%);box-shadow:0 18px 40px rgba(30,55,90,.08)}.cp-admin-brand{display:flex;gap:14px;align-items:center}.cp-admin-logo{width:52px;height:52px;border-radius:15px;background:#10233f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:23px;letter-spacing:-.04em}.cp-admin-title{margin:0;font-size:26px;line-height:1.15}.cp-admin-subtitle{margin:5px 0 0;color:#5f6f83}.cp-admin-credit{font-size:.95em;color:#5f6f83;text-align:right}.cp-admin-credit a{font-weight:700}.auth-badge{display:inline-block;padding:2px 7px;border-radius:999px;background:#eef3f8;font-size:.85em}.auth-badge.on{background:#eaf8ef;color:#1e7b3a}.auth-badge.off{background:#fdecec;color:#a53939}.auth-badge.root{background:#fff3d6;color:#8a5a00}.auth-permissions{width:100%;border-collapse:collapse}.auth-permissions th,.auth-permissions td{padding:7px;border-bottom:1px solid #edf1f5}.auth-subfeature td{background:#fafcff}.auth-subfeature td:first-child{padding-left:30px}.auth-feature-row td{font-weight:600;background:#fbfdff}
.auth-feature-row-root td{background:#fff8e7!important}.auth-permissions td.Center{text-align:center}.auth-muted{color:#667789}.auth-success{background:#eaf8ef;border:1px solid #9bd0a6;padding:10px 12px;border-radius:10px}.auth-error{background:#fdecec;border:1px solid #e0a0a0;padding:10px 12px;border-radius:10px}.cp-license-note{margin-top:14px;padding:10px 12px;border-radius:10px;background:#f3f7fb;border:1px solid #dde8f3;color:#46586d}.cp-lang-switch{text-align:right;font-size:.8rem;margin-bottom:10px}.cp-lang-switch a{color:#5f6f83;text-decoration:none}.cp-lang-switch a:hover{text-decoration:underline}

.acc-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px}
.acc-table-wrap{overflow-x:auto;border:1px solid #dde6ef;border-radius:14px;background:#fff;box-shadow:0 10px 24px rgba(30,55,90,.05)}
.acc-table{width:100%;border-collapse:collapse;font-size:.9rem;min-width:560px}
.acc-table th{background:#f7fafc;color:#5f6f83;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:10px 14px;text-align:left;border-bottom:1.5px solid #e2e8f0}
.acc-table td{padding:10px 14px;border-bottom:1px solid #edf1f5;vertical-align:middle}
.acc-table tr:last-child td{border-bottom:none}
.acc-table tr:hover td{background:#f9fbfd}
.acc-table td.Center{text-align:center}

.kebab-wrap{position:relative;display:inline-block}
.kebab-btn{border:1.5px solid #dde6ef;background:#fff;border-radius:8px;width:32px;height:32px;font-size:1.1rem;line-height:1;cursor:pointer;color:#46586d}
.kebab-btn:hover{border-color:#10233f;color:#10233f}
.kebab-menu{display:none;position:absolute;right:0;top:38px;z-index:20;min-width:170px;background:#fff;border:1px solid #dde6ef;border-radius:10px;box-shadow:0 14px 30px rgba(16,24,40,.14);padding:6px;flex-direction:column;gap:2px}
.kebab-menu.open{display:flex}
.kebab-menu a,.kebab-menu button{display:block;width:100%;text-align:left;padding:8px 10px;border-radius:7px;border:none;background:none;font-size:.85rem;color:#26313f;text-decoration:none;cursor:pointer;font-family:inherit}
.kebab-menu a:hover,.kebab-menu button:hover{background:#f2f6fb}
.kebab-menu button.danger{color:#a53939}
.kebab-menu button.danger:hover{background:#fdecec}
.kebab-menu form{margin:0}

.cp-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);align-items:flex-start;justify-content:center;padding:32px 16px;z-index:100;overflow-y:auto}
.cp-modal{background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(16,24,40,.25);padding:22px;width:100%;max-width:640px}
.cp-modal-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px}
.cp-modal-head h2{margin:0;font-size:1.1rem}
.cp-modal-close{border:none;background:none;font-size:1.3rem;line-height:1;cursor:pointer;color:#5f6f83;padding:2px 6px;border-radius:6px}
.cp-modal-close:hover{background:#f2f6fb;color:#26313f}
.cp-btn{display:inline-block;padding:.5rem 1.1rem;border-radius:8px;text-decoration:none;font-size:.9rem;font-weight:600;background:#10233f;color:#fff}
.cp-btn:hover{background:#1c3a63}
.btn-xs-link{font-size:.82rem;color:#10233f;font-weight:600;text-decoration:none}
.btn-xs-link:hover{text-decoration:underline}
@media(max-width:900px){.cp-admin-hero{display:block}.cp-admin-credit{text-align:left;margin-top:12px}}
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

    <div class="acc-toolbar">
        <h2 style="margin:0;flex:1"><?php echo htmlspecialchars(authText('SectionUsers')); ?></h2>
        <a class="cp-btn" href="?open=edit"><?php echo htmlspecialchars(authText('BtnNewUser')); ?></a>
    </div>

    <div class="acc-table-wrap">
        <table class="acc-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(authText('ColLogin')); ?></th>
                    <th><?php echo htmlspecialchars(authText('ColName')); ?></th>
                    <th><?php echo htmlspecialchars(authText('ColStatus')); ?></th>
                    <th class="Center"><?php echo htmlspecialchars(authText('ColAdmin')); ?></th>
                    <th class="Center"><?php echo htmlspecialchars(authText('ColActions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users) { ?>
                    <tr><td colspan="5" class="Center auth-muted"><?php echo htmlspecialchars(authText('NoUsers')); ?></td></tr>
                <?php } ?>
                <?php foreach ($users as $u) {
                    $isSelfRow = $u->AclUsUser === ($_SESSION['AUTH_User'] ?? '');
                    $enabled = intval($u->AclUsEnabled);
                ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($u->AclUsUser); ?></code><?php if ($isSelfRow) { ?> <span class="auth-muted">(<?php echo htmlspecialchars(authText('LoggedAs')); ?>)</span><?php } ?></td>
                    <td><?php echo htmlspecialchars($u->AclUsName); ?></td>
                    <td><span class="auth-badge <?php echo $enabled ? 'on' : 'off'; ?>"><?php echo htmlspecialchars($enabled ? authText('StatusEnabled') : authText('StatusDisabled')); ?></span></td>
                    <td class="Center"><?php echo intval($u->AclUsAuthAdmin) ? '<span class="auth-badge root">' . htmlspecialchars(authText('BadgeRoot')) . '</span>' : '—'; ?></td>
                    <td class="Center">
                        <div class="kebab-wrap">
                            <button type="button" class="kebab-btn" onclick="toggleKebab(this)" aria-label="…">⋯</button>
                            <div class="kebab-menu">
                                <a href="?user=<?php echo urlencode($u->AclUsUser); ?>&open=edit"><?php echo htmlspecialchars(authText('ActionEdit')); ?></a>
                                <a href="?user=<?php echo urlencode($u->AclUsUser); ?>&open=rights"><?php echo htmlspecialchars(authText('ActionRights')); ?></a>
                                <?php if (!$isSelfRow) { ?>
                                <form method="post">
                                    <?php echo authCsrfField(); ?>
                                    <input type="hidden" name="action" value="toggle_enabled">
                                    <input type="hidden" name="user" value="<?php echo htmlspecialchars($u->AclUsUser); ?>">
                                    <button type="submit"><?php echo htmlspecialchars($enabled ? authText('ActionDisable') : authText('ActionEnable')); ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('<?php echo authJsConfirmAttr('ConfirmDeleteUser'); ?>')">
                                    <?php echo authCsrfField(); ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user" value="<?php echo htmlspecialchars($u->AclUsUser); ?>">
                                    <button type="submit" class="danger"><?php echo htmlspecialchars(authText('BtnDeleteUser')); ?></button>
                                </form>
                                <?php } ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Modal: create/edit user -->
    <div class="cp-modal-overlay" id="modal-edit" style="<?php echo $openModal === 'edit' ? 'display:flex' : ''; ?>" onclick="if(event.target===this) this.style.display='none'">
        <div class="cp-modal">
            <div class="cp-modal-head">
                <h2><?php echo htmlspecialchars($selectedUserRow ? authText('SectionEditUser') : authText('SectionCreateUser')); ?></h2>
                <button type="button" class="cp-modal-close" onclick="this.closest('.cp-modal-overlay').style.display='none'" aria-label="<?php echo htmlspecialchars(authText('Close')); ?>">×</button>
            </div>
            <form method="post">
                <?php echo authCsrfField(); ?>
                <input type="hidden" name="action" value="save_user">
                <table class="Tabella">
                    <tr><td><?php echo htmlspecialchars(authText('FieldUser')); ?></td><td><input name="user" maxlength="16" required <?php echo $selectedUserRow ? 'readonly' : ''; ?> value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser ?? ''); ?>"> <small><?php echo htmlspecialchars(authText('HintUsername')); ?></small></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldName')); ?></td><td><input name="name" maxlength="100" value="<?php echo htmlspecialchars($selectedUserRow->AclUsName ?? ''); ?>"></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldPassword')); ?></td><td><input type="password" name="password" autocomplete="new-password"> <small><?php echo htmlspecialchars(authText('HintKeepPassword')); ?></small></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldEnabled')); ?></td><td><input type="checkbox" name="enabled" <?php echo (!$selectedUserRow || intval($selectedUserRow->AclUsEnabled)) ? 'checked' : ''; ?>></td></tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldAdminRoot')); ?></td><td><input type="checkbox" name="admin" <?php echo ($selectedUserRow && intval($selectedUserRow->AclUsAuthAdmin)) ? 'checked' : ''; ?>> <small><?php echo htmlspecialchars(authText('HintRootBypass')); ?></small></td></tr>
                    <tr><td colspan="2" class="Center"><input type="submit" value="<?php echo htmlspecialchars(authText('BtnSaveUser')); ?>"></td></tr>
                </table>
            </form>
        </div>
    </div>

    <!-- Modal: rights (per-competition ACL) -->
    <div class="cp-modal-overlay" id="modal-rights" style="<?php echo $openModal === 'rights' ? 'display:flex' : ''; ?>" onclick="if(event.target===this) this.style.display='none'">
        <div class="cp-modal">
            <div class="cp-modal-head">
                <h2><?php echo htmlspecialchars(authText('ModalRightsTitle', array('user' => $selectedUserRow->AclUsUser ?? ''))); ?></h2>
                <button type="button" class="cp-modal-close" onclick="this.closest('.cp-modal-overlay').style.display='none'" aria-label="<?php echo htmlspecialchars(authText('Close')); ?>">×</button>
            </div>
            <?php if ($selectedUserRow) { ?>
            <table class="Tabella">
                <tr><th><?php echo htmlspecialchars(authText('ColCompetitionPattern')); ?></th><th><?php echo htmlspecialchars(authText('ColAccessSummary')); ?></th><th><?php echo htmlspecialchars(authText('ColActions')); ?></th></tr>
                <?php foreach ($selectedRules as $rule) { ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($rule['pattern']); ?></code></td>
                        <td><?php echo htmlspecialchars(authAccessLabel($rule['features'])); ?></td>
                        <td>
                            <a class="btn-xs-link" href="?user=<?php echo urlencode($selectedUserRow->AclUsUser); ?>&open=rights&pattern=<?php echo urlencode($rule['pattern']); ?>#acc-rule-form"><?php echo htmlspecialchars(authText('ActionEdit')); ?></a>
                            &nbsp;
                            <form method="post" style="display:inline" onsubmit="return confirm('<?php echo authJsConfirmAttr('ConfirmDeleteRule'); ?>')">
                                <?php echo authCsrfField(); ?>
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="user" value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser); ?>">
                                <input type="hidden" name="pattern" value="<?php echo htmlspecialchars($rule['pattern']); ?>">
                                <input type="submit" value="<?php echo htmlspecialchars(authText('BtnDelete')); ?>">
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$selectedRules) { ?>
                    <tr><td colspan="3" class="Center auth-muted"><?php echo htmlspecialchars(authText('NoRules')); ?></td></tr>
                <?php } ?>
            </table>

            <h3 id="acc-rule-form"><?php echo htmlspecialchars(authText('SectionAddUpdateAccess')); ?></h3>
            <form method="post">
                <?php echo authCsrfField(); ?>
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="user" value="<?php echo htmlspecialchars($selectedUserRow->AclUsUser); ?>">
                <table class="Tabella">
                    <tr>
                        <td><?php echo htmlspecialchars(authText('FieldCompetition')); ?></td>
                        <td>
                            <select name="tournament_code">
                                <option value=""><?php echo htmlspecialchars(authText('OptionManualPattern')); ?></option>
                                <?php foreach ($tournaments as $t) { ?>
                                    <option value="<?php echo htmlspecialchars($t->ToCode); ?>" <?php echo $editPattern === $t->ToCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($t->ToCode . ' — ' . $t->ToName); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr><td><?php echo htmlspecialchars(authText('FieldManualPattern')); ?></td><td><input name="pattern" placeholder="TOURCODE, FR%, FR*, *" maxlength="150" value="<?php echo htmlspecialchars($editPattern); ?>"> <small><?php echo authText('HintStarAllCompetitions', array('star' => '<code>*</code>')); ?></small></td></tr>
                </table>
                <table class="auth-permissions">
                    <tr><th><?php echo htmlspecialchars(authText('ColFeatureSubfeature')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColNoAccess')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColReadOnly')); ?></th><th class="Center"><?php echo htmlspecialchars(authText('ColReadWrite')); ?></th></tr>
                    <?php
                    global $listACL;
                    $knownSubFeatures = authKnownSubFeatures();
                    foreach ($listACL as $id => $rawLabel) {
                        $id = intval($id);
                        if ($id < AclRoot) continue;
                        $featureLevel = authRuleFeatureLevel($editParsedRule, $id);
                        $isRootFeature = $id === AclRoot;
                    ?>
                        <tr class="auth-feature-row<?php echo $isRootFeature ? ' auth-feature-row-root' : ''; ?>">
                            <td><?php echo htmlspecialchars(authFeatureLabel($id, $rawLabel)); ?></td>
                            <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="0" <?php echo $featureLevel === 0 ? 'checked' : ''; ?>></td>
                            <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="1" <?php echo $featureLevel === 1 ? 'checked' : ''; ?>></td>
                            <td class="Center"><input type="radio" name="perm[<?php echo $id; ?>]" value="2" <?php echo $featureLevel === 2 ? 'checked' : ''; ?>></td>
                        </tr>
                        <?php if ($isRootFeature) { ?>
                        <tr class="auth-feature-row-root">
                            <td colspan="4"><small class="auth-muted"><?php echo htmlspecialchars(authText('HintAclRootScope')); ?></small></td>
                        </tr>
                        <?php } ?>
                        <?php foreach (($knownSubFeatures[$id] ?? array()) as $subCode => $subLabel) {
                            $subLevel = authRuleSubLevel($editParsedRule, $id, $subCode);
                        ?>
                            <tr class="auth-subfeature">
                                <td>↳ <?php echo htmlspecialchars($subLabel); ?></td>
                                <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="0" <?php echo $subLevel === 0 ? 'checked' : ''; ?>></td>
                                <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="1" <?php echo $subLevel === 1 ? 'checked' : ''; ?>></td>
                                <td class="Center"><input type="radio" name="subperm[<?php echo $id; ?>][<?php echo htmlspecialchars($subCode); ?>]" value="2" <?php echo $subLevel === 2 ? 'checked' : ''; ?>></td>
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
<script>
function toggleKebab(btn) {
    var menu = btn.nextElementSibling;
    var wasOpen = menu.classList.contains('open');
    document.querySelectorAll('.kebab-menu.open').forEach(function (m) { m.classList.remove('open'); });
    if (!wasOpen) menu.classList.add('open');
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.kebab-wrap')) {
        document.querySelectorAll('.kebab-menu.open').forEach(function (m) { m.classList.remove('open'); });
    }
});
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
