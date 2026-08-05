# Compet+ Lightweight Authentication module for Ianseo

Original drop-in module for Ianseo's public `Modules/Authentication` hook points.

## Install

1. Copy `Modules/Authentication` into the Ianseo root.
2. In `config.php`, set:

```php
$CFG->USERAUTH=true;
```

3. Open Ianseo locally from the server machine and go to:

```text
Modules/Authentication/index.php
```

## Files

- `AuthFunctions.php`: session/login helpers, user table bootstrap, password hashing.
- `BlockFunction.php`: Ianseo ACL hook implementation.
- `LogIn.php`: login page (local form + optional "Se connecter avec Compet+" button).
- `LogOut.php`: logout action.
- `index.php`: minimal admin page for users and tournament/feature rules.
- `Account.php`: self-service page for the CURRENTLY logged-in user to link/unlink their Compet+
  identity — see "Compet+ federated login" below.
- `CompetplusOAuth.php`: OAuth2/OIDC client (Authorization Code + PKCE) talking to
  `auth.competplus.fr`.
- `CompetplusStart.php` / `CompetplusCallback.php`: entry/exit points of that flow.
- `menu.php`: adds Authentication/Account/Logout menu entries when possible.

## Data model

Uses the existing Ianseo tables:

- `AclUsers`
- `AclUserFeatures`

Plus one table owned entirely by this module (never added as columns on the native tables
above): `AclUserExternalAuth` (`Provider`, `AclUsUser`, `ExternalId`, `LinkedAt`) — links a local
account to an external identity provider. See "Compet+ federated login" below.

## Compet+ federated login ("Se connecter avec Compet+")

Optional, in addition to the local login form — never replaces it. Delegates authentication to
`auth.competplus.fr` (OAuth2/OIDC, Authorization Code + PKCE, confidential client) while ACL/rights
stay entirely in `AclUserFeatures` as today; Compet+ only ever supplies an identity (`sub` +
e-mail), never a role.

**This never creates an Ianseo account automatically.** `AclUsers` has no e-mail column, so a
first-time Compet+ sign-in is matched against an EXISTING local account through one of two paths,
never by creating one:

1. **Manual link** (always available): an already-authenticated local user visits `Account.php`
   and clicks "Link my Compet+ account", which completes an OAuth round-trip and records the
   pairing in `AclUserExternalAuth`.
2. **Automatic e-mail pairing** (best-effort, only when it applies): if the local account's login
   username (`AclUsUser`) IS itself formatted as an e-mail address — a common convention on some
   installs — and it matches the Compet+ account's e-mail (case-insensitive, exact match) **and**
   Compet+ reports that e-mail as verified (`email_verified`), the pairing is recorded
   automatically on first login, no manual step needed. `AclUsUser` is `VARCHAR(16)`, so this only
   ever matches short e-mail addresses — longer ones simply fall through to the manual link path,
   never an error. A local account that already has a *different* Compet+ identity linked is never
   silently re-paired this way (see `authFindUserByEmailAsUsername()` /
   `CompetplusCallback.php` for the exact guards).

Either way, from then on that person can log in via the "Se connecter avec Compet+" button on
`LogIn.php` — it looks up the Compet+ `sub` in `AclUserExternalAuth` and signs in directly if a
match exists, or (after also trying the automatic e-mail pairing above) sends them back to the
local login form with an explanatory message if nothing matches — never a silent/degraded
fallback.

### Enable it

1. Get a `client_id`/`client_secret` from a Compet+ admin (`POST /api/v1/auth/admin/oauth-clients`
   on `competplus-platform`) with `redirect_uri` set to
   `https://your-ianseo-domain/Modules/Authentication/CompetplusCallback.php` (exact match
   required, no dynamic query string).
2. In Ianseo's `config.php` (NOT part of this module — a per-deployment setting, exactly like
   `$CFG->USERAUTH` above), add:

```php
$CFG->USERAUTH = true;
$CFG->COMPETPLUS_AUTH = array(
    'client_id' => 'your-client-id',
    'client_secret' => 'the-secret-shown-once-at-creation',
    'auth_base_url' => 'https://auth.competplus.fr',
    'redirect_uri' => 'https://your-ianseo-domain/Modules/Authentication/CompetplusCallback.php',
);
```

The "Se connecter avec Compet+" button only appears once this block is present with a non-empty
`client_id`/`redirect_uri` — omit it (or leave a key empty) to keep the feature entirely hidden.

### Not yet implemented

No consent screen on the Compet+ side yet for third-party clients (see `competplus-platform`
docs) — acceptable while clients are manually vetted, to revisit before wider rollout. No admin
UI to link/unlink OTHER users' accounts (only self-service via `Account.php`) — an admin who needs
to do this today would need direct DB access to `AclUserExternalAuth`.

## Rule format

`AclUserFeatures.AclUFPattern` matches tournament codes:

- `TOUR2026`
- `FR%`
- `FR*`
- `*`

`AclUserFeatures.AclUFFeature` format:

```text
feature|subfeature|level#feature|subfeature|level
```

Examples:

```text
5||2       Qualification read/write
3||1       Participants read-only
5||2#3||1 Qualification read/write + Participants read-only
```

Levels:

- `0`: no access
- `1`: read-only
- `2`: read/write

An empty feature list gives tournament visibility with read-only fallback.

## Notes

This module is not an official Ianseo Authentication module. It only implements the hook functions already referenced by Ianseo core.

Recommended for LAN/private usage and experimentation first. Review security before internet exposure.


The module is distributed under the MIT License. Reuse, modification and redistribution are allowed, provided the copyright notice, license text and attribution notice are preserved. See `LICENSE` and `NOTICE`.
