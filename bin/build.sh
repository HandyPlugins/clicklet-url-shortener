#!/usr/bin/env bash
#
# Build the release package: copy the plugin without the files listed in
# .distignore into build/fast-url-shortener and zip it. Admin assets are
# compiled first; their sources ship too, as WordPress.org requires.
#
# Usage: bin/build.sh

set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="fast-url-shortener"
BUILD_DIR="build/${SLUG}"

npm ci --no-audit --no-fund --loglevel=error
npm run build

rm -rf build
mkdir -p "${BUILD_DIR}"

EXCLUDES=(--exclude-from=.distignore --exclude=build)

# Local-only files (AI assistant files, internal notes) listed in .git/info/exclude.
if [ -f .git/info/exclude ]; then
	EXCLUDES+=(--exclude-from=.git/info/exclude)
fi

rsync -a "${EXCLUDES[@]}" ./ "${BUILD_DIR}/"

(cd build && zip -qr "${SLUG}.zip" "${SLUG}")

echo "Built build/${SLUG}.zip"
