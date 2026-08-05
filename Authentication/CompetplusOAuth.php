<?php
/**
 * "Se connecter avec Compet+" -- OAuth2/OIDC (Authorization Code + PKCE, confidential client)
 * against auth.competplus.fr. Plain functions, no namespace/class, consistent with the rest of
 * this module. Only talks to Compet+ and returns an identity (sub + email); linking that
 * identity to a local AclUsers account is handled by authLinkExternalIdentity() /
 * authFindUserByExternalId() in AuthFunctions.php -- this file never touches AclUsers directly.
 *
 * Entirely server-side (cURL, with a file_get_contents fallback): no OAuth client library is
 * used, matching the rest of this codebase (e.g. Modules/UpdateWeb), and no CORS concern since
 * the browser only ever navigates (GET /authorize, GET callback), never fetch()es Compet+ itself.
 */

require_once(dirname(__FILE__) . '/AuthFunctions.php');

define('COMPETPLUS_OAUTH_TIMEOUT', 10);
define('COMPETPLUS_OAUTH_SESSION_KEY', 'COMPETPLUS_OAUTH');
define('COMPETPLUS_OAUTH_STATE_TTL', 600);

class CompetplusOAuthException extends Exception
{
}

function competplusBase64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Same "local relative URL only, never an authentication page" rule as LogIn.php's own
// authLoginReturnUrl() -- duplicated rather than shared across files/modules on purpose, this is
// the only other place that needs it.
function authCompetplusSanitizeReturnUrl($raw)
{
    $raw = (string)$raw;
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) || substr($raw, 0, 2) === '//') {
        return '';
    }
    if (stripos($raw, '/Modules/Authentication/') !== false) {
        return '';
    }
    return $raw;
}

/**
 * Builds the /authorize URL and stashes state/PKCE (+ mode/return) in session for the callback.
 * $mode is 'login' (default, unauthenticated visitor trying to sign in) or 'link' (an already
 * logged-in local user linking their account, see Account.php) -- carried through session, never
 * trusted from a query-string parameter at callback time.
 */
function authCompetplusAuthorizeUrl($mode = 'login', $linkUsername = '', $returnUrl = '')
{
    $config = authCompetplusConfig();
    if ($config === null) {
        throw new CompetplusOAuthException('Compet+ login is not configured (see README.md).');
    }

    $verifier = competplusBase64UrlEncode(random_bytes(32));
    $state = bin2hex(random_bytes(32));

    $_SESSION[COMPETPLUS_OAUTH_SESSION_KEY] = array(
        'state' => $state,
        'code_verifier' => $verifier,
        'mode' => $mode === 'link' ? 'link' : 'login',
        'link_username' => $mode === 'link' ? authNormalizeUsername($linkUsername) : '',
        'return' => $mode === 'login' ? authCompetplusSanitizeReturnUrl($returnUrl) : '',
        'expire' => time() + COMPETPLUS_OAUTH_STATE_TTL,
    );

    $challenge = competplusBase64UrlEncode(hash('sha256', $verifier, true));

    $params = array(
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    );

    return $config['auth_base_url'] . '/authorize?' . http_build_query($params);
}

/**
 * Validates the callback (state + expiry), exchanges the code, fetches the profile.
 * Returns array('sub' => ..., 'email' => ..., 'mode' => 'login'|'link', 'linkUsername' => ...).
 * Throws CompetplusOAuthException on any failure -- callers must never fall back to a degraded
 * "logged in anyway" path on error.
 */
function authCompetplusCompleteFlow($code, $state)
{
    $pending = isset($_SESSION[COMPETPLUS_OAUTH_SESSION_KEY]) ? $_SESSION[COMPETPLUS_OAUTH_SESSION_KEY] : null;
    unset($_SESSION[COMPETPLUS_OAUTH_SESSION_KEY]);

    if (!is_array($pending) || $code === '' || $state === '') {
        throw new CompetplusOAuthException('Invalid or expired Compet+ login request.');
    }
    if (!hash_equals((string)$pending['state'], (string)$state)) {
        throw new CompetplusOAuthException('Invalid state parameter (possible CSRF).');
    }
    if (intval($pending['expire']) < time()) {
        throw new CompetplusOAuthException('Compet+ login request expired, please try again.');
    }

    $config = authCompetplusConfig();
    if ($config === null) {
        throw new CompetplusOAuthException('Compet+ login is not configured (see README.md).');
    }

    $token = authCompetplusExchangeCode($code, (string)$pending['code_verifier'], $config);
    $profile = authCompetplusFetchUserinfo((string)$token['access_token'], $config);

    return array(
        'sub' => $profile['sub'],
        'email' => $profile['email'],
        'emailVerified' => $profile['emailVerified'],
        'mode' => $pending['mode'] === 'link' ? 'link' : 'login',
        'linkUsername' => (string)$pending['link_username'],
        'return' => (string)($pending['return'] ?? ''),
    );
}

function authCompetplusExchangeCode($code, $codeVerifier, $config)
{
    $payload = array(
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code_verifier' => $codeVerifier,
    );

    list($status, $body) = authCompetplusHttpRequest('POST', $config['auth_base_url'] . '/api/v1/auth/token', json_encode($payload));

    if ($status !== 200 || empty($body['access_token'])) {
        $detail = isset($body['error_description']) ? $body['error_description'] : (isset($body['error']) ? $body['error'] : ('HTTP ' . $status));
        throw new CompetplusOAuthException('Compet+ code exchange refused: ' . $detail);
    }

    return $body;
}

function authCompetplusFetchUserinfo($accessToken, $config)
{
    list($status, $body) = authCompetplusHttpRequest(
        'GET',
        $config['auth_base_url'] . '/api/v1/auth/userinfo',
        null,
        array('Authorization: Bearer ' . $accessToken)
    );

    if ($status !== 200 || empty($body['sub']) || empty($body['email'])) {
        throw new CompetplusOAuthException('Invalid or unreachable Compet+ profile (HTTP ' . $status . ').');
    }

    return array(
        'sub' => (string)$body['sub'],
        'email' => (string)$body['email'],
        'emailVerified' => !empty($body['email_verified']),
    );
}

/**
 * cURL first, file_get_contents/stream_context fallback if the extension is unavailable on this
 * host -- this codebase does not otherwise assume cURL is present (see Modules/UpdateWeb for the
 * same pattern).
 *
 * @return array{0:int,1:array} [httpStatus, decoded JSON body]
 */
function authCompetplusHttpRequest($method, $url, $jsonBody = null, $headers = array())
{
    $headers[] = 'Accept: application/json';
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => COMPETPLUS_OAUTH_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => $method,
        );
        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        if ($response === false) {
            throw new CompetplusOAuthException('Network call to Compet+ failed: ' . $curlError);
        }
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $jsonBody === null ? '' : $jsonBody,
                'timeout' => COMPETPLUS_OAUTH_TIMEOUT,
                'ignore_errors' => true,
            ),
        ));
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new CompetplusOAuthException('Network call to Compet+ failed (no cURL extension and file_get_contents failed).');
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                    $status = intval($m[1]);
                }
            }
        }
    }

    $decoded = json_decode((string)$response, true);
    return array($status, is_array($decoded) ? $decoded : array());
}
