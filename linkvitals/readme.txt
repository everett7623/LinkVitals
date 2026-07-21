=== LinkVitals – Link Health & SEO Auditor ===
Contributors: everettlabs
Tags: broken links, link checker, seo, 404, redirect
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.3.24
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive link health audit for WordPress. Detect broken links, redirects, timeouts, SSL errors, orphaned pages, and SEO link risks.

== Description ==

LinkVitals helps WordPress site owners maintain healthy links across their entire website. It scans posts, pages, menus, custom post types, and taxonomy descriptions to find broken links, redirects, timeouts, and other link issues.

**Key Features:**

* Scan all post types, pages, nav menus, and taxonomy descriptions
* Detect broken links (404, 5xx errors)
* Identify redirects (301, 302, 307, 308)
* Find timeout and SSL errors
* Internal link analysis with orphaned page detection
* Batch scanning with queue system (never overloads your server)
* Pause/resume scanning at any time
* Email notifications for new broken links
* CSV export of reports
* Rate limiting for external domain requests
* WP-Cron scheduled automatic scans
* Verified one-click and selected-row repairs for missing WordPress image sizes
* Clickable dashboard statistics that open the matching report filter
* Optional AI suggestions for orphaned pages with source-page edit links
* No required external service dependencies
* WordPress native admin UI

**What it scans:**

* `<a href>` links
* `<img src>` images
* `<source srcset>` responsive images
* `<iframe>`, `<embed>`, `<object>` embeds
* `<video>` and `<audio>` media
* Download links (PDF, DOC, ZIP, etc.)

**Link classification:**

* Internal / External
* Image / Media / Download
* Anchor (#fragment)
* Mailto / Tel
* Malformed / Empty

== Installation ==

1. Upload the `linkvitals` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Tools > LinkVitals
4. Click "Start Full Scan" to begin your first audit

If uploading a zip through a hosting file manager, use `linkvitals.zip`.
After extraction, the plugin directory must be `/wp-content/plugins/linkvitals/`,
not a versioned directory such as `/wp-content/plugins/linkvitals-<version>/`.

== Frequently Asked Questions ==

= Will this slow down my website? =

No. The plugin only works in the admin area and uses a queue-based batch processing system. It never runs on the front-end or processes everything at once.

= How does batch scanning work? =

The plugin processes content in small batches (configurable, default 20 items per batch). Between batches, it yields control back to WordPress, preventing server timeouts.

= Can I pause a scan? =

Yes. You can pause and resume scans at any time from the dashboard.

= Does it check external links? =

Yes, with configurable rate limiting to avoid hammering external servers. You can also disable external link checking in settings.

= Will it delete my data on uninstall? =

Only if you enable "Delete all plugin data when plugin is uninstalled" in settings. By default, data is preserved.

= Does AI modify my posts automatically? =

No. AI suggestions are optional and only recommend an existing source page,
anchor text, placement, and reason. LinkVitals never writes those suggestions
into post content. It sends only the target title and excerpt plus up to 10
bounded candidate titles and excerpts to the provider you configure.

= How do I upgrade from a 0.2.x development build? =

The plugin folder and main file changed for the LinkVitals rebrand. Deactivate
the 0.2.x build, remove its plugin files without running its uninstall routine,
then install `linkvitals.zip` and activate LinkVitals. Do not activate both
folders at the same time. Existing `lha_*` data and settings remain compatible.

== Changelog ==

= 0.3.24 =
* Adds property-style coverage for URL normalization, extraction, queue claims, and HTTP status boundaries
* Preserves non-default HTTP ports while removing only scheme-default ports
* Resolves dot segments and query-only relative URLs against the correct source document

= 0.3.23 =
* Adds dependency-free occurrence-cleanup SQL branch coverage and unlink transformation tests
* Verifies real unlink snapshots, duplicate occurrence removal, unmatched anchors, and guarded rollback

= 0.3.22 =
* Adds dependency-free boundary coverage for settings validation and unsupported option values
* Adds SEO attribute classification coverage for mixed HTML whitespace, target casing, and insecure links
* Treats HTML whitespace consistently in rel tokens and recognizes case-insensitive _blank targets

= 0.3.21 =
* Makes stale post-occurrence cleanup safe across different WordPress and plugin table collations
* Keeps checksum verification for fixed WordPress targets without blocking latest-version integration tests on upstream checksum drift

= 0.3.20 =
* Fixes GitHub Actions workflow validation by using runner-safe WordPress test paths

= 0.3.19 =
* Preserves the last known-good occurrences when content extraction throws an exception
* Persists scanner extraction errors across bounded queue retries
* Verifies mixed successful and failed objects complete independently within one batch

= 0.3.18 =
* Verifies failed queue work returns to pending twice and reaches terminal failure on the third attempt
* Verifies retry transitions persist attempts and errors while clearing obsolete claim tokens
* Verifies stuck recovery resets only expired processing work and assigns a new token when it is reclaimed

= 0.3.17 =
* Verifies consecutive scheduled incremental scans queue only posts modified after the completed content cursor
* Verifies a newly discovered issue sends one notification while a later text-only edit retaining the same issue sends none
* Verifies existing broken links are not rechecked merely because their source post is rescanned

= 0.3.16 =
* Verifies paused scans do not advance through WP-Cron and resume to completion without losing queue state
* Verifies scheduled incremental scans with no changed content preserve scan timestamps and the completed content cursor
* Verifies no-change scheduled scans leave no notification baseline, email, or misleading start log

= 0.3.15 =
* Runs the real WP-Cron scanner through multiple one-item batches until completion in integration tests
* Verifies deterministic HTTP classification, drained queue state, completion timestamps, and content cursor promotion
* Verifies concurrent completion paths send and log a new-issue notification exactly once

= 0.3.14 =
* Provisions LinkVitals tables, settings, and queue Cron when a new site is added to a network-active installation
* Clears scan state and every parameterized AI Cron job on every site during network deactivation
* Adds multisite integration coverage for existing sites, newly created sites, and mixed per-site uninstall retention

= 0.3.13 =
* Expands real WordPress integration coverage to posts, taxonomy descriptions, and custom menu links
* Verifies duplicate occurrence storage, rescan replacement, and stale unpublished-source cleanup
* Verifies URL replacement snapshots, concurrent-edit rollback protection, and successful guarded rollback

= 0.3.12 =
* Adds real WordPress integration smoke tests for activation, schema creation, queue claims, security checks, and uninstall cleanup
* Tests WordPress 6.4 with PHP 8.0 and the latest WordPress release with PHP 8.3 in CI
* Registers the custom queue recurrence during activation so the initial queue event is scheduled reliably

= 0.3.11 =
* Adds continuous integration for PHP 8.0 and PHP 8.3 syntax and contract tests
* Verifies translation catalogs, compiled Chinese translations, and release package structure on every push and pull request

= 0.3.10 =
* Records the last scan start and successful completion as separate timestamps
* Uses the start of the last completed content scan as the safe incremental-scan cursor
* Prevents link-only rechecks and repair refreshes from advancing the content-scan cursor

= 0.3.9 =
* Sends new-issue notifications consistently when scans finish through AJAX or WP-Cron
* Prevents concurrent scan workers from sending duplicate completion emails
* Removes current notification and dynamic AI transient state during eligible single-site or multisite uninstall

= 0.3.8 =
* Removes stale link occurrences from deleted, unpublished, or no-longer-scannable source objects
* Clears old occurrences when an existing source object's content becomes empty
* Automatically removes link rows left without any source occurrences when scans start or finish

= 0.3.7 =
* Rechecks every actionable link issue through the bounded background scan pipeline instead of stopping after one batch
* Restores taxonomy-description coverage during incremental scans

= 0.3.6 =
* Fixed internal-link counts so taxonomy and menu occurrence IDs cannot be mistaken for source posts
* Isolated AI suggestion jobs by administrator to keep generated edit links within the initiating user's permissions

= 0.3.5 =
* Added opt-in AI internal-link suggestions for orphaned pages with source-page edit shortcuts
* Runs provider calls as deduplicated WP-Cron jobs with resumable admin polling instead of blocking requests
* Limits model context to server-selected candidates and rejects invented or duplicate post IDs before display
* Added encrypted OpenAI and Anthropic settings, connection tests, structured output, and current default models

= 0.3.4 =
* Made every dashboard statistic card an accessible entry point to its matching Links Report filter
* Reused the existing report-filter whitelist without adding requests or front-end assets

= 0.3.3 =
* Added verified original-image recovery for missing internal WordPress image sizes
* Added one-click row repair and sequential selected-row bulk repair without long blocking requests
* Limited automatic image repairs to internal 404 images with recognized dimension suffixes and reusable repair history

= 0.3.2 =
* Made queue batch claims atomic so concurrent AJAX and WP-Cron workers cannot process the same content objects
* Prevented scans from completing while another worker still owns pending or processing queue items

= 0.3.1 =
* Pointed project and plugin metadata to the canonical GitHub repository
* Prepared the initial public source publication under the authenticated maintainer account

= 0.3.0 =
* Rebranded the plugin and distribution identity as LinkVitals
* Changed the plugin slug, entry point, text domain, language files, and upload package to `linkvitals`
* Preserved all `LHA_*` and `lha_*` runtime identifiers so existing scan data and settings remain compatible
* Added GitHub-ready project documentation, license information, and ignore rules

= 0.2.13 =
* Updated plugin author and contributor metadata to everettlabs

= 0.2.12 =
* Added dependency-free contract tests for link resolution and type classification
* Added DOM extraction coverage for duplicate occurrences and srcset candidates

= 0.2.11 =
* Updated development documentation to describe the dependency-free PHP contract tests
* Corrected the documented test status and verification workflow

= 0.2.10 =
* Added a dependency-free PHP contract test harness for URL normalization, issue totals, and report filters
* Integrated contract tests into the lightweight development verification command

= 0.2.9 =
* Migrated legacy language settings without an explicit manual-selection marker back to Auto
* Prevented old stored Chinese language values from forcing Chinese UI on English WordPress sites
* Kept future manual English and Simplified Chinese selections persistent

= 0.2.8 =
* Added post-level edit capability checks before URL replacement, unlink, or repair rollback modifies post content
* Added release verification for repair write-permission guards

= 0.2.7 =
* Added a repair history retention setting for rolled-back repair records
* Added a dashboard maintenance action for purging old rolled-back repair history
* Wired dashboard maintenance buttons for orphan cleanup, log purge, repair-history purge, and data reset
* Added verification coverage for repair-history cleanup and maintenance-button wiring

= 0.2.6 =
* Set the plugin language default to Auto so it follows the WordPress site language
* Load Simplified Chinese automatically for Chinese site locales and English for other site locales
* Kept manual English and Simplified Chinese overrides in Settings > Language
* Clarified the rollback model: audit logs are records, while Repair History entries with content snapshots are safely reversible
* Added release verification for Auto language mode and Chinese repair-history translations

= 0.2.4 =
* Fixed the plugin language setting so `zh_CN` is preserved and existing `zh_cn` values load the Chinese language pack correctly
* Added a Repair History list under Logs with reversible records for URL replacements and unlink repairs
* Added guarded rollback for repair records, refusing automatic rollback when the post content changed after the repair
* Added release checks for repair rollback wiring and Chinese repair-history translations

= 0.2.3 =
* Treated successful redirects as optional cleanup instead of urgent repairs in the links report
* Removed the generic replacement action and repair suggestion from redirect rows while keeping one-click "Use final URL" for internal redirects

= 0.2.2 =
* Renamed the inline repair action from "Fix URL" to "Replace URL" to clarify that it edits source content instead of creating redirects
* Added row-level repair suggestions for redirects, 404 media, missing downloads, and server/domain errors
* Clarified inline replacement help text and restricted unlink actions to real anchor links

= 0.2.1 =
* Added upload-safe packaging that produces a stable-slug plugin zip
* Prevented accidental versioned upload zip names that can create versioned plugin directories
* Added release checks for nested plugin directories and workspace artifacts
* Documented the required stable plugin directory name for hosting file-manager uploads

= 0.2.0 =
* Limited "Use final URL" quick-fix to internal redirect chains (avoids replacing external affiliate redirects)
* Added stale-record handling when no source occurrences exist, with automatic cleanup
* Improved Chinese localization for new inline repair statuses and errors
* Removed confirmation/alert popups in link repair actions
* Switched inline URL repair to direct save flow (no preview-confirm popup)
* Improved AJAX error display by showing backend messages instead of generic network errors
* Added Issues, DNS Error, Server Error, and Forbidden filters to the links report
* Added DNS Errors to the dashboard summary cards
* Included Forbidden links in issue totals, rechecks, and email notifications
* Added Server Error status counts and made issue totals status-based to avoid 404/5xx double-counting
* Clamped report and export filters to supported values before querying
* Hardened unlink repair to skip unsupported occurrence object types and handle post update failures
* Fixed Python translation compilation so Chinese `.mo` output preserves UTF-8 correctly
* Improved report bulk action handling so redirects happen before page output and refreshed lists show updated data
* Expanded Chinese localization coverage for admin, maintenance, AI, and repair strings
* Improved redirect detection for equivalent internal URLs to reduce unnecessary fixes
* Applied repair state immediately after successful replace/unlink so issue counts update without re-scan
* Improved repair success messaging for cases that resolve without direct occurrence replacement
* Fixed URL replacement to support srcset image URLs
* Improved URL matching for relative/absolute URLs on the same site

= 0.1.1 =
* Initial release
* Full link scanning for posts, pages, and custom post types
* HTTP status checking with HEAD/GET fallback
* Dashboard with statistics
* Links report with filtering and search
* Internal link analysis
* Batch queue processing
* WP-Cron scheduled scans
* CSV export
* Email notifications
* Settings page

== Upgrade Notice ==

= 0.3.24 =
Improves URL normalization and relative URL resolution accuracy.

= 0.3.23 =
Adds repair unlink and occurrence cleanup regression coverage.

= 0.3.22 =
Improves SEO attribute detection and adds settings validation boundary coverage.

= 0.3.21 =
Fixes stale occurrence cleanup on installations whose WordPress and plugin tables use different collations.

= 0.3.20 =
Fixes CI startup so the real WordPress integration matrix can run.

= 0.3.19 =
Prevents transient extraction failures from erasing prior scan results and adds mixed-batch failure coverage.

= 0.3.18 =
Adds real database coverage for queue retries, terminal failures, and stale-claim recovery.

= 0.3.17 =
Adds real WordPress coverage for changed-content incremental scans and notification deltas across consecutive runs.

= 0.3.16 =
Adds real WordPress coverage for pause/resume behavior and no-change scheduled incremental scans.

= 0.3.15 =
Adds end-to-end background scan completion and notification deduplication coverage.

= 0.3.14 =
Completes multisite lifecycle handling for newly created sites, network deactivation, and per-site uninstall retention.

= 0.3.13 =
Adds end-to-end database coverage for content scanning, occurrence cleanup, and reversible repair behavior.

= 0.3.12 =
Ensures the recurring queue worker is scheduled on first activation and adds real WordPress lifecycle validation.

= 0.3.11 =
Adds automated compatibility, translation, and release-package validation for repository changes.

= 0.3.10 =
Separates scan start, completion, and incremental cursor state so interrupted scans and link-only rechecks cannot skip content changes.

= 0.3.9 =
Makes scan notifications consistent and completes plugin-state cleanup during uninstall.

= 0.3.8 =
Keeps link reports accurate when source posts, menu links, taxonomy descriptions, or their content are removed.

= 0.3.7 =
Ensures issue rechecks and incremental taxonomy scans process all applicable records.

= 0.3.6 =
Corrects orphan-page analysis and isolates background AI suggestion results between administrators.

= 0.3.5 =
Adds optional, suggestion-only AI guidance for fixing orphaned pages. Configure a provider and API key under LinkVitals Settings to enable it.

= 0.3.4 =
Makes dashboard statistics directly actionable by opening the corresponding filtered link report.

= 0.3.3 =
Adds safe, reversible repair actions for deleted WordPress image-size files when the original image is still available.

= 0.3.2 =
Improves scan reliability when AJAX and WP-Cron process the queue concurrently.

= 0.3.1 =
Publishes the canonical GitHub project location for LinkVitals.

= 0.3.0 =
Rebrands the plugin as LinkVitals. Deactivate any 0.2.x build before activating the new `linkvitals` plugin folder; existing `lha_*` data remains compatible.

= 0.2.13 =
Updates the plugin author and contributor metadata to everettlabs.

= 0.2.12 =
Expands automated coverage for the core link extraction pipeline.

= 0.2.11 =
Keeps the development handoff documentation aligned with the current contract-test workflow.

= 0.2.10 =
Adds a lightweight automated contract-test foundation for safer future development.

= 0.2.9 =
Fixes legacy language settings so English WordPress sites do not keep showing the plugin menu in Chinese after upgrade.

= 0.2.8 =
Adds stricter post-level permission checks for source-content repair actions.

= 0.2.7 =
Adds repair-history cleanup controls and fixes dashboard maintenance button wiring.

= 0.2.6 =
Defaults the plugin interface to the WordPress site language, with manual English and Simplified Chinese overrides still available.

= 0.2.4 =
Fixes Chinese language selection and adds repair history with safe rollback for source-content edits.

= 0.2.3 =
Reduces noise on redirect reports by keeping successful redirects as optional cleanup items.

= 0.2.2 =
Clarifies repair actions so administrators can distinguish source URL replacement from redirect setup.

= 0.2.1 =
Uses a safer release package name and stricter packaging checks to prevent versioned or nested plugin directories.

= 0.2.0 =
Improves repair UX, redirect handling, report filters, bulk actions, stale-record cleanup, URL replacement, and Chinese localization.

= 0.1.1 =
Initial release.
