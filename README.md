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
- `LogIn.php`: login page.
- `LogOut.php`: logout action.
- `index.php`: minimal admin page for users and tournament/feature rules.
- `menu.php`: adds Authentication/Logout menu entries when possible.

## Data model

Uses the existing Ianseo tables:

- `AclUsers`
- `AclUserFeatures`

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
