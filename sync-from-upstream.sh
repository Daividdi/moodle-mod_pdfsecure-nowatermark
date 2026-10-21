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
import pathlib, re, sys
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

# lib.php: swap the whole comment + return, not just the return. Swapping only the
# line left upstream's comment ("the default has to be the old behaviour") sitting on
# top of the inverted code - a variant whose comment contradicts its own logic.
VARIANT_COMMENT = "    // THIS BUILD INVERTS THE DEFAULT - see VARIANT.md. Unmarked unless an admin has\n    // explicitly asked for stamping.\n    //\n    // The fallback lives here in the code, not only in the setting's default value,\n    // and that is the whole trick that makes the two builds swappable: they carry the\n    // SAME $plugin->version, so Moodle runs no upgrade step when one replaces the\n    // other and therefore never applies a new setting default. Deciding at call time\n    // means the build behaves correctly as soon as the new code is in force, in\n    // either direction, with no upgrade and no downgrade.\n    //\n    // Caveat proven on a live install: OPcache serves the previously compiled\n    // lib.php until it revalidates (opcache.revalidate_freq, 60s on our servers),\n    // so reload PHP after swapping builds instead of trusting the file copy.\n"
p = root / "lib.php"
s = p.read_text()
if "THIS BUILD INVERTS THE DEFAULT" in s and "return ($mode === 'full') ? 'full' : 'off';" in s:
    print("  lib.php: already applied")
else:
    rx = re.compile(r"(    \$mode = get_config\('mod_pdfsecure', 'stampmode'\);\n)((?:    //.*\n)*)(    return \(\$mode === 'off'\) \? 'off' : 'full';)")
    s2, n = rx.subn(lambda mm: mm.group(1) + VARIANT_COMMENT + "    return ($mode === 'full') ? 'full' : 'off';", s, count=1)
    if n != 1:
        sys.exit("ANCHOR MOVED in lib.php - pdfsecure_stamp_mode() changed upstream, fix the delta by hand")
    p.write_text(s2)
    print("  lib.php: patched")
swap("settings.php",
     "get_string('settingstampmode_desc', 'mod_pdfsecure'),\n        'full',",
     "get_string('settingstampmode_desc', 'mod_pdfsecure'),\n        'off',")
# The release string changes with every upstream version, so anchor on the
# $plugin->release line itself and append the suffix to whatever it holds.
# (A literal 'v2.1.0' anchor broke the first sync after upstream bumped.)
p = root / "version.php"
s = p.read_text()
if "-nowatermark'" in s:
    print("  version.php: already applied")
else:
    s2, n = re.subn(r"(\$plugin->release\s*=\s*')([^']+)(';)", r"\1\2-nowatermark\3", s, count=1)
    if n != 1:
        sys.exit("ANCHOR MOVED in version.php - no $plugin->release line, fix the delta by hand")
    p.write_text(s2)
    print("  version.php: patched")
PY

echo "Synced from ${UPSTREAM}@${REF}. Review the diff before committing."
