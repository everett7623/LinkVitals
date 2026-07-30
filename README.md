# LinkVitals

[![CI](https://github.com/everett7623/LinkVitals/actions/workflows/ci.yml/badge.svg)](https://github.com/everett7623/LinkVitals/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)

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

### Quick Start

```bash
# Verify code quality (run before committing)
python tools/dev-verify.py

# Run contract tests
php tests/run.php

# Sync and compile translations
python tools/i18n-sync.py
python generate-mo.py

# Build release package
python tools/package-release.py
```

### Repository Structure

The repository root contains development, test, translation, and packaging
tools. The installable plugin source is under [`linkvitals/`](linkvitals/).

```
LinkVitals/
├── linkvitals/              # Installable WordPress plugin
│   ├── linkvitals.php       # Main plugin file
│   ├── includes/            # LHA_* classes
│   ├── assets/              # Admin CSS/JS
│   └── languages/           # Translation files
├── tools/                   # Development scripts
│   ├── dev-verify.py        # Code quality checker
│   ├── i18n-sync.py         # Translation sync
│   └── package-release.py   # Release builder
├── tests/                   # Test suites
│   ├── run.php              # Contract tests
│   └── integration/         # WordPress integration tests
├── AGENTS.md                # Complete development guide
├── CLAUDE.md                # Quick reference
└── linkvitals.zip           # Release artifact
```

### Documentation

- **[AGENTS.md](AGENTS.md)** - Complete development guide for AI coding assistants
- **[CLAUDE.md](CLAUDE.md)** - Quick reference and command cheat sheet

### Version Management

When updating source code, synchronize version numbers in all 5 locations:

1. `linkvitals/linkvitals.php` - `Version:` header
2. `linkvitals/linkvitals.php` - `LHA_VERSION` constant
3. `linkvitals/readme.txt` - `Stable tag`
4. `linkvitals/readme.txt` - Top `Changelog` entry
5. `linkvitals/readme.txt` - Top `Upgrade Notice` entry

Run `python tools/dev-verify.py` to verify version consistency.

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

Quick contribution checklist:
- Follow WordPress coding standards
- Add tests for new features
- Update translations if needed
- Run `python tools/dev-verify.py` before submitting

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
