# This is a variant build

**Canonical source:** https://github.com/Daividdi/moodle-mod_pdfsecure

This repository is not a different plugin. It is the same plugin, the same
component (`mod_pdfsecure`), with **one behaviour inverted**: it serves documents
**unmarked** unless an administrator explicitly turns stamping on.

It exists for sites where the workstations already apply their own watermark —
Malaysia and WuXi — so those installs need no post-install configuration step.

## Why it is not called `mod_pdfsecure_nowatermark`

Moodle activity module names may not contain underscores. `core_component::is_valid_plugin_name('mod', 'pdfsecure_nowatermark')`
returns false, so a module by that name cannot be installed at all.

More importantly, a *different* component name would be the wrong answer even if
it were legal. Activities created by `mod_pdfsecure` are rows of type
`pdfsecure`; a second module type would not serve any of them. The 93 migrated
activities in Malaysia would have to be migrated a second time. Keeping the
component identical is what makes this build a drop-in.

## The entire difference

Three lines, and nothing else:

| File | Standard build | This build |
| --- | --- | --- |
| `lib.php` → `pdfsecure_stamp_mode()` | `($mode === 'off') ? 'off' : 'full'` | `($mode === 'full') ? 'full' : 'off'` |
| `settings.php` → `stampmode` default | `'full'` | `'off'` |
| `version.php` → `$plugin->release` | `v2.1.0` | `v2.1.0-nowatermark` |

## Why `$plugin->version` is deliberately identical

Moodle convention says the version integer must change whenever plugin code
changes. This build breaks that convention on purpose.

Both builds carry `2026081700`. That means swapping one for the other is a file
replacement with **no upgrade step in either direction** — and no downgrade,
which is the direction Moodle refuses outright. Had this build carried a higher
number, installing it would upgrade cleanly but going back to the standard build
would be blocked.

That only works because the behaviour is decided at call time in
`pdfsecure_stamp_mode()` rather than by an upgrade-time setting default. Swap the
files, and the next request already behaves correctly.

The two builds are told apart by `$plugin->release`, which Moodle shows in
*Site administration → Plugins → Plugins overview*.

## Switching

- **Standard build, stamp off:** set *Watermark the documents* to **No**. Same
  result as this build; preferred when the site is comfortable managing settings.
- **This build:** install it and do nothing. An admin can still turn stamping on.

Either way the access control is identical. See the README for what turning the
stamp off does and does not change.

## Keeping in sync

Run `./sync-from-upstream.sh` after any change lands upstream. It fetches the
canonical repository, copies it over this tree and re-applies the three-line
delta, failing loudly if an anchor has moved. A stale variant of a security
plugin is worse than no variant, and this repository is the one that goes stale.
