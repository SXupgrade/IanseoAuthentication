# Compet+ Lightweight Authentication module for Ianseo

Drop-in module for Ianseo's public `Modules/Authentication` hook points,
deployed through `Modules/Custom/` — the one directory Ianseo's own update
process never touches — instead of directly into `Modules/Authentication/`,
which an Ianseo update can (and does) wipe out entirely.

## Why `Modules/Custom/` and not `Modules/Authentication/` directly

Ianseo hard-codes a few paths under `Modules/Authentication/` in its own
core (`Common/BlockDefines.php`'s `require_once('Modules/Authentication/
BlockFunction.php')`, `config.php`'s `AuthFunctions.php` include) — it has
no notion of `Modules/Custom/`-relative auth hooks. So that exact path has
to exist on disk, but the directory itself isn't part of Ianseo's own
versioned/preserved content (see the Ianseo checkout's `.gitignore`:
`/Modules/Authentication`), so a code update that replaces Ianseo's files
wipes it.

The real module code lives in `Modules/Custom/Authentication/` instead —
preserved across updates by Ianseo's own design (see `Modules/Custom/
README.TXT` in the Ianseo checkout: "left untouched by the update
process"). `Modules/Authentication/` becomes a disposable set of tiny
forwarder files that just `require` the real code from `Modules/Custom/
Authentication/`, regenerated automatically by `Bootstrap.php` whenever
it's missing — no manual step needed after an update.

## Install

1. Copy `Custom/Authentication` into the Ianseo root's `Modules/Custom/`
   (so the real files live at `Modules/Custom/Authentication/`).
2. Open Ianseo locally from the server machine and go to:

```text
Modules/Authentication/index.php
```

   (This works even with `USERAUTH` still off — `menu.php` self-heals
   `Modules/Authentication/` unconditionally, so the first-admin setup flow
   and this admin screen are both reachable before authentication is ever
   turned on.)
3. Create the first administrator, then click **Enable authentication** at
   the top of the admin screen.

That last click is the only thing that actually turns the module on —
there is no `config.php` line to edit by hand. It flips `$CFG->USERAUTH`
in Ianseo's root `config.php` itself (`ConfigWriter.php`), and — the first
time it's switched on — also installs a small safety-net block right there
in `config.php`: it makes sure `Modules/Authentication/BlockFunction.php`
exists *before* `Common/BlockDefines.php`'s hard `require_once` on it
runs, on every request. Without it, an Ianseo update that wipes
`Modules/Authentication/` while `USERAUTH` stays on would fatal-error
every request before `menu.php` (the module's other, normal self-heal
path, described below) is ever reached. `Ianseo`'s own repo is never
touched for this — the module owns that block, in the live `config.php`,
not in source control.

That write is deliberately conservative: it requires an exact, single
match on the `$CFG->USERAUTH` line before touching anything, takes a
timestamped backup of `config.php` next to it before every write, and
writes through a temp file + atomic rename rather than editing in place —
see `ConfigWriter.php` for the details. If the web server can't write to
`config.php` (common on some hosts), the toggle reports why and does
nothing further; in that case set `$CFG->USERAUTH=true;` by hand instead
and use the toggle later once permissions allow it (the safety-net block
only ever gets installed through a successful toggle, so re-running it
once permissions are fixed is what actually adds it).

Nothing under `Modules/Authentication/` should ever be hand-edited — it's
regenerated on demand and any manual changes are silently overwritten the
next time `Bootstrap.php` runs. Edit the files in `Modules/Custom/
Authentication/` instead.

## Files

All in `Custom/Authentication/` (deployed to Ianseo's `Modules/Custom/
Authentication/`):

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
- `menu.php`: adds Authentication/Account/Logout menu entries when possible, and self-heals
  `Modules/Authentication/` on every menu build (Ianseo auto-includes any `Modules/Custom/*/
  menu.php`, which is what makes this the natural self-heal hook — see `Bootstrap.php`).
- `Bootstrap.php`: regenerates the forwarder files Ianseo expects at `Modules/Authentication/`.
  Bump `$shimVersion` in here if you ever change what those forwarders contain, or add/remove a
  module file, so existing installs pick up the change on their next request instead of keeping a
  stale shim forever.
- `ConfigWriter.php`: toggles `$CFG->USERAUTH` in Ianseo's root `config.php` from the admin
  screen's "Enable/Disable authentication" button, and installs the safety-net block described
  under "Install" above the first time it's switched on.
- `Updater.php`: checks GitHub for a newer version and applies it — see "Versioning and
  self-update" below.
- `VERSION`: the version currently installed (plain semver text, e.g. `1.1.0`) — compared against
  GitHub tags by `Updater.php`. Gets overwritten by every update, same as everything else in this
  folder.
- `Languages/<code>.php`: this module's own translation strings — see "Language" below.

## Versioning and self-update

Releases are plain git tags on this repo (`vX.Y.Z`, e.g. `v1.1.0`) — no separate "publish a
release" step needed, `git tag vX.Y.Z && git push origin vX.Y.Z` is the whole release process.
`VERSION` inside `Custom/Authentication/` tracks which one is currently installed.

The admin screen (`index.php`) checks the highest `vX.Y.Z` tag on
`github.com/SXupgrade/IanseoAuthentication` against the installed `VERSION` — cached for 6 hours
(`.update-check-cache.json`, next to `VERSION`, never committed) so a normal page load essentially
never actually calls GitHub; "Check for updates" forces a fresh check on demand. This runs
automatically on every visit to the admin screen (read-only, no changes without a click), but
**only an account with `$canAdmin` (a `AUTH_ROOT` session, or physically on the server machine
during initial setup — the same gate every other sensitive action on this screen already uses) can
click "Update now".**

Clicking it:

1. Downloads that tag's source as a zip directly from GitHub (`zipball_url` from the same tags
   API call, no separate lookup).
2. Extracts it to a temp directory and builds the new `Modules/Custom/Authentication/` fully in a
   fresh staging directory next to the live one — the live install isn't touched at all while this
   runs, so a failure here (disk full, permissions, a bad download) leaves it exactly as it was.
3. Only once that staging copy fully succeeds: two fast `rename()` calls swap the live directory
   out to a timestamped backup (`Modules/Custom/Authentication-backup-<timestamp>/`, kept
   indefinitely — delete old ones by hand whenever) and the staged one in. If the second rename
   fails, the first is rolled back automatically — the live install is never left half-updated.

The repo to check (`SXupgrade/IanseoAuthentication`) is a hardcoded constant in `Updater.php`, not
read from `$CFG` or any request input, so there's no way to point this at anything other than the
real module repo. Same trust model as Ianseo's own self-updater
(`Update/UpdateIanseo.php`, downloading from `ianseo.net`): HTTPS to a fixed, known-good host, no
extra signature layer on top.

Needs the PHP `zip` extension (`ZipArchive`) — the update button reports clearly if it's missing
instead of silently doing nothing; install the new `Custom/Authentication/` by hand in that case,
same as step 1 of "Install" above.

**An install running a version from *before* this feature existed can't discover or apply updates
on its own** — it has no `VERSION` file or `Updater.php` yet, so there's nothing to click. That one
jump has to be manual (replace `Modules/Custom/Authentication/` with a fresh checkout, same as a
first install); every version from here on can update itself from then on.

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
2. Enable authentication from the admin screen (see "Install" above) if you haven't already, then
   add the OAuth credentials to Ianseo's `config.php` by hand — these are Compet+ client
   credentials, not something this module's own toggle manages:

```php
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

## Admin screen (`index.php`)

A table (login / name / status / admin) lists every account, each row with a "⋯" menu:
**Edit** (identity/password/enabled/admin — modal), **Rights** (per-competition ACL — a separate
modal), a one-click **Enable/Disable** toggle, and **Delete**. Both modals stay fully server-
rendered (no AJAX/JSON layer added to the module): clicking a menu entry navigates to
`?user=<login>&open=edit` or `&open=rights`, and the corresponding modal opens on load — kept
this way on purpose, consistent with the rest of the module (plain POST forms, full-page reload,
no client-side state to keep in sync). Only the "⋯" dropdown itself needs a few lines of vanilla
JS to open/close.

The rights modal shows human-readable feature names (`authText('Feature_AclXxx')` in
`authFeatureLabel()`) instead of Ianseo's raw internal codes (`AclCompetition`, `AclISKServer`...)
that `$listACL` exposes as-is — mapped by the feature's numeric id (a stable core `define()`),
with a fallback to the raw code for any id a future Ianseo core version might add that this module
doesn't know about yet. Editing an existing rule (via the "Edit" link next to it) now pre-fills
the permission radios from its current stored value — previously the same form was used for both
creating and editing, but always started from "no access" on every field, making an edit
indistinguishable from starting over.

The currently logged-in admin's own row never offers Disable/Delete (only Edit/Rights) — this
module has no separate account-recovery path, so locking yourself out from this screen would mean
editing the database directly to get back in.

### Delegating competition creation/deletion by naming pattern (`AclRoot`)

Ianseo core gates competition creation (`Tournament/index.php` save path), deletion
(`Tournament/TourDelete.php`), lock/unlock (`Tournament/BlockTour.php`), import
(`Tournament/TournamentImport.php`) and its own native (IP-based) per-tournament ACL editor
(`Tournament/AccessControlList/*`) behind `checkFullACL(AclRoot, ...)` / `possibleFeature(AclRoot,
...)` — feature id `0`. These calls are **pattern-aware by design**: Ianseo core itself resolves
them against the requester's `AclUserFeatures` rule for the specific competition code involved
(e.g. a rule for pattern `13092*`), the exact same mechanism as every other feature in the rights
editor above — this was always how Ianseo intended to support delegating a subset of competitions
by naming convention, without making someone a true root/admin.

The rights editor now includes `AclRoot` (labelled "Full administration (this pattern)") as a
grantable row, highlighted and with an inline warning: it is **not** a narrow "create/delete"
permission — granting it for a pattern hands over full administration (create, delete, lock/
unlock, import, native ACL) for every competition matching that pattern, nothing narrower. Prior
to this, three independent places in this module explicitly rejected feature id `0`
(`authParseFeatureRules()`, `authBuildFeatureStringFromPost()`, and the rights editor's own
render loop) — a deliberate defense-in-depth choice at the time, since granting `AclRoot` through
this module hadn't been vetted for its actual (broader-than-"competition management") scope yet.

**Not affected by a pattern-scoped `AclRoot` grant** — these hard-check
`empty($_SESSION['AUTH_ROOT'])` directly, bypassing this module's pattern-aware hooks entirely,
and stay strictly root-only regardless: `Update/index.php` (the "all competitions" hub),
`Update/index-action.php` (its AJAX actions), `Update/ExportAllCompetitions.php` (bulk export).
Changing that would mean patching Ianseo core itself, re-applied on every core update — out of
scope for this module.

Because Ianseo's own menu only offers "New competition" when it can already check a specific
tournament code against the requester's rules (impossible before one exists — see
`Common/Menu.php`'s `possibleFeature(AclRoot, AclReadWrite)` call with no code), a user delegated
`AclRoot` on a pattern wouldn't otherwise see any link to create one. `menu.php` adds a "Create a
competition" entry (`authUserHasAnyRootGrant()`) pointing straight at Ianseo's own
`Tournament/index.php?New=` for exactly this case — a discoverability aid only, not a new access
path: the same pattern check Ianseo core already does at save time applies regardless of how that
page was reached.

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
