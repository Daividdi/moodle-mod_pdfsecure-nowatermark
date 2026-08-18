#!/usr/bin/env bash
# Re-create this variant from the canonical plugin.
#
# The variant is the canonical tree with three lines changed. Rather than
# cherry-picking upstream commits and hoping the two histories stay compatible,
# this rebuilds the tree from upstream every time and re-applies the delta.
# If an anchor string has moved upstream, it stops instead of producing a build
# that silently lost the inversion.
set -euo pipefail

UPSTREAM="${UPSTREAM:-https://github.com/Daividdi/moodle-mod_pdfsecure.git}"
REF="${1:-main}"
HERE="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

git clone --quiet --depth 1 --branch "$REF" "$UPSTREAM" "$TMP/up"
rm -rf "$TMP/up/.git"

# Everything except this repository's own files comes from upstream.
find "$HERE" -mindepth 1 -maxdepth 1 \
     ! -name .git ! -name VARIANT.md ! -name sync-from-upstream.sh \
     -exec rm -rf {} +
cp -R "$TMP/up/." "$HERE/"

python3 - "$HERE" <<'PY'
import pathlib, sys
root = pathlib.Path(sys.argv[1])

def swap(relpath, old, new):
    p = root / relpath
    s = p.read_text()
    if new in s:
        print(f"  {relpath}: already applied")
        return
    if old not in s:
        sys.exit(f"ANCHOR MOVED in {relpath} - upstream changed, fix the delta by hand")
    p.write_text(s.replace(old, new, 1))
    print(f"  {relpath}: patched")

swap("lib.php",
     "return ($mode === 'off') ? 'off' : 'full';",
     "return ($mode === 'full') ? 'full' : 'off';")
swap("settings.php",
     "get_string('settingstampmode_desc', 'mod_pdfsecure'),\n        'full',",
     "get_string('settingstampmode_desc', 'mod_pdfsecure'),\n        'off',")
swap("version.php",
     "$plugin->release   = 'v2.1.0';",
     "$plugin->release   = 'v2.1.0-nowatermark';")
PY

echo "Synced from ${UPSTREAM}@${REF}. Review the diff before committing."
