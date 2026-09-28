#!/usr/bin/env bash
set -euo pipefail

project_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
package_directory="$(mktemp -d)"
trap 'rm -rf "$package_directory"' EXIT

mkdir -p "$project_directory/dist"
rm -f "$project_directory/dist/wext-static-publisher.zip"
cp -R "$project_directory/wext-static-publisher" "$package_directory/"
cp "$project_directory/src/worker.mjs" "$package_directory/wext-static-publisher/assets/worker.mjs"
# WordPress.org supplies language packs separately through translate.wordpress.org.
find "$package_directory/wext-static-publisher/languages" -type f \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' \) -delete
# WordPress.org rejects vendor development scripts in the distributable ZIP.
rm -f "$package_directory/wext-static-publisher/vendor/paragonie/random_compat/build-phar.sh"
(
  cd "$package_directory"
  zip -q -r "$project_directory/dist/wext-static-publisher.zip" wext-static-publisher
)

echo "$project_directory/dist/wext-static-publisher.zip"
