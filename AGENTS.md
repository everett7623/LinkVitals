# AGENTS.md

Codex development guide for the LinkVitals repository.

This file is the handoff document for future Codex work. The old specification
directory has been removed, so keep this file current whenever architecture,
workflows, settings, or release steps change.

## Repository Layout

The actual WordPress plugin lives in `linkvitals/`. The repository
root is a development and packaging workspace.

- `linkvitals/linkvitals.php` - main plugin bootstrap,
  version constants, singleton entry point, and manual `require_once` list.
- `linkvitals/includes/` - all `LHA_*` classes, one class per
  `class-lha-*.php` file.
- `linkvitals/assets/css/admin.css` - admin-only stylesheet.
- `linkvitals/assets/js/admin.js` - admin-only JavaScript.
- `linkvitals/languages/` - translation template and Chinese
  translation files.
- `tests/run.php` - dependency-free PHP contract tests for core static behavior.
- `generate-mo.php` and `generate-mo.py` - compile `.po` translations to `.mo`.
- `linkvitals.zip` - WordPress-uploadable release artifact.

Do not edit files inside release zips. Edit plugin source under
`linkvitals/`, then rebuild release artifacts only when packaging.

## Commands

There is no Composer setup, test runner, or CI configuration in this repo.
Verification is currently manual.

- Lint a PHP file when PHP is available:
  `php -l "linkvitals/includes/class-lha-<name>.php"`
- Run lightweight repository checks:
  `python tools/dev-verify.py`
- Run the dependency-free PHP contract tests directly:
  `php tests/run.php`
- Synchronize source translation strings into the manual catalogs:
  `python tools/i18n-sync.py`
- Rebuild Chinese translations after changing translatable strings:
  `python generate-mo.py`
- Build the WordPress-installable release zip:
  `python tools/package-release.py`
- The PHP translation compiler is also available:
  `php generate-mo.php`

If `php` is not on PATH, say so in the final response and use available checks
such as targeted source review and translation compilation with Python.

Every accepted source, documentation, packaging, or release-workflow change must
bump the plugin version before packaging. Version bumps must update all of these
places:

- `Version:` header in `linkvitals/linkvitals.php`
- `LHA_VERSION` constant in `linkvitals/linkvitals.php`
- `Stable tag` in `linkvitals/readme.txt`
- top `Changelog` entry in `linkvitals/readme.txt`
- top `Upgrade Notice` entry in `linkvitals/readme.txt`

## Product Summary

LinkVitals is a WordPress admin-only plugin for auditing link
health. It scans posts, pages, custom post types, nav menu custom links, taxonomy
term descriptions, excerpts with HTML, and WooCommerce product gallery image
URLs. It detects broken links, redirects, timeouts, SSL/DNS/server errors,
ignored domains or patterns, internal link issues, orphaned pages, anchor
fragment failures, and SEO risks on external links.

The plugin should have zero front-end footprint: no front-end assets, queries,
or heavy hooks on public site requests.

## Current Implementation Status

The MVP and phase-two feature set is implemented in source:

- lifecycle hooks, table creation, uninstall, and version upgrade routines
- custom DB tables and CRUD helpers
- queue-based batch scanner
- DOM-based link extraction and URL classification
- HTTP checking with HEAD, GET fallback, redirects, ignore lists, and rate limit
- WP-Cron queue processing and optional scheduled scans
- Tools admin page with tabs for Dashboard, Links Report, Internal Links,
  SEO Check, Settings, and Logs
- AJAX scan controls and per-link actions
- WP_List_Table report with filters, search, sorting, pagination, and bulk
  actions
- settings form, logger, CSV export, repair actions, redirect replacement,
  repair history with guarded rollback, anchor checker, internal link analyzer,
  SEO checker, email notifications
- optional `LHA_AI` helper for OpenAI or Anthropic replacement suggestions
- maintenance AJAX tools for orphan cleanup, log purge, and data reset

Recent cleanup already performed:

- rebranded the plugin and distribution identity as LinkVitals with the
  `linkvitals` directory, main file, text domain, language files, and zip name
- preserved `LHA_*` classes and all `lha_*` persisted/runtime identifiers so
  existing scan data, settings, AJAX actions, and cron hooks remain compatible
- removed Syncthing `.sync-conflict-*` files from `assets/`
- changed occurrences so each actual link appearance gets its own row
- fixed repair/unlink logger argument order and action names
- fixed repair URL lookup to use normalized URL hashes
- added logs for unignore and bulk ignore/unignore actions
- rebuilt `linkvitals-zh_CN.mo` with `python generate-mo.py`
- optimized report and CSV source lookups with batched occurrence summaries
- fixed actionable issue totals so 404 links are not double-counted
- added `tools/dev-verify.py` for lightweight no-WordPress development checks
- added `tests/run.php` with dependency-free contract tests for URL
  normalization, actionable issue totals, report filter sanitization, URL
  resolution, link classification, duplicate occurrences, and srcset parsing
- added `tools/i18n-sync.py` and catalog coverage checks for manual i18n
- added report filters for aggregate issues, DNS errors, server errors, and
  forbidden links
- centralized issue statuses in `LHA_DB::get_issue_statuses()` so report
  filters, notification totals, and rechecks stay aligned
- issue totals are status-based only; 404/5xx code buckets are displayed as
  secondary diagnostics and must not be added to actionable totals
- report filter input is clamped with `LHA_DB::sanitize_report_filter_key()`
  before querying or exporting
- moved report bulk action handling before page output so confirmation
  redirects and immediate list refreshes are reliable
- hardened unlink repair so it only edits supported post-content objects and
  handles `wp_update_post()` failures
- fixed the Python `.mo` compiler so UTF-8 Chinese translations are preserved
  instead of being decoded as `unicode_escape`
- fixed plugin language selection so `zh_CN` is not lowercased on save and
  existing `zh_cn` values still load the Chinese language pack
- changed the plugin language default to `auto`, which follows the WordPress
  site language (`zh*` loads Simplified Chinese; other locales use English)
- added a legacy language migration so old saved `zh_CN` / `en_US` values
  without an explicit manual-selection marker are reset to `auto` before the
  textdomain loads
- added repair history records for URL replacement and unlink actions, plus a
  Logs-tab rollback button that refuses to run if the content changed afterward
- added a repair history retention setting and maintenance action that purge
  old rolled-back repair records while keeping active rollback snapshots
- wired dashboard maintenance buttons for orphan cleanup, log purge, repair
  history purge, and guarded data reset
- added post-level `edit_post` capability checks before repair actions modify
  source post content
- made queue claims atomic with per-batch claim tokens and prevented scan
  completion while another worker still owns queue items

Known gaps:

- automated coverage is limited to dependency-free database and extractor
  contracts in `tests/run.php`; no WordPress integration test environment or
  CI is wired up
- old optional property-test tasks were never implemented
- release zips may not include the latest source edits until explicitly rebuilt
- use `python tools/package-release.py` for the upload zip; do not zip the
  repository root, an outer workspace folder, or a version-suffixed folder
  manually

## Architecture

`LinkVitals_Plugin` boots on `plugins_loaded`. It loads translations on
`init`, checks plugin version on `admin_init`, creates admin objects only when
`is_admin()`, and always registers cron handling.

Activation calls `LHA_Activator::activate()` to create tables, set default
options, set scan status, and schedule `lha_process_queue`. Deactivation clears
plugin cron hooks and sets scan state idle. Uninstall only drops data when the
`delete_data_on_uninstall` setting is enabled.

The scanning pipeline is orchestrated by `LHA_Scanner`:

1. Start scan and populate `LHA_Queue` with content objects.
2. `lha_process_queue` runs every five minutes, or AJAX calls process a batch.
3. Queue items are marked `processing`.
4. Old occurrences for the object are deleted.
5. `LHA_Link_Extractor` parses content with `DOMDocument`, resolves relative
   URLs, classifies each link, and records metadata.
6. `LHA_DB::upsert_link()` deduplicates by SHA-256 of normalized URL.
7. `LHA_DB::insert_occurrence()` records each appearance.
8. Pending links are checked by `LHA_Link_Checker` or skipped/ignored according
   to settings.
9. When queue and pending links are exhausted, scan status becomes `completed`.

## Core Classes

- `LHA_DB` - table names, table creation, URL normalization, link CRUD,
  occurrence CRUD, repair history CRUD, reports, stats, ignored state, cleanup.
- `LHA_Queue` - pending/processing/done/failed queue lifecycle, attempts,
  stuck-item reset.
- `LHA_Scanner` - scan orchestration, queue population, batch processing,
  settings-aware link checking.
- `LHA_Link_Extractor` - DOM extraction, srcset parsing, relative URL
  resolution, link classification.
- `LHA_Link_Checker` - WordPress HTTP API checks, fallback, redirects, error
  classification, proxy support, per-domain rate limiting.
- `LHA_Admin` - menu, tab rendering, assets, AJAX handlers.
- `LHA_List_Table` - Links Report table, row actions, bulk actions.
- `LHA_Settings` - settings rendering, sanitization, validation, cron reschedule.
- `LHA_Repair` - URL replacement, unlinking in post content, repair snapshots,
  and guarded rollback.
- `LHA_Exporter` - CSV download.
- `LHA_Logger` - audit log writes and log retention cleanup.
- `LHA_Internal_Analyzer` - inbound/outbound counts, orphan detection, HTTPS
  internal link checks.
- `LHA_Anchor_Checker` - fragment id/name validation.
- `LHA_SEO_Checker` - nofollow, sponsored, noopener/noreferrer, HTTP checks.
- `LHA_AI` - optional server-side AI suggestions.

## Data Model

Tables use `$wpdb->prefix . 'lha_' . $name`.

- `lha_links`: one row per normalized URL. Important fields include `url_hash`,
  `url`, `normalized_url`, `domain`, `link_type`, `http_code`, `status`,
  `error_type`, `final_url`, `redirect_count`, `response_time`, `content_type`,
  `first_seen`, `last_seen`, `last_checked`, `check_count`, `is_ignored`, and
  `ignore_reason`.
- `lha_occurrences`: one row for each place a URL appears. Important fields
  include `link_id`, `object_type`, `object_id`, `source_title`, `source_url`,
  `edit_url`, `html_tag`, `attribute_name`, `anchor_text`, `raw_html`,
  `context_snippet`, timestamps.
- `lha_queue`: scan objects with `object_type`, `object_id`, `object_url`,
  `status`, `priority`, `attempts`, `last_error`, `claim_token`, timestamps.
- `lha_logs`: audit trail with `action_type`, `url`, `old_value`, `new_value`,
  `object_ids`, `message`, `user_id`, `created_at`.
- `lha_repairs`: reversible source-content repair history. Important fields
  include `action_type`, `object_type`, `object_id`, `source_title`, `edit_url`,
  `old_url`, `new_url`, `old_content`, `new_content`, content hashes, `status`,
  `rollback_message`, `user_id`, `rolled_back_by`, and timestamps.

Important invariants:

- `LHA_DB::normalize_url()` must stay idempotent.
- URLs are deduplicated by hash of normalized URL.
- Re-scanning an object deletes its old occurrences before inserting current
  occurrences.
- Ignored links are excluded from normal report views except the Ignored filter.
- All variable SQL must use `$wpdb->prepare()` unless the value is a known table
  name or static SQL fragment controlled by code.

## Settings

All plugin settings live in the single `lha_settings` option.

Default keys include:

- `auto_scan`
- `scan_frequency` (`daily`, `weekly`, `monthly`)
- `batch_size` clamped to 1-100
- `http_timeout` clamped to 1-30
- `max_redirects` clamped to 1-10
- `check_external`
- `check_images`
- `check_media`
- `check_anchors`
- `check_nofollow`
- `ignore_domains`
- `ignore_patterns`
- `email_notifications`
- `notification_email`
- `delete_data_on_uninstall`
- `repair_history_retention_days` (`0` keeps rolled-back repair history
  forever; positive values purge old rolled-back repair records only)
- proxy settings: `proxy_enabled`, `proxy_host`, `proxy_port`, `proxy_type`
- `language` (`auto`, `en_US`, `zh_CN`)
- `language_manually_selected` (internal marker; legacy settings without this
  marker are migrated back to `auto`)

Locale values must preserve case. Do not sanitize `language` with
`sanitize_key()` because it lowercases `zh_CN` to `zh_cn`; normalize explicitly
and keep compatibility with existing lowercase values. The default `auto`
setting follows the WordPress site language via `get_locale()` and maps `zh*`
locales to `zh_CN`; other site locales use `en_US`.

Scan state lives in `lha_scan_status`, `lha_last_scan_time`, and `lha_version`.

## Admin And AJAX

The admin page is under Tools:
`tools.php?page=lha-dashboard`.

Tabs:

- Dashboard
- Links Report
- Internal Links
- SEO Check
- Settings
- Logs

AJAX handlers use the `lha_ajax_nonce` nonce and require `manage_options`.
Current actions include:

- `lha_start_scan`
- `lha_scan_progress`
- `lha_process_batch`
- `lha_pause_scan`
- `lha_resume_scan`
- `lha_recheck_link`
- `lha_ignore_link`
- `lha_unignore_link`
- `lha_export_csv`
- `lha_replace_url`
- `lha_unlink`
- `lha_rollback_repair`
- `lha_get_replace_preview`
- `lha_ai_analyze`
- `lha_ai_test`
- `lha_cleanup_orphans`
- `lha_purge_logs`
- `lha_purge_repairs`
- `lha_reset_data`

Settings saves use `check_admin_referer( 'lha_settings_nonce' )`.

## Coding Conventions

- Prefix every class with `LHA_`.
- Put each class in `linkvitals/includes/class-lha-<name>.php`.
- Add every new class file to the manual `require_once` list in
  `linkvitals.php`; there is no autoloader.
- Every PHP file starts with:
  `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- Use WordPress APIs and native admin patterns.
- Avoid new external dependencies unless the user explicitly approves them.
- Keep front-end impact at zero.
- Use `sanitize_*` on input, `esc_*` on output, and `$wpdb->prepare()` for SQL.
- Use `wp_send_json_success()` and `wp_send_json_error()` for AJAX responses.
- Use the `linkvitals` text domain for all user-facing strings.
- Regenerate translations after changing translatable strings.

## Internationalization

Translation files are in `linkvitals/languages/`.

When adding or changing user-facing strings:

1. Wrap strings with WordPress translation functions.
2. Update `linkvitals.pot` and `linkvitals-zh_CN.po`.
3. Run `python generate-mo.py` from the repo root.

The current translation setup is manual, not generated by WP-CLI.

## Development Priorities

When continuing development, prefer high-impact correctness and safety work:

- fix activation/runtime errors first
- verify scan pipeline behavior before UI polish
- keep repair operations conservative and well logged
- preserve occurrence accuracy
- protect admin actions with nonce and capability checks
- keep translations synchronized
- avoid broad refactors unless they clearly reduce real risk

Good next tasks:

- add a minimal test harness or WordPress test setup
- add property/unit tests for URL normalization, extraction, queue behavior,
  status classification, settings validation, repair unlinking, SEO detection,
  and occurrence cleanup
- audit remaining report/UI strings for translation coverage
- verify the current source inside a real WordPress install
- rebuild release zip after source changes are accepted
- verify release zips have exactly one top-level directory,
  `linkvitals/`, with `linkvitals.php` directly inside

## Pitfalls

- The repo root is not the plugin root.
- The LinkVitals rebrand changed the plugin directory and main-file identity.
  Do not activate a 0.2.x development folder and `linkvitals/` together; they
  intentionally share `LHA_*` classes and `lha_*` data for migration continuity.
- The release zip files are artifacts and may be stale.
- The upload artifact must be named `linkvitals.zip`, not
  `linkvitals-<version>.zip`. Some hosting file managers extract
  archives into a folder named after the zip; a versioned zip name can create
  `wp-content/plugins/linkvitals-<version>/`, which breaks predictable
  WordPress plugin deletion and upgrades.
- WordPress installs and deletes plugins by the path it discovered at upload
  time. If a bad zip was installed with an extra outer folder, the admin delete
  action may leave that outer folder behind; remove the stale outer folder from
  `wp-content/plugins/` manually after confirming the active plugin is gone.
- `CLAUDE.md` is legacy assistant guidance; `AGENTS.md` is the Codex guide.
- `.sync-conflict-*` files in `assets/` are Syncthing artifacts and should not
  be treated as source.
- `LHA_AI` is optional and should fail gracefully when no provider key is set.
- PHP may not be installed on the local PATH in this workspace.
