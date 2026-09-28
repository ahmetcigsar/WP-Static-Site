#!/usr/bin/env bash
set -euo pipefail

project_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
state_directory="${WEXT_PLAYGROUND_DIRECTORY:-$HOME/.local/share/wext-smartcrawl}"
port="${WEXT_PLAYGROUND_PORT:-9401}"
mkdir -p "$state_directory/wordpress" "$state_directory/plugins"
if [[ ! -f "$state_directory/plugins/smartcrawl-seo/wpmu-dev-seo.php" ]]; then
  curl -fL https://downloads.wordpress.org/plugin/smartcrawl-seo.latest-stable.zip -o "$state_directory/smartcrawl.zip"
  unzip -tq "$state_directory/smartcrawl.zip"
  unzip -qo "$state_directory/smartcrawl.zip" -d "$state_directory/plugins"
fi
install_mode=download-and-install
if [[ -f "$state_directory/wordpress/wp-config.php" ]]; then
  install_mode=do-not-attempt-installing
fi
exec npx --yes @wp-playground/cli@latest server \
  --port="$port" --site-url="http://127.0.0.1:$port" \
  --wordpress-install-mode="$install_mode" \
  --mount-before-install="$state_directory/wordpress:/wordpress" \
  --mount="$state_directory/plugins/smartcrawl-seo:/wordpress/wp-content/plugins/smartcrawl-seo" \
  --auto-mount="$project_directory/wext-static-publisher" \
  --mount="$project_directory:/workspace" \
  --blueprint="$project_directory/tests/blueprints/smartcrawl.json"
