#!/usr/bin/env python3
"""
Lightweight development verification for LinkVitals.

This script is intentionally usable on machines without a local WordPress
install. When PHP is available it also runs `php -l` over plugin PHP files.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "linkvitals"
MAIN = PLUGIN / "linkvitals.php"
README = PLUGIN / "readme.txt"
POT = PLUGIN / "languages" / "linkvitals.pot"
PO = PLUGIN / "languages" / "linkvitals-zh_CN.po"

I18N_PATTERN = re.compile(
    r"(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)"
    r"\(\s*(['\"])((?:\\.|(?!\1).)*?)\1\s*,\s*['\"]linkvitals['\"]",
    re.S,
)
MSGID_PATTERN = re.compile(r'^msgid "((?:[^"\\]|\\.)*)"', re.M)


class Reporter:
    def __init__(self) -> None:
        self.failures: list[str] = []
        self.warnings: list[str] = []

    def ok(self, message: str) -> None:
        print(f"[OK] {message}")

    def warn(self, message: str) -> None:
        self.warnings.append(message)
        print(f"[WARN] {message}")

    def fail(self, message: str) -> None:
        self.failures.append(message)
        print(f"[FAIL] {message}")

    def finish(self) -> int:
        print()
        print(f"Completed with {len(self.failures)} failure(s), {len(self.warnings)} warning(s).")
        return 1 if self.failures else 0


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def unescape_php_string(value: str) -> str:
    return (
        value.replace("\\'", "'")
        .replace('\\"', '"')
        .replace("\\$", "$")
        .replace("\\\\", "\\")
    )


def require_match(pattern: str, text: str, label: str, reporter: Reporter) -> str | None:
    match = re.search(pattern, text, re.MULTILINE)
    if not match:
        reporter.fail(f"Could not find {label}.")
        return None
    return match.group(1)


def check_workspace_shape(reporter: Reporter) -> None:
    required = [
        PLUGIN,
        MAIN,
        PLUGIN / "includes",
        PLUGIN / "assets" / "css" / "admin.css",
        PLUGIN / "assets" / "js" / "admin.js",
        PLUGIN / "languages" / "linkvitals.pot",
        PLUGIN / "languages" / "linkvitals-zh_CN.po",
        PLUGIN / "languages" / "linkvitals-zh_CN.mo",
    ]

    missing = [str(path.relative_to(ROOT)) for path in required if not path.exists()]
    if missing:
        reporter.fail("Missing required path(s): " + ", ".join(missing))
    else:
        reporter.ok("Required repository paths exist.")

    forbidden = []
    forbidden.extend(path for path in ROOT.rglob(".kiro") if path.is_dir())
    forbidden.extend(path for path in ROOT.rglob("*sync-conflict*") if path.is_file())
    if forbidden:
        reporter.fail("Forbidden artifact(s) found: " + ", ".join(str(p.relative_to(ROOT)) for p in forbidden))
    else:
        reporter.ok("No .kiro directories or sync-conflict artifacts found.")


def check_versions(reporter: Reporter) -> None:
    main_text = read_text(MAIN)
    readme_text = read_text(README)

    header_version = require_match(r"^\s*\*\s*Version:\s*([^\s]+)", main_text, "plugin header Version", reporter)
    constant_version = require_match(
        r"define\(\s*'LHA_VERSION'\s*,\s*'([^']+)'\s*\)",
        main_text,
        "LHA_VERSION constant",
        reporter,
    )
    stable_tag = require_match(r"^Stable tag:\s*([^\s]+)", readme_text, "readme Stable tag", reporter)

    found = [header_version, constant_version, stable_tag]
    if all(found) and len(set(found)) == 1:
        reporter.ok(f"Version values are in sync ({header_version}).")
    elif all(found):
        reporter.fail(
            "Version mismatch: "
            f"header={header_version}, constant={constant_version}, stable_tag={stable_tag}"
        )

    changelog_match = re.search(r"^=+\s*([0-9][^=\s]*)\s*=+\s*$", readme_text, re.MULTILINE)
    if not changelog_match:
        reporter.fail("Could not find top changelog entry in readme.txt.")
    elif changelog_match and stable_tag and changelog_match.group(1) != stable_tag:
        reporter.fail(
            "Top changelog entry does not match Stable tag "
            f"({changelog_match.group(1)} vs {stable_tag})."
        )

    upgrade_notice_match = re.search(
        r"== Upgrade Notice ==\s*\n\s*\n=+\s*([0-9][^=\s]*)\s*=+",
        readme_text,
        re.MULTILINE,
    )
    if not upgrade_notice_match:
        reporter.fail("Could not find top upgrade notice entry in readme.txt.")
    elif stable_tag and upgrade_notice_match.group(1) != stable_tag:
        reporter.fail(
            "Top upgrade notice entry does not match Stable tag "
            f"({upgrade_notice_match.group(1)} vs {stable_tag})."
        )


def check_manual_requires(reporter: Reporter) -> None:
    main_text = read_text(MAIN)
    class_files = sorted((PLUGIN / "includes").glob("class-lha-*.php"))
    missing = []

    for class_file in class_files:
        needle = f"includes/{class_file.name}"
        if needle not in main_text.replace("\\", "/"):
            missing.append(class_file.name)

    if missing:
        reporter.fail("Class file(s) missing from require_once list: " + ", ".join(missing))
    else:
        reporter.ok("All include class files are listed in the main plugin require_once block.")


def check_php_guards(reporter: Reporter) -> None:
    guarded_files = [MAIN, *sorted((PLUGIN / "includes").glob("class-lha-*.php"))]
    missing_guard = []

    for path in guarded_files:
        text = read_text(path)
        if "defined( 'ABSPATH' )" not in text and 'defined( "ABSPATH" )' not in text:
            missing_guard.append(str(path.relative_to(ROOT)))

    if missing_guard:
        reporter.fail("Missing ABSPATH guard: " + ", ".join(missing_guard))
    else:
        reporter.ok("Main plugin and include class files have ABSPATH guards.")

    uninstall_text = read_text(PLUGIN / "uninstall.php")
    if "defined( 'WP_UNINSTALL_PLUGIN' )" in uninstall_text or 'defined( "WP_UNINSTALL_PLUGIN" )' in uninstall_text:
        reporter.ok("uninstall.php is guarded by WP_UNINSTALL_PLUGIN.")
    else:
        reporter.fail("uninstall.php is missing WP_UNINSTALL_PLUGIN guard.")


def check_translations(reporter: Reporter) -> None:
    mo = PLUGIN / "languages" / "linkvitals-zh_CN.mo"

    if mo.stat().st_mtime + 1 >= PO.stat().st_mtime:
        reporter.ok("Chinese .mo file is at least as fresh as the .po file.")
    else:
        reporter.fail("Chinese .mo file is older than the .po file; run python generate-mo.py.")

    pot_text = read_text(POT)
    if '"X-Generator: Manual\\n"' in pot_text:
        reporter.ok("POT generator metadata is manual.")
    else:
        reporter.warn("POT generator metadata is not marked Manual.")

    required_msgids = [
        "View Report",
        "Link ignored by bulk action",
        "Link unignored by bulk action",
        "DNS Errors: %8$d",
        "Server Errors: %6$d",
        "Forbidden: %10$d",
    ]
    combined_catalog = read_text(POT) + "\n" + read_text(PO)
    missing = [msgid for msgid in required_msgids if msgid not in combined_catalog]
    if missing:
        reporter.fail("Expected translation message(s) missing: " + ", ".join(missing))
    else:
        reporter.ok("Recently added translation messages are present.")

    source_msgids: set[str] = set()
    for path in sorted((PLUGIN / "includes").glob("*.php")) + [MAIN]:
        text = read_text(path)
        for match in I18N_PATTERN.finditer(text):
            source_msgids.add(unescape_php_string(match.group(2)))

    pot_msgids = {unescape_php_string(match.group(1)) for match in MSGID_PATTERN.finditer(read_text(POT))}
    po_msgids = {unescape_php_string(match.group(1)) for match in MSGID_PATTERN.finditer(read_text(PO))}

    missing_pot = sorted(source_msgids - pot_msgids)
    missing_po = sorted(source_msgids - po_msgids)
    if missing_pot or missing_po:
        details = []
        if missing_pot:
            details.append("POT missing: " + "; ".join(missing_pot[:10]))
        if missing_po:
            details.append("PO missing: " + "; ".join(missing_po[:10]))
        reporter.fail("Translation catalog is missing source msgid(s). Run python tools/i18n-sync.py. " + " | ".join(details))
    else:
        reporter.ok(f"Translation catalogs include all {len(source_msgids)} source msgid(s).")

    try:
        mo_translations = read_mo_translations(mo)
    except ValueError as exc:
        reporter.fail(str(exc))
        return

    expected_mo_strings = {
        "Dashboard": "仪表盘",
        "LinkVitals": "LinkVitals",
        "Recheck Link Issues": "重新检测链接问题",
        "Repair History": "修复记录",
        "Rollback": "回退",
        "Auto (site language)": "自动（站点语言）",
        "Purge Old Repair History": "清理旧修复记录",
    }
    bad_mo_strings = [
        msgid
        for msgid, expected in expected_mo_strings.items()
        if mo_translations.get(msgid) != expected
    ]
    if bad_mo_strings:
        reporter.fail("Chinese .mo contains corrupted or missing key translation(s): " + ", ".join(bad_mo_strings))
    else:
        reporter.ok("Chinese .mo key translations decode correctly.")


def read_mo_translations(path: Path) -> dict[str, str]:
    data = path.read_bytes()
    if len(data) < 28:
        raise ValueError("Chinese .mo file is too small to be valid.")

    magic, _revision, count, original_offset, translated_offset, _hash_size, _hash_offset = (
        int.from_bytes(data[index:index + 4], "little")
        for index in range(0, 28, 4)
    )
    if magic != 0x950412DE:
        raise ValueError("Chinese .mo file has an invalid magic number.")

    translations: dict[str, str] = {}
    for index in range(count):
        original_entry = original_offset + index * 8
        translated_entry = translated_offset + index * 8
        original_length = int.from_bytes(data[original_entry:original_entry + 4], "little")
        original_string_offset = int.from_bytes(data[original_entry + 4:original_entry + 8], "little")
        translated_length = int.from_bytes(data[translated_entry:translated_entry + 4], "little")
        translated_string_offset = int.from_bytes(data[translated_entry + 4:translated_entry + 8], "little")
        msgid = data[original_string_offset:original_string_offset + original_length].decode("utf-8")
        msgstr = data[translated_string_offset:translated_string_offset + translated_length].decode("utf-8")
        translations[msgid] = msgstr

    return translations


def check_report_filters(reporter: Reporter) -> None:
    db_text = read_text(PLUGIN / "includes" / "class-lha-db.php")
    admin_text = read_text(PLUGIN / "includes" / "class-lha-admin.php")
    list_table_text = read_text(PLUGIN / "includes" / "class-lha-list-table.php")

    required_issue_statuses = ["broken", "server_error", "timeout", "ssl_error", "dns_error", "forbidden"]
    missing_statuses = [
        status
        for status in required_issue_statuses
        if f"'{status}'" not in db_text and f'"{status}"' not in db_text
    ]
    if missing_statuses:
        reporter.fail("Issue status helper is missing status(es): " + ", ".join(missing_statuses))
    elif "get_issue_statuses" in db_text and "get_broken_links" in db_text and "'server_error' => 0" in db_text:
        reporter.ok("Issue statuses are centralized for issue counts and rechecks.")
    else:
        reporter.fail("Could not find centralized issue status helper usage.")

    issue_total_match = re.search(
        r"function\s+get_issue_total_from_stats\s*\([^)]*\)\s*:\s*int\s*\{(?P<body>.*?)\n\s*\}",
        db_text,
        re.S,
    )
    if not issue_total_match:
        reporter.fail("Could not inspect get_issue_total_from_stats().")
    else:
        body = issue_total_match.group("body")
        if "code_404" in body or "code_5xx" in body:
            reporter.fail("Issue total uses HTTP-code-derived counts; it should use status counts only.")
        elif "server_error" in body and "forbidden" in body:
            reporter.ok("Issue total uses status-derived counts without 404/5xx double-counting.")
        else:
            reporter.fail("Issue total is missing server_error or forbidden status counts.")

    required_filter_keys = [
        "issues",
        "broken",
        "404",
        "5xx",
        "server_error",
        "redirect",
        "timeout",
        "ssl_error",
        "dns_error",
        "forbidden",
        "internal",
        "external",
        "image",
        "anchor",
        "ignored",
    ]
    missing_filters = [
        key
        for key in required_filter_keys
        if f"'{key}'" not in db_text or f"'{key}'" not in admin_text
    ]
    if missing_filters:
        reporter.fail("Report filter key(s) missing from DB/admin code: " + ", ".join(missing_filters))
    else:
        reporter.ok("Report filter keys are present in both DB and admin code.")

    sanitizer_uses = (
        admin_text.count("sanitize_report_filter_key")
        + list_table_text.count("sanitize_report_filter_key")
    )
    if "function sanitize_report_filter_key" in db_text and sanitizer_uses >= 3:
        reporter.ok("Report filter input is clamped through LHA_DB::sanitize_report_filter_key().")
    else:
        reporter.fail("Report filter input is not consistently clamped through sanitize_report_filter_key().")


def check_repair_safety(reporter: Reporter) -> None:
    repair_text = read_text(PLUGIN / "includes" / "class-lha-repair.php")
    admin_text = read_text(PLUGIN / "includes" / "class-lha-admin.php")
    db_text = read_text(PLUGIN / "includes" / "class-lha-db.php")

    if "is_supported_post_object_type" not in repair_text:
        reporter.fail("LHA_Repair is missing a shared supported-object guard.")
        return

    unlink_match = re.search(r"public function unlink\s*\([^)]*\)\s*:\s*array\s*\{(?P<body>.*?)(?=\n    /\*\*)", repair_text, re.S)
    if not unlink_match:
        reporter.fail("Could not inspect LHA_Repair::unlink().")
        return

    unlink_body = unlink_match.group("body")
    if "is_supported_post_object_type" not in unlink_body:
        reporter.fail("LHA_Repair::unlink() does not guard occurrence object types before get_post().")
    elif "is_wp_error" not in unlink_body:
        reporter.fail("LHA_Repair::unlink() does not handle wp_update_post() errors.")
    else:
        reporter.ok("LHA_Repair::unlink() guards object types and handles update errors.")

    repair_history_checks = {
        "repairs table": "CREATE TABLE {$table_repairs}" in db_text,
        "repair insert": "insert_repair" in db_text and "record_repair" in repair_text,
        "rollback content guard": "hash_equals" in repair_text and "The content has changed since this repair was recorded" in repair_text,
        "rollback ajax": "lha_rollback_repair" in admin_text,
        "repair history cleanup": "cleanup_repair_history" in db_text and "status = %s" in db_text and "'rolled_back'" in db_text,
        "repair edit capability guard": (
            "current_user_can( 'edit_post', $post->ID )" in repair_text
            and repair_text.count("can_edit_post_content( $post )") >= 3
        ),
    }
    missing = [name for name, ok in repair_history_checks.items() if not ok]
    if missing:
        reporter.fail("Repair history/rollback check(s) missing: " + ", ".join(missing))
    else:
        reporter.ok("Repair history records reversible snapshots and exposes guarded rollback.")


def check_maintenance_actions(reporter: Reporter) -> None:
    admin_text = read_text(PLUGIN / "includes" / "class-lha-admin.php")
    js_text = read_text(PLUGIN / "assets" / "js" / "admin.js")

    required_actions = {
        "lha_cleanup_orphans": "lha-btn-cleanup-orphans",
        "lha_purge_logs": "lha-btn-purge-logs",
        "lha_purge_repairs": "lha-btn-purge-repairs",
        "lha_reset_data": "lha-btn-reset-data",
    }

    missing = []
    for action, button_id in required_actions.items():
        if action not in admin_text:
            missing.append(f"admin action {action}")
        if button_id not in admin_text:
            missing.append(f"admin button {button_id}")
        if action not in js_text or button_id not in js_text:
            missing.append(f"JS binding {button_id}/{action}")

    if missing:
        reporter.fail("Maintenance action wiring is incomplete: " + ", ".join(missing))
    else:
        reporter.ok("Dashboard maintenance buttons are wired to AJAX actions.")

    if "confirmation" in admin_text and "RESET" in admin_text and "reset_data_prompt" in js_text:
        reporter.ok("Reset data requires an explicit confirmation token.")
    else:
        reporter.fail("Reset data is missing the explicit RESET confirmation guard.")


def check_language_settings(reporter: Reporter) -> None:
    main_text = read_text(MAIN)
    settings_text = read_text(PLUGIN / "includes" / "class-lha-settings.php")
    activator_text = read_text(PLUGIN / "includes" / "class-lha-activator.php")

    if "sanitize_key( $input['language']" in settings_text:
        reporter.fail("Language setting is passed through sanitize_key(), which lowercases locales like zh_CN.")
        return

    if "normalize_language" not in settings_text or "'zh_CN'" not in settings_text:
        reporter.fail("Settings are missing locale-preserving language normalization.")
        return

    if "'zh_cn' === $normalized_language" not in main_text:
        reporter.fail("Textdomain loading does not accept stored lowercase zh_cn values.")
        return

    if "'language'               => 'auto'" not in activator_text:
        reporter.fail("Default language is not set to auto in activation defaults.")
        return

    legacy_migration_checks = [
        "migrate_legacy_language_setting" in main_text,
        "language_manually_selected" in main_text,
        "update_option( 'lha_settings', $settings )" in main_text,
        "'language_manually_selected' => 0" in activator_text,
        "$sanitized['language_manually_selected'] = 1" in settings_text,
    ]
    if not all(legacy_migration_checks):
        reporter.fail("Language setting is missing the legacy-to-auto migration marker flow.")
        return

    if "get_locale()" not in main_text or "str_starts_with( $site_locale, 'zh' ) ? 'zh_cn' : 'en_us'" not in main_text:
        reporter.fail("Auto language mode does not follow the WordPress site locale.")
        return

    if "Auto (site language)" not in settings_text:
        reporter.fail("Settings page is missing the Auto (site language) option.")
        return

    reporter.ok("Language defaults to auto, follows the WordPress site locale, and preserves zh_CN values.")


def check_release_zip(reporter: Reporter, version: str | None) -> None:
    if not version:
        reporter.warn("Could not determine version; skipped release zip freshness check.")
        return

    zip_path = ROOT / "linkvitals.zip"
    if not zip_path.exists():
        reporter.fail(f"Release zip missing: {zip_path.name}")
        return

    versioned_root_zips = sorted(ROOT.glob("linkvitals-[0-9]*.zip"))
    if versioned_root_zips:
        reporter.fail(
            "Versioned upload zip(s) found in repo root; use linkvitals.zip for uploads: "
            + ", ".join(path.name for path in versioned_root_zips)
        )
    else:
        reporter.ok("No versioned upload zip is present in the repo root.")

    source_files = [path for path in PLUGIN.rglob("*") if path.is_file()]
    latest_source = max(path.stat().st_mtime for path in source_files)
    if zip_path.stat().st_mtime + 1 >= latest_source:
        reporter.ok(f"Release zip {zip_path.name} is at least as fresh as plugin source.")
    else:
        reporter.fail(f"Release zip {zip_path.name} is older than plugin source; rebuild it.")

    try:
        with zipfile.ZipFile(zip_path) as archive:
            names = [name.replace("\\", "/") for name in archive.namelist() if name and not name.endswith("/")]
    except zipfile.BadZipFile:
        reporter.fail(f"Release zip {zip_path.name} is not a valid zip archive.")
        return

    top_levels = {name.split("/", 1)[0] for name in names}
    if top_levels == {"linkvitals"}:
        reporter.ok("Release zip has the expected plugin top-level directory.")
    else:
        reporter.fail(
            "Release zip has unexpected top-level entries: "
            + ", ".join(sorted(top_levels))
        )

    if "linkvitals/linkvitals.php" in names:
        reporter.ok("Release zip contains the main plugin file at the install root.")
    else:
        reporter.fail("Release zip does not contain linkvitals/linkvitals.php.")

    nested_plugin_files = [
        name for name in names if name.startswith("linkvitals/linkvitals/")
    ]
    workspace_files = [
        name
        for name in names
        if name in {
            "linkvitals/AGENTS.md",
            "linkvitals/CLAUDE.md",
            "linkvitals/generate-mo.py",
            "linkvitals/generate-mo.php",
        }
        or name.startswith("linkvitals/tools/")
        or name.endswith(".zip")
    ]
    if nested_plugin_files or workspace_files:
        details = nested_plugin_files[:5] + workspace_files[:5]
        reporter.fail("Release zip contains nested plugin or workspace artifact(s): " + ", ".join(details))
    else:
        reporter.ok("Release zip does not contain nested plugin directories or workspace artifacts.")


def normalize_url_like_plugin(url: str) -> str:
    value = url.strip().lower()
    value = value.split("#", 1)[0]
    if not re.match(r"^https?://[^/]+/$", value):
        value = value.rstrip("/")
    value = re.sub(r":(80|443)(?=/|$)", "", value)
    value = re.sub(r"^(https?://)www\.", r"\1", value)
    return value


def check_documented_invariants(reporter: Reporter) -> None:
    samples = [
        (" HTTPS://WWW.Example.COM:443/Path/?A=1#frag ", "https://example.com/path/?a=1"),
        ("http://www.example.com:80/", "http://example.com/"),
        ("https://example.com/path///", "https://example.com/path"),
    ]

    for raw, expected in samples:
        actual = normalize_url_like_plugin(raw)
        twice = normalize_url_like_plugin(actual)
        if actual != expected or twice != actual:
            reporter.fail(f"Normalization invariant failed for {raw!r}: got {actual!r}, expected {expected!r}.")
            return

    reporter.ok("Documented URL normalization examples are idempotent.")


def run_optional_php_lint(reporter: Reporter) -> None:
    php = shutil.which("php")
    if not php:
        reporter.warn("PHP CLI not found; skipped php -l syntax checks.")
        return

    php_files = (
        sorted(PLUGIN.rglob("*.php"))
        + sorted((ROOT / "tests").rglob("*.php"))
        + [ROOT / "generate-mo.php"]
    )
    failed = []

    for path in php_files:
        result = subprocess.run(
            [php, "-l", str(path)],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
        if result.returncode != 0:
            failed.append(f"{path.relative_to(ROOT)}: {result.stdout.strip()}")

    if failed:
        reporter.fail("PHP lint failed:\n" + "\n".join(failed))
    else:
        reporter.ok(f"PHP lint passed for {len(php_files)} file(s).")


def run_optional_php_tests(reporter: Reporter) -> None:
    php = shutil.which("php")
    if not php:
        reporter.warn("PHP CLI not found; skipped dependency-free PHP tests.")
        return

    test_runner = ROOT / "tests" / "run.php"
    result = subprocess.run(
        [php, str(test_runner)],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )

    if result.returncode != 0:
        reporter.fail("PHP contract tests failed:\n" + result.stdout.strip())
    else:
        summary = result.stdout.strip().splitlines()[-1]
        reporter.ok(f"PHP contract tests passed ({summary})")


def main() -> int:
    reporter = Reporter()
    main_text = read_text(MAIN)
    version = require_match(
        r"define\(\s*'LHA_VERSION'\s*,\s*'([^']+)'\s*\)",
        main_text,
        "LHA_VERSION constant",
        reporter,
    )

    check_workspace_shape(reporter)
    check_versions(reporter)
    check_manual_requires(reporter)
    check_php_guards(reporter)
    check_translations(reporter)
    check_report_filters(reporter)
    check_repair_safety(reporter)
    check_maintenance_actions(reporter)
    check_language_settings(reporter)
    check_documented_invariants(reporter)
    check_release_zip(reporter, version)
    run_optional_php_lint(reporter)
    run_optional_php_tests(reporter)

    return reporter.finish()


if __name__ == "__main__":
    sys.exit(main())
