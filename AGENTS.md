# AGENTS.md

AI Coding Assistant Development Guide for the LinkVitals repository.

This file is the comprehensive reference for AI coding assistants (Claude Code, Cursor, GitHub Copilot, Codex, etc.) working with this codebase. Keep this file current whenever architecture, workflows, settings, or release steps change.

## Repository Layout

The actual WordPress plugin lives in `linkvitals/`. The repository
root is a development and packaging workspace.

- `linkvitals/linkvitals.php` - main plugin bootstrap,
  version constants, singleton entry point, and manual `require_once` list.
- `linkvitals/includes/` - all `LHA_*` classes, one class per
  `class-lha-*.php` file.
- `linkvitals/assets/css/admin.css` - admin-only stylesheet.
- `linkvitals/assets/js/admin.js` - admin-only JavaScript.
- `linkvitals/assets/js/image-repair.js` - bounded row and bulk interactions
  for missing WordPress image-size repairs.
- `linkvitals/assets/js/ai-admin.js` - AI connection tests and recursive
  polling/rendering for background orphan-page suggestions.
- `linkvitals/languages/` - translation template and Chinese
  translation files.
- `tests/run.php` - dependency-free PHP contract tests for core static behavior.
- `tests/integration/` - WP-CLI smoke tests that run against a real temporary
  WordPress database in GitHub Actions.
- `.github/workflows/ci.yml` - PHP compatibility, contract, translation, and
  release-package validation for pushes and pull requests.
- `generate-mo.php` and `generate-mo.py` - compile `.po` translations to `.mo`.
- `linkvitals.zip` - WordPress-uploadable release artifact.
- `SUBMITTING.md` - maintainer runbook for the WordPress.org plugin
  directory submission, review, and SVN publishing workflow.

Do not edit files inside release zips. Edit plugin source under
`linkvitals/`, then rebuild release artifacts only when packaging.

## Commands

There is no Composer setup. Local verification uses the dependency-free scripts
below. GitHub Actions also installs temporary WordPress sites backed by MySQL
for lifecycle and database integration checks.

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
- GitHub Actions runs PHP lint and `tests/run.php` on PHP 8.0 and PHP 8.3,
  checks translation synchronization and a freshly built release zip, and runs
  WP-CLI integration smoke tests against WordPress 6.4/PHP 8.0 and latest
  WordPress/PHP 8.3.

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
- optional OpenAI or Anthropic suggestions for broken links and orphaned pages,
  with encrypted settings and provider-neutral background jobs
- maintenance AJAX tools for orphan cleanup, log purge, and data reset

Design constraints distilled from past fixes. These are load-bearing: each one
encodes a bug that was already paid for once. The narrative history of how they
came about lives in `git log`, not here.

Reporting and statistics:

- `LHA_DB::get_issue_statuses()` is the single source of truth for issue
  statuses. Totals are status-based only. The 404/5xx code buckets are
  secondary diagnostics and must never be added to actionable totals, or the
  same link is counted twice.
- Report filter input must pass through `LHA_DB::sanitize_report_filter_key()`
  before it reaches a query or an export.
- CSV export streams rows in bounded 1000-row batches; a single-query export
  cap silently truncates large reports. Cells are neutralized against
  spreadsheet formula injection by `LHA_Exporter::guard_cell()`, and
  `LHA_DB::get_links()` orders with an `l.id` tiebreaker so batched windows
  stay stable.
- Report bulk actions must be handled before page output so confirmation
  redirects and immediate list refreshes work.
- Internal-link source counts must be restricted to matching published post
  occurrences; taxonomy and menu object IDs can otherwise collide with post IDs.
- SEO issue badges are translated through an explicit whitelist so internal
  issue identifiers are never exposed in the UI.

Scanning and queue:

- Per-object occurrence deletion must happen before the empty-content return,
  so clearing a post's content also clears its old link records.
- Occurrence replacement is delayed until extraction succeeds; a transient
  parser failure must preserve the last known-good result and retry.
- Stale post-occurrence cleanup compares post types as binary strings, because
  WordPress core tables and plugin tables may use different utf8mb4 collations.
- Full scans use bounded 100-row queue inserts; incremental and repair writes
  stay on the duplicate-aware path.
- Public taxonomy descriptions are paged by stable term ID in 100-term windows
  so initialization never loads an entire large taxonomy at once.
- Runtime batch sizes are clamped before queue and pending-link processing even
  when the stored option is corrupted.
- Pause and resume are restricted to valid running/paused transitions, and AJAX
  batch controls must report and honor the authoritative scan state.
- Link rows with no remaining occurrences are removed at scan start and again
  after queue and pending-link work fully drain.

Activation and cron:

- The custom queue recurrence must be registered explicitly during activation,
  or the first `lha_process_queue` event is rejected as an unknown schedule.
- AI cron cleanup must remove every argument variant, not just empty-argument
  events.
- Scan-completion notifications share one baseline and a short-lived option
  lock across the AJAX and WP-Cron paths so exactly one email is sent.

Repair safety:

- Repair actions check the post-level `edit_post` capability before modifying
  source post content, and only edit supported post-content objects.
- Replacements are bounded to exact URL tokens so longer URLs sharing a prefix
  are not corrupted.
- A rollback snapshot must be persisted before any content write, and rollback
  refuses to run when content changed after the snapshot.
- Replacement previews and repair writes are filtered against current post
  types and edit permissions; old occurrences are retained when refresh
  queueing fails.
- Repaired posts are queued for background occurrence refresh after
  replacement, unlink, and rollback, while explicitly paused scans stay paused.

AI:

- AI provider keys are encrypted only when the site defines `AUTH_KEY`.
  Without it, settings surface an error and keep the previously stored key;
  encrypting with a bundled salt is forbidden because plugin source is
  public on WordPress.org.
- AI job deduplication and status polling are scoped to the initiating
  administrator so edit links cannot cross permission contexts.

CI:

- Core checksum verification is limited to fixed WordPress CI targets, because
  the latest release archive and checksum API can briefly drift during rollouts.

Known gaps:

- WordPress integration coverage is intentionally a bounded WP-CLI smoke suite;
  browser-driven admin workflows and full PHPUnit fixtures are not wired up
- AI response envelopes and whitelist validation have contract coverage, but
  live provider calls still require staging verification with real credentials
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
plugin cron hooks and sets scan state idle. Network activation and deactivation
apply those operations to every existing site. While network-active,
`wp_initialize_site` provisions the same plugin state for newly created sites.
Uninstall evaluates `delete_data_on_uninstall` independently on every site and
only drops data where that setting is enabled.

Fresh activation stores `LHA_VERSION`. Existing installations keep their prior
`lha_version` marker while activation or `admin_init` provisions schema and
defaults; `check_version()` commits the new marker only after required upgrade
routines finish. `lha_upgrade_lock` serializes the entire version transaction,
rechecks the installed marker after locking, recovers expired ownership with an
atomic compare-and-swap, and is released only by its current owner. The CAS
recovery writes the options table directly, so it must invalidate both the
individual option cache and the `alloptions` cache; an autoloaded lock row
served from a stale `alloptions` entry would make the owner mismatch and the
recovered mutex would never be released.

The scanning pipeline is orchestrated by `LHA_Scanner`:

1. Start scan and populate `LHA_Queue` with content objects.
2. `lha_process_queue` runs every five minutes, or AJAX calls process a batch.
3. Queue items are marked `processing`.
4. `LHA_Link_Extractor` parses content with `DOMDocument`, resolves relative
   URLs, classifies each link, and records metadata.
5. After extraction succeeds, old occurrences for the object are deleted.
6. `LHA_DB::upsert_link()` deduplicates by SHA-256 of normalized URL.
7. `LHA_DB::insert_occurrence()` records each appearance. Extraction failures
   retain the previous occurrences and return the queue item for bounded retry.
8. Pending links are checked by `LHA_Link_Checker` or skipped/ignored according
   to settings.
9. When queue and pending links are exhausted, scan status becomes `completed`.

The orphan-page AI workflow is separate from scanning. The admin queues one
`lha_process_ai_orphan_job` single event, polls a transient-backed stable state,
and displays at most three suggestions. `LHA_AI_Internal` queries a bounded
candidate pool once, sends at most ten title/excerpt contexts, and discards any
model IDs outside the server-owned candidate map. It never writes post content.

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
- `LHA_Image_Repair` - recognizes WordPress dimension suffixes, verifies an
  internal original image, and delegates changes to `LHA_Repair`.
- `LHA_Exporter` - CSV download.
- `LHA_Logger` - audit log writes and log retention cleanup.
- `LHA_Internal_Analyzer` - inbound/outbound counts, orphan detection, HTTPS
  internal link checks.
- `LHA_Anchor_Checker` - fragment id/name validation.
- `LHA_SEO_Checker` - nofollow, sponsored, noopener/noreferrer, HTTP checks.
- `LHA_AI` - optional server-side AI suggestions.
- `LHA_AI_Internal` - bounded candidate ranking, prompt context, and strict
  server-side validation for orphaned-page suggestions.
- `LHA_AI_Jobs` - transient-backed queue, deduplication, WP-Cron processing,
  stable status polling, and result retention for AI suggestions.

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
- `lha_queue`: scan objects with `object_type`, `object_id`, optional
  `object_url`, `status`, `priority`, `attempts`, `last_error`, `claim_token`,
  timestamps. Current scanners resolve source URLs during processing rather
  than precomputing them while populating the queue.
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
- `ai_provider` (`openai`, `claude`, or empty to disable)
- encrypted credentials: `ai_key_openai`, `ai_key_claude`
- editable models: `ai_model_openai`, `ai_model_claude`
- `language` (`auto`, `en_US`, `zh_CN`)
- `language_manually_selected` (internal marker; legacy settings without this
  marker are migrated back to `auto`)

Locale values must preserve case. Do not sanitize `language` with
`sanitize_key()` because it lowercases `zh_CN` to `zh_cn`; normalize explicitly
and keep compatibility with existing lowercase values. The default `auto`
setting follows the WordPress site language via `get_locale()` and maps `zh*`
locales to `zh_CN`; other site locales use `en_US`.

Scan state lives in `lha_scan_status`, `lha_scan_started_at`,
`lha_last_scan_time`, `lha_scan_type`, `lha_scan_token`,
`lha_content_scan_cursor`, and `lha_version`. `lha_scan_state_lock` serializes
scan initialization and completion state transitions. `lha_last_scan_time` is written only when shared pipeline work
finishes. Incremental scans use `lha_content_scan_cursor`, which is promoted
from the start timestamp only after a full or incremental content scan
completes; link-only rechecks and repair refreshes do not advance it.

Upgrade state is separate: `lha_upgrade_lock` serializes provisioning, migration,
and the final `lha_version` commit without conflating version ownership with scan
pipeline ownership.

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
- `lha_repair_image_variant`
- `lha_unlink`
- `lha_rollback_repair`
- `lha_get_replace_preview`
- `lha_ai_analyze`
- `lha_ai_test`
- `lha_ai_orphan_trigger`
- `lha_ai_orphan_status`
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
- `CLAUDE.md` is the short daily reference; `AGENTS.md` is this complete guide.
  Both are current and both are maintained. Keep them consistent when
  architecture, workflows, settings, or release steps change.
- `.sync-conflict-*` files in `assets/` are Syncthing artifacts and should not
  be treated as source.
- `LHA_AI` is optional and should fail gracefully when no provider key is set.
- AI orphan suggestions depend on WP-Cron. They are suggestion-only, must use
  bounded context, and model IDs must be validated against server candidates.
- PHP may not be installed on the local PATH in this workspace.
