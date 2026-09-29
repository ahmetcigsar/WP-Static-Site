# WordPress.org review fixes — 3.3.0

## Changes

- Removed the export-request PHP `display_errors` override, the database error-display override, and their UI option.
- Replaced local export assets, ZIP archives, bulk-download packages, and activity log files with a private, per-site database table. Binary bodies are stored as base64-encoded 256 KiB chunks; metadata is published after successful writes.
- Generate ZIP data without temporary files and stream authenticated downloads from database chunks. Cloudflare reads database assets through its existing WordPress HTTP API flow. SFTP uses phpseclib's bounded callback reader.
- Import prior local ZIPs without extracting them to disk. Preserve originals; show an administrator notice on failed imports, with retry by reactivation. Existing settings and REST routes remain available.
- Update retention, diagnostics, SEO file checks, and uninstall cleanup for database storage.

## Validation

Clean WordPress Playground installations with `WP_DEBUG=true`:

| Check | Result |
| --- | --- |
| PHP 8.1 export integration: 20 URLs, HTML/CSS/SEO/search/language output and ZIP contents | Passed |
| Admin/settings smoke test and SFTP selection flow | Passed |
| Archive sorting, bulk download, retention/deletion and deployment callbacks | Passed |
| Legacy brand/settings/secret/archive migration | Passed |
| Corrupt legacy ZIP preservation, admin notice and successful retry | Passed on extracted distribution |
| Activity log pagination and search | Passed |
| Diagnostics | Passed |
| Unsafe export paths and export asset rendering | Passed |
| PHP 8.1 and 8.3 database storage, binary chunks, interrupted-write cleanup, ZIP consistency and UTF-8 names | Passed |
| SFTP callback byte fidelity and empty-file handling | Passed |
| Anonymous REST denial, authorized ZIP byte fidelity and missing artifact response | Passed |
| Cloudflare session, binary upload and final deployment metadata through mocked HTTP | Passed |
| Uninstall removes export table while preserving unrelated WordPress data | Passed on extracted distribution |
| Invalid SEO links return missing without interrupting export | Passed on final extracted distribution |
| PHP parsing | 388 files passed |
| Distribution ZIP integrity and contents | Passed; no shell scripts or bundled translation packs |

The final distribution was extracted and used for the database/ZIP/REST/Cloudflare/uninstall test and Plugin Check. See `wordpress-org/plugin-check-3.3.0.json` for the scanner report. The scan has 0 errors and 79 warnings. Compared with the 3.2.1 report, three warning instances were removed and one schema-change warning was added for the versioned, plugin-owned table created by `dbDelta()`.

## Practical limits

- Playground uses SQLite; production MySQL/MariaDB was not exercised in this session.
- Cloudflare HTTP was mocked and SFTP data/selection were tested locally. No real Cloudflare or SFTP deployment was made.
- Assets and uncompressed ZIP copies increase database use: approximately 2.7 times exported bytes per retained build, excluding metadata and logs. Default retention is five builds.
- ZIP generation supports fewer than 4 GiB and at most 65,535 entries; ZIP64 is not implemented. Large-site performance was not benchmarked.
- Existing on-disk exports remain untouched as backups after import. They can be removed manually after verification. New exports do not create filesystem artifacts.
- Integration hooks now receive logical database identifiers in place of filesystem paths; custom integrations must use `Export_Storage` to read them.
- Passing Plugin Check does not constitute WordPress.org approval.

## Delivery

- Package: `dist/wext-static-publisher.zip`
- Version: 3.3.0
- Bytes: 840865
- SHA-256: `8adccfba4ed414a567e63959a67deaebf7b9a14a9954135d5d05692b9ac283ba`
- Reply draft: `wordpress-org/review-response-3.3.0.txt`
- Uploaded to the existing WordPress.org submission on September 29, 2026; the submission page confirmed version 3.3.0. The review email was answered. Manual review is pending.
- No live Cloudflare or SFTP deployment was performed.
