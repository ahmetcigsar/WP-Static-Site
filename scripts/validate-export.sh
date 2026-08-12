#!/usr/bin/env bash
set -euo pipefail

output_directory="${1:-dist}"

test -f "$output_directory/index.html"
test -f "$output_directory/404.html"
test -f "$output_directory/_headers"
test -f "$output_directory/_redirects"
test -f "$output_directory/ragnus-static-manifest.json"
test -f "$output_directory/ragnus-language-config.json"

if [[ -n "${WP_ORIGIN:-}" ]] && rg -F -n --glob '*.{html,css,js,json}' "$WP_ORIGIN" "$output_directory"; then
  echo "Export still contains the WordPress origin URL." >&2
  exit 1
fi

jq -e '.schema_version == 1 and (.build_sha256 | length == 64)' \
  "$output_directory/ragnus-static-manifest.json" >/dev/null

jq -e '.schema_version == 1 and (.enabled | type == "boolean") and (.supported_languages | type == "array")' \
  "$output_directory/ragnus-language-config.json" >/dev/null
