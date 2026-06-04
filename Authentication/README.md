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

Localhost is allowed to bootstrap the first admin user.

## Files

- `AuthFunctions.php`: session/login helpers, user table bootstrap, password hashing.
- `BlockFunction.php`: Ianseo ACL hook implementation.
- `LogIn.php`: login page.
- `LogOut.php`: logout action.
- `index.php`: minimal admin page for users and tournament/feature rules.
- `menu.php`: adds Authentication/Logout menu entries when possible.

## Data model

Uses the existing Ianseo 2024 tables:

- `AclUsers`
- `AclUserFeatures`

Passwords are stored with `password_hash()`. Legacy SHA-256 hashes created by Ianseo migrations are accepted and upgraded after successful login.

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

This module intentionally does not copy or depend on Ianseo's commercial Authentication module. It only implements the hook functions already referenced by Ianseo core.

Recommended for LAN/private usage and experimentation first. Review security before internet exposure.

## Compet+ compatibility note — v0.1.1

This build intentionally stores passwords as SHA-256 hashes (`64` characters) to remain compatible with the native Ianseo `AclUsers.AclUsPwd VARCHAR(64)` schema.

It still accepts existing `password_hash()` values if they are already present in a custom-expanded database, but it never auto-migrates passwords to that format. This avoids requiring any database schema change.

Initial admin example:

```sql
INSERT INTO AclUsers (AclUsUser, AclUsName, AclUsPwd, AclUsEnabled, AclUsAuthAdmin)
VALUES ('admin', 'Administrator', SHA2('CHANGE_ME_NOW', 256), 1, 1)
ON DUPLICATE KEY UPDATE
  AclUsName = VALUES(AclUsName),
  AclUsPwd = VALUES(AclUsPwd),
  AclUsEnabled = VALUES(AclUsEnabled),
  AclUsAuthAdmin = VALUES(AclUsAuthAdmin);
```

## v0.1.2 notes

- Keeps users logged in when Ianseo opens a competition and rebuilds the PHP session.
- Rehydrates `AUTH_ENABLE`, `AUTH_ROOT`, and `AUTH_COMP` from `AclUsers` / `AclUserFeatures` when only `AUTH_User` remains in the session.
- Replaces the minimal raw ACL editor with a user-friendly administration page:
  - user list and selected user editor;
  - tournament dropdown or manual pattern (`TOURCODE`, `FR%`, `FR*`, `*`);
  - permission matrix with no access / read-only / read-write levels.


## v0.1.2-authguard-v4

- Makes the global bootstrap non-blocking to avoid redirect loops on authenticated pages.
- Keeps session rehydration after Ianseo opens/closes a tournament.
- Redirects anonymous users from the main tournament list through the Ianseo auth hook only.


## Branding and license — Compet+

This build adds a Compet+ branded login and administration experience with a visible note:

```text
Module made available by Compet+
https://competplus.fr
```

The module is distributed under the MIT License. Reuse, modification and redistribution are allowed, provided the copyright notice, license text and attribution notice are preserved. See `LICENSE` and `NOTICE`.
