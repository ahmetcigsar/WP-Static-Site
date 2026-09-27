# Wext Static Publisher architecture

## Boundaries

- WordPress is the content source and export engine.
- In the GitHub workflow, Cloudflare credentials are stored only in CI secrets.
- In the direct Cloudflare workflow, users supply their own Account ID and API token. WordPress stores the token encrypted; no external deployment service is involved.
- REST endpoints require WordPress Application Password authentication and the plugin's `wext_static_export` capability.
- Headless + Static Publisher mode blocks the WordPress theme front end for visitors. Same-origin export requests access theme HTML using timestamp and HMAC headers derived from WordPress salts and valid for five minutes.
- Cloudflare CI deployment publishes a complete, atomic static snapshot. SFTP uploads files directly to the target directory.
- Forms, search, comments, memberships, and commerce need separate static-compatible implementations; static search is provided by the plugin.

## Export and deployment flow

1. For a manual CI export, CI calls `POST /wp-json/wext-static/v1/exports`. For automatic exports, WordPress groups selected content, media, menu, theme, and site-setting changes for 60 seconds.
2. WordPress queues the job in WP-Cron.
3. The exporter seeds published content URLs and crawls same-origin resources. In Headless mode, it signs these requests; ordinary visitors to the CMS origin receive the configured 404, 410, or 307 response.
4. Origin URLs in HTML and CSS are replaced with the public static domain.
5. The snapshot produces `_headers`, `_redirects`, language-routing configuration, a manifest, and a ZIP archive.
6. After an export, an optional GitHub `repository_dispatch` webhook sends the job ID and build SHA-256 to CI.
7. CI downloads only the ZIP from `/exports/{job_id}/artifact` and compares its manifest job ID and SHA-256 with the webhook payload.
8. Wrangler deploys the verified snapshot to the user's Workers Static Assets target from `wrangler.jsonc` and checks the deployment URL over HTTP.
9. GitHub Actions sends an authenticated `deploying`, `completed`, or `failed` result to `/deployments/callback`. WordPress stores export and Cloudflare deployment status separately.
10. If automatic SFTP upload is enabled, files from the same successful build directory are uploaded to the remote target. SFTP status and errors are recorded separately from the ZIP export.

In the direct Cloudflare flow, WordPress creates an asset manifest after a successful export, uploads missing assets to the user's Worker, and updates the Worker module with the new asset token. `_headers` and `_redirects` are included in the asset configuration. Users configure `workers.dev` or a custom domain in their Cloudflare dashboard.

## SFTP flow

- SFTP uses the bundled phpseclib 3 client with password authentication. If phpseclib cannot load, PHP cURL with SFTP support can serve as a fallback transport.
- Host, port, username, remote directory, timeout, and optional MD5 host fingerprint are saved under **Deploy > SFTP**.
- The password is encrypted with Sodium `secretbox` or OpenSSL AES-256-GCM using a key derived from WordPress `AUTH_KEY`. It is excluded from admin responses and filter data.
- The connection check verifies access to and listing permission on the target directory. When a fingerprint is supplied, the cURL transport verifies the host key during connection.
- Manual upload sends the latest successful build. The automatic option runs the same upload after each successful export.
- Matching remote paths are overwritten and missing subdirectories are created. Unrelated or obsolete remote files are not deleted automatically.

## Multilingual flow

- Multilingual export supports directory-based WordPress translation URLs such as `/en/` and `/tr/`.
- Enabled language roots are added to the crawl queue alongside normal content seeds. Each language must produce `/<language>/index.html`.
- The export generates `wext-language-config.json` and `wext-language-preference.js` for preference storage.
- New installations use English as the default language. Existing saved language settings remain intact. Settings live under **Static Site > Multilingual**; the option key is retained for compatibility.
- **SEO > SEO Plugins** separately controls whether Rank Math page metadata, JSON-LD, sitemap, and robots output are included in the static package.
- The Rank Math sitemap tree follows safe same-origin `*.xml` and `*.xsl` sitemap paths only, up to 100 files. Origin URLs are mapped to the public domain.
- Rank Math JSON-LD URLs are mapped to the public domain. When static search is enabled, the `SearchAction` target becomes the static search path; a dynamic WordPress search action is not left in the static output.
- The Cloudflare Worker routes only `/`. An explicit preference cookie takes precedence over `Accept-Language`, which takes precedence over the configured default language.
- Because the redirect depends on the visitor, it uses `302`, `Cache-Control: private, no-store`, and `Vary: Accept-Language, Cookie`.
- Language-specific paths are served directly by Static Assets without another language redirect.
- The exporter maps `hreflang` URLs to the public domain and adds the router root as `x-default`.

## Security model

- CI should use a separate WordPress user with the `Static Publisher Deploy` role.
- Create an Application Password for that user. Keep the WordPress administrator password out of CI.
- Keep `WP_APP_PASSWORD` and the Cloudflare token in their respective secret stores.
- The deployment callback rejects status updates whose job ID and SHA-256 do not match the archive.
- The Cloudflare API token should have `Workers Scripts Write` only for the intended account. WordPress encrypts it using its security keys.
- A fine-grained token stored in WordPress for GitHub `repository_dispatch` should have `Contents: write` only for the relevant repository.
- Restrict the SFTP account to the target static directory without shell, WordPress directory, or broader server access.
- Verify the SFTP host fingerprint with the hosting provider through a separate channel. Without a fingerprint, the connection is encrypted but the server identity is not pinned.

## Known MVP limitations

- Low-traffic sites and origins behind Cloudflare Access should trigger WP-Cron with a system cron job.
- Very large sites may need incremental export instead of a full crawl.
- WordPress AJAX/REST endpoints requested by JavaScript at runtime are not made static.
- Complex CSS URL syntax and asset URLs embedded in JavaScript need site-specific checks.
- The WordPress multilingual plugin is responsible for translated content and reciprocal `hreflang` tags.
- The plugin writes Apache/IIS rules to block the storage directory. On Nginx, also block `wp-content/uploads/wext-static`; artifacts are downloadable only through authenticated REST.
- If the origin is behind Cloudflare Access or HTTP Basic, add the required service headers through the `wext_static_request_args` filter in a site-specific MU plugin.
- SFTP deployment uses password authentication; private-key authentication is outside this version's scope.
- Direct SFTP upload is not atomic and does not remove obsolete remote files.

Example Cloudflare Access filter:

```php
add_filter('wext_static_request_args', function (array $args): array {
    $args['headers']['CF-Access-Client-Id'] = getenv('CF_ACCESS_CLIENT_ID');
    $args['headers']['CF-Access-Client-Secret'] = getenv('CF_ACCESS_CLIENT_SECRET');
    return $args;
});
```
