#!/usr/bin/env bash
# Sync a version into the Lite plugin source.
#
# Usage:
#   ./scripts/sync-version.sh 1.2.3

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MAIN="$ROOT/woo-sortillus-lite.php"
VERSION_FILE="$ROOT/VERSION"

version="${1:-}"
if [[ -z "$version" ]]; then
	version="$(tr -d '[:space:]' < "$VERSION_FILE")"
fi

version="${version#v}"

if [[ ! "$version" =~ ^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$ ]]; then
	echo "error: invalid semver: $version" >&2
	exit 1
fi

printf '%s\n' "$version" > "$VERSION_FILE"

sed -i.bak -E "s/^ \* Version: .*/ * Version: ${version}/" "$MAIN"
sed -i.bak -E "s/^(define\( 'WOO_SORTILLUS_LITE_VERSION', ')[^']+(' \);)/\1${version}\2/" "$MAIN"
rm -f "$MAIN.bak"

echo "Synced version ${version} to VERSION, plugin header, and runtime constant"
