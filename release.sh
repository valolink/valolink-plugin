#!/usr/bin/env bash
#
# Bump the plugin version, commit, tag, and push — kicking off the GitHub Actions
# release workflow that builds the zip and publishes the release.
#
# Usage:
#   ./release.sh                # bump patch  (0.1.2 -> 0.1.3)
#   ./release.sh patch          # bump patch
#   ./release.sh minor          # bump minor  (0.1.2 -> 0.2.0)
#   ./release.sh major          # bump major  (0.1.2 -> 1.0.0)
#   ./release.sh 1.2.3          # set an explicit version
#   ./release.sh -h | --help    # show this help

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_FILE="$ROOT/valolink-plugin.php"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
    sed -nE '2,/^$/p' "$0" | sed 's/^# \?//'
    exit 0
fi

# --- pre-flight ---------------------------------------------------------------

if [ ! -f "$PLUGIN_FILE" ]; then
    echo "error: $PLUGIN_FILE not found" >&2
    exit 1
fi

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    echo "error: working tree is dirty. Commit or stash first." >&2
    git -C "$ROOT" status --short >&2
    exit 1
fi

# --- resolve current + new version --------------------------------------------

header_version() {
    grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_FILE" \
        | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}

CURRENT="$(header_version)"
if [ -z "$CURRENT" ]; then
    echo "error: could not read current version from $PLUGIN_FILE" >&2
    exit 1
fi

if ! echo "$CURRENT" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "error: current version '$CURRENT' is not X.Y.Z — refusing to guess" >&2
    exit 1
fi

ARG="${1:-patch}"
IFS='.' read -r MAJOR MINOR PATCH <<<"$CURRENT"

case "$ARG" in
    major) NEW="$((MAJOR + 1)).0.0" ;;
    minor) NEW="$MAJOR.$((MINOR + 1)).0" ;;
    patch) NEW="$MAJOR.$MINOR.$((PATCH + 1))" ;;
    *)
        if ! echo "$ARG" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
            echo "error: '$ARG' is not a valid semver (X.Y.Z) or bump keyword (major|minor|patch)" >&2
            exit 1
        fi
        NEW="$ARG"
        ;;
esac

if [ "$NEW" = "$CURRENT" ]; then
    echo "error: new version equals current ($CURRENT)" >&2
    exit 1
fi

# Refuse to overwrite an existing tag (locally or remotely).
if git -C "$ROOT" rev-parse "v$NEW" >/dev/null 2>&1; then
    echo "error: tag v$NEW already exists locally" >&2
    exit 1
fi

BRANCH="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD)"

echo "Repo:     $ROOT"
echo "Branch:   $BRANCH"
echo "Version:  $CURRENT  ->  $NEW"
echo
read -r -p "Proceed? [y/N] " confirm
case "$confirm" in
    [yY]|[yY][eE][sS]) ;;
    *) echo "Aborted."; exit 1 ;;
esac

# --- stamp the file -----------------------------------------------------------

sed -i -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1$NEW/" "$PLUGIN_FILE"
sed -i -E "s/(define\('VALOLINK_PLUGIN_VERSION',[[:space:]]*')[^']*('\);)/\1$NEW\2/" "$PLUGIN_FILE"

# Verify both stamps took.
HEADER_VER="$(header_version)"
CONST_VER="$(grep -oE "VALOLINK_PLUGIN_VERSION', '[^']+'" "$PLUGIN_FILE" | head -1 | sed -E "s/.*'([^']+)'/\1/")"
if [ "$HEADER_VER" != "$NEW" ] || [ "$CONST_VER" != "$NEW" ]; then
    echo "error: version stamp didn't apply cleanly (header=$HEADER_VER, const=$CONST_VER)" >&2
    echo "       inspect $PLUGIN_FILE and revert if needed" >&2
    exit 1
fi

# --- commit + tag + push ------------------------------------------------------

git -C "$ROOT" add "$PLUGIN_FILE"
git -C "$ROOT" commit -m "Release v$NEW"
git -C "$ROOT" tag -a "v$NEW" -m "v$NEW"

echo
read -r -p "Push commit + tag v$NEW to origin? [y/N] " push_confirm
case "$push_confirm" in
    [yY]|[yY][eE][sS])
        git -C "$ROOT" push origin "$BRANCH"
        git -C "$ROOT" push origin "v$NEW"
        echo "Pushed. The release workflow should build and publish the zip shortly."
        ;;
    *)
        echo "Skipped push. To push manually:"
        echo "  git push origin $BRANCH"
        echo "  git push origin v$NEW"
        ;;
esac
