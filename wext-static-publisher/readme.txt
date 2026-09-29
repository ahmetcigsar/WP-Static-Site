=== Wext Static Publisher ===
Tags: static site, headless cms, cloudflare, export, sftp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.3.0
License: MIT
License URI: https://opensource.org/license/mit

Export WordPress sites to static files, use Headless CMS mode, and deploy to your own Cloudflare Workers account.

== Description ==

Wext Static Publisher exports complete sites, rewrites URLs, creates ZIP archives, and deploys directly to a user's own Cloudflare account. Headless CMS mode blocks the WordPress theme front end for visitors while signed export requests preserve the theme design in the static site. It includes directory-based multilingual routing with English as the default for new installations, integrations for Rank Math, All in One SEO, SEOPress, SureRank SEO, The SEO Framework, Yoast SEO, and SmartCrawl SEO, SFTP deployment, archive management, WP-CLI, and CI endpoints protected by WordPress Application Passwords. All features are free and open source.

Static search runs locally in the visitor's browser with configurable fields, ranking weights, and typo tolerance. No search service account is required.

English is the default interface language. Available WordPress.org language packs follow the selected WordPress administrator locale. Translations are distributed separately through translate.wordpress.org.

The plugin's original code is MIT licensed. Bundled third-party components keep their own licenses; see THIRD-PARTY-NOTICES.txt and their included license files.

The human-readable source code and packaging instructions are available at https://github.com/ahmetcigsar/Wext-Static-Publisher .

== External services ==

Cloudflare connection and deployment are optional. When you connect your own Cloudflare account and deploy, the plugin sends your account ID, API token, Worker configuration, and generated static assets directly to Cloudflare. See Cloudflare's terms and privacy policy: https://www.cloudflare.com/website-terms/ and https://www.cloudflare.com/privacypolicy/ . ZIP export and local static search do not require a Cloudflare account.

IndexNow is optional. When enabled, a completed export sends the public site hostname, IndexNow key and key location, and the URLs of changed or removed pages to https://api.indexnow.org/indexnow so participating search engines can discover updates. See https://www.indexnow.org/terms for its terms and privacy information.

The GitHub deployment webhook is optional. If configured, the plugin sends the configured bearer token, export job ID, and build SHA-256 checksum to the webhook URL chosen by the site administrator. For GitHub repository dispatch, see GitHub's terms and privacy statement: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service and https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement .

== Installation ==

1. Copy the `wext-static-publisher` directory to `wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Set the public site URL on the Wext Static Publisher admin page.
4. Under Deploy > Cloudflare, connect your own Account ID and an API token with Workers Scripts Write permission.
5. Configure a `workers.dev` subdomain or custom domain in Cloudflare, then export and deploy the static site.

Export assets, ZIP archives, and activity logs are stored in a private, per-site database table. Downloads require WordPress authorization. The plugin does not write exported HTML, JavaScript, ZIP archives, or web-server rules to the filesystem. ZIP downloads use streaming, uncompressed ZIP entries. Allow database capacity for assets and archives; the archive retention setting controls completed exports.

Existing Wext Static Publisher installations can update in place. Saved settings are preserved. Earlier ZIP archives are imported into database storage without extracting files to disk; ZipArchive is required only for that import. Original uploads files remain untouched as backups. Once the imported exports have been verified, the administrator can remove the old plugin export directories through their hosting file manager.

== Screenshots ==

1. Main dashboard showing publishing status and the latest static build.
2. Deploy setup for a Cloudflare account owned by the site administrator.
3. Static Site settings with Headless CMS and multilingual options.
4. SEO plugin integrations and static output controls.
5. Hide settings for WordPress paths and traces in static output.

== Upgrade Notice ==

= 3.3.0 =
Moves exports and logs into private database storage and removes error-display overrides. Existing ZIPs are imported; original files remain as backups. Plan database capacity before updating large sites.

= 3.2.1 =
Updates export storage validation, asset enqueueing, language-pack loading, and legacy migration for directory review.

= 3.2.0 =
Adds SmartCrawl SEO integration and fixes disabled SEO output settings being re-enabled on save.

= 3.1.0 =
Update the existing Wext Static Publisher installation in place. Saved settings and export archives remain available.

== Changelog ==

= 3.3.0 =
* Store static assets, archives, and activity logs in a private per-site database table.
* Stream ZIP downloads and SFTP uploads without creating local export files.
* Read Cloudflare deployment assets and configuration directly from database storage.
* Preserve PHP and WordPress error reporting settings.
* Import previous ZIP archives without extracting them to disk.

= 3.2.1 =
* Render exported script and stylesheet references through WordPress asset queues.
* Use standard WordPress language packs instead of bundled translations.
* Wait for the administrator to deactivate the legacy plugin before migrating.
* Reject unsafe export paths, symbolic links, and server-executable file types.
* Store new export artifacts in the plugin-specific uploads directory with Apache/IIS access protection.

= 3.2.0 =
* Detect SmartCrawl SEO automatically and add independent metadata, Schema, sitemap, and robots.txt output controls.
* Preserve SmartCrawl SEO output and rewrite internal URLs to the public static domain.
* Export SmartCrawl sitemap stylesheets as static XSL files and adapt Schema SearchAction to static search.
* Fix unchecked SEO output settings being re-enabled by repeated WordPress sanitization.
* Add SmartCrawl integration tests and a persistent local WordPress Playground setup.

= 3.1.0 =
* Keep the Wext Static Publisher installation directory and translation domain consistent with the public name.
* Replace the bundled Apache-licensed search library with an MIT-licensed browser search script.
* Add WordPress.org screenshots and directory metadata.
