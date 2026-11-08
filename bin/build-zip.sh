#!/usr/bin/env bash
#
# Build the Wise Addons Pro release zip (upload it to the Lemon Squeezy product; see docs/RELEASE.md).
#
#   bin/build-zip.sh
#
# Copies the plugin into a temporary folder, leaving out everything listed in
# .distignore, renames the main file to <slug>.php, and zips the result as
#   <dist>/<slug>-<version>.zip
# with a single top-level folder named <slug>/.
#
# DIST_DIR overrides the output folder. The default is the "dist" folder at
# the root of the Local site (five levels above the plugin folder).

set -euo pipefail

SLUG="wise-addons-for-elementor-pro"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${DIST_DIR:-$ROOT/../../../../../dist}"
mkdir -p "$DIST_DIR"
DIST_DIR="$(cd "$DIST_DIR" && pwd)"

# The main plugin file is the root PHP file with a "Plugin Name:" header.
MAIN_FILE=""
for file in "$ROOT"/*.php; do
	if grep -q "^ \* Plugin Name:" "$file"; then
		MAIN_FILE="$(basename "$file")"
		break
	fi
done
if [ -z "$MAIN_FILE" ]; then
	echo "Main plugin file not found in $ROOT" >&2
	exit 1
fi

VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$MAIN_FILE" | head -n 1 | tr -d '[:space:]')"
STABLE="$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt" | head -n 1 | tr -d '[:space:]')"
if [ -z "$VERSION" ] || [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: plugin header \"$VERSION\", readme Stable tag \"$STABLE\"" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/$SLUG/"

if [ "$MAIN_FILE" != "$SLUG.php" ]; then
	mv "$STAGE/$SLUG/$MAIN_FILE" "$STAGE/$SLUG/$SLUG.php"
	# Keep the source reference in the translation template in step with the rename.
	for pot in "$STAGE/$SLUG"/languages/*.pot; do
		[ -f "$pot" ] || continue
		sed -i.bak "s|^#: $MAIN_FILE|#: $SLUG.php|" "$pot"
		rm -f "$pot.bak"
	done
fi

OUT="$DIST_DIR/$SLUG-$VERSION.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -rqX "$OUT" "$SLUG" )

echo "Built $OUT ($(du -h "$OUT" | cut -f1 | tr -d '[:space:]'))"
