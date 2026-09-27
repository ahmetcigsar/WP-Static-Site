=== WP Static Publisher ===
Tags: static site, cloudflare workers, export, sftp
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 3.0.4
License: MIT
License URI: https://opensource.org/license/mit

Export WordPress sites to static files, use Headless CMS mode, and deploy to your own Cloudflare Workers account.

English is the default interface language. Turkish, Spanish, French, Simplified Chinese, Japanese, Arabic, and Portuguese translations follow the selected WordPress administrator locale.

== Description ==

WP Static Publisher exports complete sites, rewrites URLs, creates ZIP archives, and deploys directly to a user's own Cloudflare account. Headless CMS mode blocks the WordPress theme front end for visitors while signed export requests preserve the theme design in the static site. It includes directory-based multilingual routing with English as the default for new installations, integrations for Rank Math, All in One SEO, SEOPress, SureRank SEO, The SEO Framework, and Yoast SEO, SFTP deployment, archive management, WP-CLI, and CI endpoints protected by WordPress Application Passwords. All features are free and open source.

The plugin's original code is MIT licensed. Bundled third-party components keep their own licenses; see THIRD-PARTY-NOTICES.md and their included license files.

== Installation ==

1. Copy the `wext-static-publisher` directory to `wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Set the public site URL on the WP Static Publisher admin page.
4. Under Deploy > Cloudflare, connect your own Account ID and an API token with Workers Scripts Write permission.
5. Configure a `workers.dev` subdomain or custom domain in Cloudflare, then export and deploy the static site.
