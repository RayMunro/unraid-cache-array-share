# Cache / Array Share Control for Unraid

An Unraid 6.12+ plugin that gives every user share three explicit, mutually exclusive tickboxes:

- **Cache → Array:** Primary storage is the chosen pool, Secondary storage is Array.
- **Cache only:** Primary storage is the chosen pool, Secondary storage is None.
- **Array only:** Mover runs first; after verification, Primary storage is Array and Secondary storage is None.

The plugin uses Unraid's native `/update.htm` share-update interface, creates a
timestamped backup before every batch, verifies every applied change, and can
restore the most recent un-restored batch.

For an **Array-only** selection, the plugin:

1. Creates a backup of the original share policies.
2. Temporarily sets those shares to Pool → Array.
3. Starts Unraid's global Mover and waits for it to finish in a background job.
4. Checks every configured, mounted pool for files belonging to the selected shares.
5. If **Force remaining** is ticked for a share, copies its leftovers directly
   from the pool to `/mnt/user0`, removing each source file only after a
   successful copy.
6. Verifies the selected pool locations again and applies Array-only only when
   they are clear.

In a mixed operation, shares selected as **Cache only** are not included in the
post-Mover remaining-files check. Their Cache-only policy is applied after the
Array-only shares pass that check. Cache only does not move existing Array files
onto the pool; it directs new writes to the chosen pool.

If files remain, Array-only is not applied and the safe Pool → Array policy is
left in place. The automatic Restore button is also disabled for that failed
safety operation so it cannot reintroduce the unsafe policy. This can happen
when Docker containers or VMs keep files open.
Because Unraid Mover is global, other shares can also be moved according to
their own policies during this operation.

Force remaining is a separate per-share choice and is disabled until Array
only is selected for that row. Stop Docker and VMs before forcing files so an
application cannot modify a file while it is being transferred.

The **Force all selected Array-only** button ticks Force remaining for every row
that is currently selected as Array only, avoiding the need to tick each one
manually. It does not select any additional shares.

## Install

**Via Community Applications:** search for "Cache / Array Share Control"
in the Apps tab and click Install.

**Manually:** in the Unraid webGUI go to **Plugins → Install Plugin** and
paste:

```
https://raw.githubusercontent.com/RayMunro/unraid-cache-array-share/main/cache-array-share.plg
```

Then open **Settings → User Utilities → Cache / Array Share Control**.
The package is embedded directly in the `.plg`, so nothing else needs to
be downloaded or copied by hand.

## Build and test

```bash
./build.sh
php tests/run.php
```

## Safety

- Nothing is selected by default, and only one tickbox can be selected per share.
- All three Select all actions exclude system-dependent shares. Those shares remain available for deliberate manual selection.
- Only explicitly selected shares are changed.
- All unrelated share settings are preserved.
- A backup and manifest are created before the first change.
- Completed changes are rolled back if a later share fails.
- `appdata`, `domains`, `system`, `isos`, and custom shares referenced by Docker or VM configuration are visibly marked as system-dependent.
- Unavailable pools or files remaining after Mover prevent Array-only from being applied.
- Forced transfers use the Array-only `/mnt/user0` path and are verified before the policy changes.

Author: Raymond Munro  
Copyright © Raymond Munro 2026  
License: GPL-3.0-or-later
