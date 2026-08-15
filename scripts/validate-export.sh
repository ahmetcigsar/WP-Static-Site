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

if [[ -n "${EXPECTED_JOB_ID:-}" ]]; then
  jq -e --arg job_id "$EXPECTED_JOB_ID" '.job_id == $job_id' \
    "$output_directory/ragnus-static-manifest.json" >/dev/null
fi

if [[ -n "${EXPECTED_BUILD_SHA256:-}" ]]; then
  jq -e --arg checksum "$EXPECTED_BUILD_SHA256" '.build_sha256 == $checksum' \
    "$output_directory/ragnus-static-manifest.json" >/dev/null
fi

jq -e '.schema_version == 1 and (.enabled | type == "boolean") and (.supported_languages | type == "array")' \
  "$output_directory/ragnus-language-config.json" >/dev/null
