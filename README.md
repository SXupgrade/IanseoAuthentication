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
- `Languages/<code>.php`: this module's own translation strings — see "Language" below.

## Language

Every user-facing string in this module goes through `authText($key, $vars = null)`
(`AuthFunctions.php`), which:

- **Detects the language** the same way the rest of Ianseo does — Ianseo core's own
  `SelectLanguage()` (`Common/Globals.inc.php`): `?Lang=xx` query param, then the `UseLanguage`
  cookie (set via `?SetLanguage=xx`, handled automatically by Ianseo core on any page), then the
  browser's `Accept-Language` header, defaulting to English. A visitor who already picked a
  language elsewhere in Ianseo gets this module in the same language automatically, no separate
  choice needed.
- **Loads strings from this module's own `Languages/<code>.php` files** (`en.php`, `fr.php`), NOT
  Ianseo core's `Common/Languages/` tree that `get_text()` normally uses — on purpose, so the
  module stays a single self-contained drop-in folder (step 1 of Install above) with nothing to
  copy into Ianseo core at install time.
- **Falls back to English** for any key missing from a non-English file (never a broken/empty
  string), and to the raw key itself if even the English file is missing it (should never happen
  in practice — `en.php` and `fr.php` are kept in sync, same key set).

A small language switcher (`authLanguageSwitcherHtml()`) appears on this module's own pages
(`LogIn.php`, `Account.php`, `index.php`) — reuses Ianseo's own `?SetLanguage=` mechanism, so it
works with zero extra wiring and preserves the rest of the current query string (e.g.
`LogIn.php?return=...`).

**Adding a language**: copy `Languages/en.php` to `Languages/<code>.php` (ISO code Ianseo already
recognizes, e.g. `de`, `es`, `it`) and translate each value — the switcher and `authText()` pick
it up automatically, no other file to touch. Keep the same key set as `en.php` (a missing key
falls back to English rather than breaking, but a full translation is obviously better UX).

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
first-time Compet+ sign-in is matched against an EXISTING local account through one of three
paths, never by creating one:

1. **Manual link** (always available): an already-authenticated local user visits `Account.php`
   and clicks "Link my Compet+ account", which completes an OAuth round-trip and records the
   pairing in `AclUserExternalAuth`.
2. **Automatic e-mail pairing** (best-effort, only when it applies): if the local account's login
   username (`AclUsUser`) IS itself formatted as an e-mail address — a common convention on some
   installs — and it matches the Compet+ account's e-mail (case-insensitive, exact match) **and**
   Compet+ reports that e-mail as verified (`email_verified`), the pairing is recorded
   automatically on first login, no manual step needed. `AclUsUser` is `VARCHAR(16)`, so this only
   ever matches short e-mail addresses — longer ones simply fall through, never an error.
3. **Automatic FFTA-licence pairing** (best-effort, tried only if #2 didn't match): if `AclUsUser`
   holds the archer's FFTA licence number instead — arguably a better fit than e-mail here, an FFTA
   licence (7 digits + 1 letter, 8 chars) comfortably fits `VARCHAR(16)` where most e-mails don't —
   it's matched (case-insensitive, exact) against the licence number on the Compet+ account's
   **archer profile**. That profile is NOT part of `auth`'s identity (`sub`/e-mail only): it's
   fetched with a second Bearer-authenticated call to `cloud.competplus.fr` (same access token —
   opaque Compet+ session tokens are valid across all Compet+ apps, not just `auth`), silently
   skipped if the archer has no cloud profile yet or `cloud` is briefly unreachable — never blocks
   the rest of the flow.

Neither automatic path ever re-pairs a local account that already has a *different* Compet+
identity linked (see `competplusTryPairCandidate()` in `CompetplusCallback.php` for the shared
guard) — that case simply falls through to the "no account linked" message below, same as any
other non-match.

Either way, from then on that person can log in via the "Se connecter avec Compet+" button on
`LogIn.php` — it looks up the Compet+ `sub` in `AclUserExternalAuth` and signs in directly if a
match exists, or (after also trying the two automatic pairing fallbacks above) sends them back to
the local login form with an explanatory message if nothing matches — never a silent/degraded
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
    'cloud_base_url' => 'https://cloud.competplus.fr', // optional, used only for FFTA-licence pairing (#3 above)
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
