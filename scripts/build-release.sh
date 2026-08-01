#!/usr/bin/env bash
# Build a WordPress-installable Lite plugin zip with the given semver.
#
# Usage:
#   ./scripts/build-release.sh              # reads VERSION file
#   ./scripts/build-release.sh 1.2.3        # explicit version
#   ./scripts/build-release.sh v1.2.3       # strips leading "v"
#
# Output: dist/woo-sortillus-lite-<version>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
PLUGIN_SLUG="woo-sortillus-lite"
MAIN_FILE="woo-sortillus-lite.php"

version="${1:-}"
if [[ -z "$version" ]]; then
	if [[ ! -f "$ROOT/VERSION" ]]; then
		echo "error: pass a version or add a VERSION file at repo root" >&2
		exit 1
	fi
	version="$(tr -d '[:space:]' < "$ROOT/VERSION")"
fi

version="${version#v}"

if [[ ! "$version" =~ ^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$ ]]; then
	echo "error: invalid semver: $version" >&2
	exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

stage="$work/$PLUGIN_SLUG"
mkdir -p "$stage"

rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude 'dist/' \
	--exclude 'scripts/' \
	--exclude 'tests/' \
	--exclude 'VERSION' \
	--exclude 'node_modules/' \
	--exclude 'vendor/' \
	--exclude '.env' \
	--exclude '.env.*' \
	--exclude '*.log' \
	"$ROOT/" "$stage/"

main="$stage/$MAIN_FILE"
if [[ ! -f "$main" ]]; then
	echo "error: missing $MAIN_FILE in staged build" >&2
	exit 1
fi

sed -i.bak -E "s/^ \* Version: .*/ * Version: ${version}/" "$main"
sed -i.bak -E "s/^(define\( 'WOO_SORTILLUS_LITE_VERSION', ')[^']+(' \);)/\1${version}\2/" "$main"
echo "$version" > "$stage/build-version.txt"
rm -f "$main.bak"

mkdir -p "$DIST"
out="$DIST/${PLUGIN_SLUG}-${version}.zip"
rm -f "$out"

(
	cd "$work"
	zip -r "$out" "$PLUGIN_SLUG" -x '*.DS_Store'
)

echo "Built $out (version ${version})"
