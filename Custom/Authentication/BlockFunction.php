<?php
/**
 * Ianseo ACL hook implementation for Compet+ lightweight Authentication module.
 */

require_once(dirname(__FILE__) . '/AuthFunctions.php');

function isAuthEnabled($ToCode = '')
{
    global $CFG;
    if (empty($CFG->USERAUTH)) {
        return array(0, 1);
    }

    // Ianseo can reset most authentication session keys when opening or closing a tournament.
    // Rebuild them from the stored authenticated user before any page computes ACL filters.
    if (function_exists('authRefreshSessionFromStoredUser')) {
        authRefreshSessionFromStoredUser();
    }

    // Make the auth hook authoritative: pages that call isAuthEnabled() must not continue
    // anonymously when USERAUTH is enabled, except for whitelisted public auth endpoints.
    if (function_exists('authRequireLoginForRequest')) {
        authRequireLoginForRequest(true);
    }

    // [auth enabled, also check competition/IP ACL]
    return array(1, 1);
}

function authActualACL($authEnabled, &$acl)
{
    if (empty($authEnabled) || empty($_SESSION['AUTH_ENABLE'])) {
        return;
    }
    if (!empty($_SESSION['AUTH_ROOT'])) {
        foreach (array_keys($acl) as $feature) {
            $acl[$feature] = AclReadWrite;
        }
        return;
    }
    $toCode = '';
    if (!empty($_SESSION['TourId'])) {
        $toCode = getCodeFromId(intval($_SESSION['TourId']));
    }
    foreach (authCurrentUserRulesForTournament($toCode) as $rule) {
        authMergeAcl($acl, authParseFeatureRules($rule['features']));
    }
}

function authHasACL($authEnabled, $feature, $level, $toCode)
{
    if (empty($authEnabled) || empty($_SESSION['AUTH_ENABLE'])) {
        return null;
    }
    if (!empty($_SESSION['AUTH_ROOT'])) {
        return AclReadWrite;
    }
    return authCheckACL($authEnabled, true, array($feature), '', $level, $toCode);
}

function authCheckACL($authEnabled, $checkCompAcl, $feature, $subFeature, $level, $toCode)
{
    if (empty($authEnabled)) {
        return null;
    }
    if (empty($_SESSION['AUTH_ENABLE']) || empty($_SESSION['AUTH_User'])) {
        return false;
    }
    if (!empty($_SESSION['AUTH_ROOT'])) {
        return AclReadWrite;
    }

    if (!is_array($feature)) {
        $feature = array($feature);
    }

    $bestLevel = AclNoAccess;
    $hasMatchedTournament = false;
    foreach (authCurrentUserRulesForTournament($toCode) as $rule) {
        $hasMatchedTournament = true;
        $parsed = authParseFeatureRules($rule['features']);

        // Empty feature list means tournament visibility only: read-only fallback.
        if (trim($rule['features']) === '') {
            $bestLevel = max($bestLevel, AclReadOnly);
            continue;
        }

        foreach ($feature as $requestedFeature) {
            $requestedFeature = intval($requestedFeature);
            if (!isset($parsed[$requestedFeature])) {
                continue;
            }
            $candidate = authResolveFeatureLevel($parsed[$requestedFeature], $subFeature);
            $bestLevel = max($bestLevel, $candidate);
        }
    }

    if (!$hasMatchedTournament) {
        return false;
    }
    if ($bestLevel >= $level || $level == AclNoAccess) {
        return $bestLevel;
    }
    return false;
}

function subFeatureAcl($acl, $feature, $subfeature = '')
{
    if (!empty($_SESSION['AUTH_ROOT'])) {
        return AclReadWrite;
    }

    $fallback = array_key_exists($feature, $acl) ? intval($acl[$feature]) : AclNoAccess;

    // Ianseo menus often call subFeatureAcl($acl, Feature, 'specificAction').
    // The stock fallback ignores the subfeature. When authentication is enabled, resolve the
    // explicit subfeature rule first, then fall back to the feature-level ACL.
    if (!empty($_SESSION['AUTH_ENABLE']) && !empty($_SESSION['AUTH_User'])) {
        $toCode = !empty($_SESSION['TourId']) ? getCodeFromId(intval($_SESSION['TourId'])) : '';
        $bestLevel = null;
        $hasMatchedTournament = false;

        foreach (authCurrentUserRulesForTournament($toCode) as $rule) {
            $hasMatchedTournament = true;
            $parsed = authParseFeatureRules($rule['features']);
            if (!isset($parsed[intval($feature)])) {
                continue;
            }
            $candidate = authResolveFeatureLevel($parsed[intval($feature)], $subfeature);
            $bestLevel = is_null($bestLevel) ? $candidate : max($bestLevel, $candidate);
        }

        if ($hasMatchedTournament && !is_null($bestLevel)) {
            return intval($bestLevel);
        }
    }

    return $fallback;
}

function possibleFeature($feature, $level, $toCode = null)
{
    if (!empty($_SESSION['AUTH_ROOT'])) {
        return true;
    }
    if (empty($_SESSION['AUTH_ENABLE'])) {
        return false;
    }
    if ($toCode === null) {
        $toCode = !empty($_SESSION['TourId']) ? getCodeFromId(intval($_SESSION['TourId'])) : '';
    }
    $result = authCheckACL(1, true, array($feature), '', $level, $toCode);
    return ($result !== false && $result !== null && $result >= $level);
}
