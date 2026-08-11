#!/usr/bin/env bash
set -euo pipefail

project_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
package_directory="$(mktemp -d)"
trap 'rm -rf "$package_directory"' EXIT

mkdir -p "$project_directory/dist"
cp -R "$project_directory/ragnus-static-publisher" "$package_directory/"
(
  cd "$package_directory"
  zip -q -r "$project_directory/dist/ragnus-static-publisher.zip" ragnus-static-publisher
)

echo "$project_directory/dist/ragnus-static-publisher.zip"
