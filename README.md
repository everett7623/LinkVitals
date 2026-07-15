# LinkVitals

LinkVitals is a privacy-friendly WordPress link health and SEO auditor. It
scans site content for broken links, redirects, timeouts, SSL and DNS errors,
orphaned pages, invalid anchors, and external-link SEO risks without adding a
front-end footprint.

## Features

- Audits posts, pages, custom post types, menus, taxonomy descriptions,
  excerpts, media URLs, and WooCommerce product galleries.
- Checks internal and external links with queue-based batches and per-domain
  rate limiting.
- Reports broken links, redirects, 404/5xx responses, timeouts, SSL/DNS
  failures, forbidden responses, and ignored URLs.
- Analyzes internal links, orphaned content, fragments, and external-link SEO
  attributes.
- Supports CSV export, scheduled scans, email notifications, repair history,
  and guarded rollback.
- Includes optional OpenAI or Anthropic replacement suggestions.
- Loads no assets, queries, or heavy hooks on public site requests.

## Requirements

- WordPress 6.4 or later
- PHP 8.0 or later

## Installation

1. Download `linkvitals.zip` from the GitHub Releases page.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the zip, activate LinkVitals, then open **Tools → LinkVitals**.
4. Select **Start Full Scan**.

The upload archive must contain one top-level `linkvitals/` directory with
`linkvitals.php` directly inside it.

## Development

The repository root contains development, test, translation, and packaging
tools. The installable plugin source is under [`linkvitals/`](linkvitals/).

```shell
python tools/dev-verify.py
php tests/run.php
python tools/i18n-sync.py
python generate-mo.py
python tools/package-release.py
```

`python tools/dev-verify.py` also runs PHP syntax checks and the dependency-free
contract suite when PHP is available.

## Compatibility

The LinkVitals distribution uses the `linkvitals` plugin slug and text domain.
Internal `LHA_*` classes and `lha_*` database tables, options, AJAX actions,
nonces, and cron hooks are intentionally retained so data created by 0.2.x
development builds remains compatible.

Do not activate a 0.2.x plugin folder and `linkvitals/` at the same time because
both distributions share those internal identifiers.

## Privacy

Link checking runs from the WordPress server. LinkVitals does not add
front-end tracking. Optional AI requests occur only when an administrator
configures a provider key and invokes the related feature.

## Contributing

Issues and pull requests are welcome at
[`everett7623/LinkVitals`](https://github.com/everett7623/LinkVitals).
Please run `python tools/dev-verify.py` before submitting changes.

## License

LinkVitals is licensed under the GNU General Public License v2.0 or later. See
[`LICENSE`](LICENSE).
