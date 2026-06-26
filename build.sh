#!/usr/bin/env bash
#
# Build a distributable plugin zip in dist/.
#
# The archive contains a top-level `valolink-plugin/` folder so WordPress installs/updates
# it under the correct slug. Only runtime files are included (no build/dev cruft).
#
# Version source of truth: the git tag (passed by CI as $1, e.g. "0.1.1"). The resolved version
# is stamped into the shipped plugin header + VALOLINK_PLUGIN_VERSION, so the zip always reports
# the version it's tagged as — no need to hand-bump the header before tagging. Falls back to the
# header's Version for local manual builds.
#
# Usage: ./build.sh [version]
# Output: dist/valolink-plugin-<version>.zip

set -euo pipefail

SLUG="valolink-plugin"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAIN="$ROOT/$SLUG.php"

header_version() {
  grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}

# Resolve version: explicit arg > VERSION env > plugin header. Strip any leading "v".
VERSION="${1:-${VERSION:-$(header_version)}}"
VERSION="${VERSION#v}"
if [ -z "$VERSION" ]; then
  echo "error: could not resolve a version (pass one as \$1, or set Version: in $MAIN)" >&2
  exit 1
fi

DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
DEST="$STAGE/$SLUG"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$DEST" "$DIST"

# Whitelist: only ship runtime files.
cp "$MAIN" "$DEST/"
cp "$ROOT/uninstall.php" "$DEST/"
cp -R "$ROOT/src" "$DEST/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$DEST/"

# Stamp the resolved version into the shipped copy (header + constant).
sed -i -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1$VERSION/" "$DEST/$SLUG.php"
sed -i -E "s/(define\('VALOLINK_PLUGIN_VERSION',[[:space:]]*')[^']*('\);)/\1$VERSION\2/" "$DEST/$SLUG.php"

ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" -x '*.DS_Store' )

echo "Built $ZIP (version $VERSION)"
( cd "$STAGE" && unzip -l "$ZIP" | awk 'NR>3 && $4 {print "  " $4}' | grep -v '^  ---' || true )
