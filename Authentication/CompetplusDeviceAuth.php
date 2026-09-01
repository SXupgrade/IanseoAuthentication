<?php
/**
 * "Se connecter avec Compet+" -- Device Authorization Grant (RFC 8628), login flow only (see
 * CompetplusOAuth.php's redirect-based Authorization Code + PKCE flow for 'link' mode, from
 * Account.php -- unaffected by this file). Reuses the exact same per-install
 * client_id/client_secret ($CFG->COMPETPLUS_AUTH, see authCompetplusConfig()): auth.competplus.fr
 * treats this confidential client identically for either grant, just a different grant_type at
 * the token endpoint -- no separate registration needed on the Compet+ side.
 *
 * Reuses authCompetplusHttpRequest()/authCompetplusFetchUserinfo() (CompetplusOAuth.php) for
 * transport and profile lookup; identity-to-local-account matching is
 * competplusResolveLoginUser() (also CompetplusOAuth.php), shared with the redirect flow.
 */

require_once(dirname(__FILE__) . '/CompetplusOAuth.php');

/**
 * RFC 8628 §3.1/3.2 -- starts the flow. Returns array('deviceCode','userCode','verificationUri',
 * 'verificationUriComplete','expiresIn','interval'). Throws CompetplusOAuthException on failure.
 */
function authCompetplusDeviceStart($config)
{
    $payload = array(
        'client_id' => $config['client_id'],
        'scope' => 'openid email profile',
    );

    list($status, $body) = authCompetplusHttpRequest('POST', $config['auth_base_url'] . '/api/v1/auth/device/code', json_encode($payload));

    if ($status !== 200 || empty($body['device_code']) || empty($body['user_code'])) {
        $detail = isset($body['error_description']) ? $body['error_description'] : (isset($body['error']) ? $body['error'] : ('HTTP ' . $status));
        throw new CompetplusOAuthException('Compet+ device authorization refused: ' . $detail);
    }

    return array(
        'deviceCode' => (string)$body['device_code'],
        'userCode' => (string)$body['user_code'],
        'verificationUri' => (string)($body['verification_uri'] ?? ''),
        'verificationUriComplete' => (string)($body['verification_uri_complete'] ?? ''),
        'expiresIn' => intval($body['expires_in'] ?? 600),
        'interval' => max(1, intval($body['interval'] ?? 5)),
    );
}

/**
 * RFC 8628 §3.4/3.5 -- a single poll (the caller is responsible for the interval between calls,
 * see CompetplusDeviceLogin.php's poll loop). Returns array('status' =>
 * 'pending'|'slow_down'|'approved'|'denied'|'expired', plus on 'approved': the same
 * 'sub'/'email'/'emailVerified'/'accessToken' shape authCompetplusCompleteFlow() returns, ready
 * for competplusResolveLoginUser()). Never throws for the RFC's own expected poll outcomes
 * (authorization_pending/slow_down/access_denied/expired_token) -- those are normal, not
 * failures; throws CompetplusOAuthException only for a genuinely unexpected response or network
 * failure.
 */
function authCompetplusDevicePoll($deviceCode, $config)
{
    $payload = array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $deviceCode,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
    );

    list($status, $body) = authCompetplusHttpRequest('POST', $config['auth_base_url'] . '/api/v1/auth/token', json_encode($payload));

    if ($status === 200 && !empty($body['access_token'])) {
        $profile = authCompetplusFetchUserinfo((string)$body['access_token'], $config);
        return array(
            'status' => 'approved',
            'sub' => $profile['sub'],
            'email' => $profile['email'],
            'emailVerified' => $profile['emailVerified'],
            'accessToken' => (string)$body['access_token'],
        );
    }

    $errorCode = isset($body['error']) ? (string)$body['error'] : '';
    switch ($errorCode) {
        case 'authorization_pending':
            return array('status' => 'pending');
        case 'slow_down':
            return array('status' => 'slow_down');
        case 'access_denied':
            return array('status' => 'denied');
        case 'expired_token':
            return array('status' => 'expired');
    }

    $detail = isset($body['error_description']) ? $body['error_description'] : ('HTTP ' . $status);
    throw new CompetplusOAuthException('Compet+ device token poll failed: ' . $detail);
}
