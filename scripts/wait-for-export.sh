#!/usr/bin/env bash
set -euo pipefail

: "${WP_ORIGIN:?WP_ORIGIN is required}"
: "${WP_USER:?WP_USER is required}"
: "${WP_APP_PASSWORD:?WP_APP_PASSWORD is required}"
: "${EXPECTED_JOB_ID:?EXPECTED_JOB_ID is required}"

for attempt in $(seq 1 120); do
  response="$(curl --fail --silent --show-error \
    --user "$WP_USER:$WP_APP_PASSWORD" \
    "$WP_ORIGIN/wp-json/ragnus-static/v1/exports/latest")"
  state="$(jq -r '.state // "unknown"' <<<"$response")"
  job_id="$(jq -r '.job_id // ""' <<<"$response")"

  if [[ "$job_id" != "$EXPECTED_JOB_ID" ]]; then
    sleep 5
    continue
  fi

  if [[ "$state" == "completed" ]]; then
    exit 0
  fi

  if [[ "$state" == "failed" ]]; then
    jq . <<<"$response"
    exit 1
  fi

  sleep 5
done

echo "Export timed out after 10 minutes." >&2
exit 1
