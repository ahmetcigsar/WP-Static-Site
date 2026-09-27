#!/usr/bin/env bash
set -euo pipefail

project_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
package_directory="$(mktemp -d)"
trap 'rm -rf "$package_directory"' EXIT

mkdir -p "$project_directory/dist"
rm -f "$project_directory/dist/wext-static-publisher.zip"
cp -R "$project_directory/wext-static-publisher" "$package_directory/"
cp "$project_directory/src/worker.mjs" "$package_directory/wext-static-publisher/assets/worker.mjs"
(
  cd "$package_directory"
  zip -q -r "$project_directory/dist/wext-static-publisher.zip" wext-static-publisher
)

echo "$project_directory/dist/wext-static-publisher.zip"
