# Cache → Array Share Control for Unraid

An Unraid 6.12+ plugin that changes only selected user shares to:

- **Primary storage:** a chosen pool (normally `cache`)
- **Secondary storage:** Array
- **Mover action:** Pool → Array

The plugin uses Unraid's native `/update.htm` share-update interface, creates a
timestamped backup before every batch, verifies every applied change, and can
restore the most recent un-restored batch.

It does **not** start Mover. Existing files stay where they are until Unraid's
scheduled Mover runs (or the user starts Mover manually).

Copyright © 2026 Ray Munro. Licensed under the [MIT License](LICENSE).

## Install

**Via Community Applications:** search for "Cache -> Array Share Control"
in the Apps tab and click Install.

**Manually:** in the Unraid webGUI go to **Plugins → Install Plugin** and
paste:

```
https://raw.githubusercontent.com/RayMunro/unraid-cache-array-share/main/cache-array-share.plg
```

Then open **Settings → User Utilities → Cache → Array Share Control**.
The package is embedded directly in the `.plg`, so nothing else needs to
be downloaded or copied by hand.

## Build and test

```bash
./build.sh
php tests/run.php
```

## Safety

- Nothing is selected by default.
- Only explicitly selected shares are changed.
- All unrelated share settings are preserved.
- A backup and manifest are created before the first change.
- Completed changes are rolled back if a later share fails.
- `appdata`, `domains`, and `system` are visibly marked as service-sensitive.
- The plugin never runs Mover automatically.

Author: Ray Munro  
License: MIT
