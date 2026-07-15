#!/usr/bin/env python3
"""
Build a WordPress-installable release zip for LinkVitals.

The repository root is a development workspace; only the plugin directory should
be archived. This script deliberately creates the upload-safe package:

    linkvitals.zip
        linkvitals/
            linkvitals.php
            ...

The zip filename intentionally omits the version. Some hosting file managers
extract archives into a folder named after the zip file, so a versioned archive
name can leave wp-content/plugins/linkvitals-<version>/ on the server.
WordPress plugin directories should keep the stable slug:
wp-content/plugins/linkvitals/.

The script also rejects common accidental package shapes, such as nesting the
plugin inside another linkvitals directory or including repository
files.
"""

from __future__ import annotations

import argparse
import re
import sys
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_SLUG = "linkvitals"
PLUGIN = ROOT / PLUGIN_SLUG
MAIN = PLUGIN / f"{PLUGIN_SLUG}.php"
README = PLUGIN / "readme.txt"

EXCLUDED_FILENAMES = {".DS_Store", "Thumbs.db"}
EXCLUDED_SUFFIXES = {".pyc", ".pyo"}
EXCLUDED_PARTS = {"__pycache__", ".git", ".svn", ".hg"}


def read_version() -> str:
    text = MAIN.read_text(encoding="utf-8")
    readme_text = README.read_text(encoding="utf-8")
    header_match = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", text, re.MULTILINE)
    constant_match = re.search(r"define\(\s*'LHA_VERSION'\s*,\s*'([^']+)'\s*\)", text)
    stable_match = re.search(r"^Stable tag:\s*([^\s]+)", readme_text, re.MULTILINE)
    changelog_match = re.search(r"^=+\s*([0-9][^=\s]*)\s*=+\s*$", readme_text, re.MULTILINE)
    upgrade_notice_match = re.search(
        r"== Upgrade Notice ==\s*\n\s*\n=+\s*([0-9][^=\s]*)\s*=+",
        readme_text,
        re.MULTILINE,
    )

    if not header_match or not constant_match or not stable_match or not changelog_match or not upgrade_notice_match:
        raise RuntimeError(
            "Could not read plugin header Version, LHA_VERSION, Stable tag, "
            "top changelog entry, and top upgrade notice entry."
        )

    header_version = header_match.group(1)
    constant_version = constant_match.group(1)
    stable_tag = stable_match.group(1)
    changelog_version = changelog_match.group(1)
    upgrade_notice_version = upgrade_notice_match.group(1)
    if len({header_version, constant_version, stable_tag, changelog_version, upgrade_notice_version}) != 1:
        raise RuntimeError(
            "Version mismatch: "
            f"plugin header is {header_version}, "
            f"LHA_VERSION is {constant_version}, "
            f"Stable tag is {stable_tag}, "
            f"top changelog entry is {changelog_version}, "
            f"top upgrade notice entry is {upgrade_notice_version}."
        )

    return header_version


def should_skip(path: Path) -> bool:
    parts = set(path.parts)
    return (
        bool(parts & EXCLUDED_PARTS)
        or path.name in EXCLUDED_FILENAMES
        or path.suffix in EXCLUDED_SUFFIXES
    )


def collect_plugin_files() -> list[Path]:
    if not MAIN.exists():
        raise RuntimeError(f"Missing plugin main file: {MAIN}")

    files = [
        path
        for path in sorted(PLUGIN.rglob("*"))
        if path.is_file() and not should_skip(path.relative_to(PLUGIN))
    ]

    nested_main = PLUGIN / PLUGIN_SLUG / f"{PLUGIN_SLUG}.php"
    if nested_main in files:
        raise RuntimeError(f"Refusing to package nested plugin copy: {nested_main}")

    if MAIN not in files:
        raise RuntimeError("Collected files do not include the plugin main file.")

    return files


def archive_name(path: Path) -> str:
    return f"{PLUGIN_SLUG}/{path.relative_to(PLUGIN).as_posix()}"


def validate_zip(zip_path: Path) -> None:
    with zipfile.ZipFile(zip_path) as archive:
        names = [name.replace("\\", "/") for name in archive.namelist() if name and not name.endswith("/")]

    if not names:
        raise RuntimeError(f"{zip_path.name} is empty.")

    top_levels = {name.split("/", 1)[0] for name in names}
    if top_levels != {PLUGIN_SLUG}:
        raise RuntimeError(
            f"{zip_path.name} has unexpected top-level entries: {', '.join(sorted(top_levels))}"
        )

    expected_main = f"{PLUGIN_SLUG}/{PLUGIN_SLUG}.php"
    if expected_main not in names:
        raise RuntimeError(f"{zip_path.name} is missing {expected_main}.")

    forbidden = [
        name
        for name in names
        if name.startswith(f"{PLUGIN_SLUG}/{PLUGIN_SLUG}/")
        or name in {f"{PLUGIN_SLUG}/AGENTS.md", f"{PLUGIN_SLUG}/CLAUDE.md"}
        or name.startswith(f"{PLUGIN_SLUG}/tools/")
        or name.startswith(f"{PLUGIN_SLUG}/.git/")
        or name.endswith(".zip")
    ]
    if forbidden:
        raise RuntimeError(
            f"{zip_path.name} contains non-release or nested file(s): "
            + ", ".join(forbidden[:10])
        )


def build_zip(output: Path) -> None:
    files = collect_plugin_files()
    temp_path = output.with_suffix(output.suffix + ".tmp")

    if temp_path.exists():
        temp_path.unlink()

    try:
        with zipfile.ZipFile(temp_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path in files:
                archive.write(path, archive_name(path))

        validate_zip(temp_path)
        temp_path.replace(output)
    finally:
        if temp_path.exists():
            temp_path.unlink()

    print(f"Built {output.relative_to(ROOT)} with {len(files)} file(s).")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build the plugin release zip.")
    parser.add_argument(
        "--output",
        type=Path,
        help="Optional output zip path. Defaults to linkvitals.zip in the repo root.",
    )
    parser.add_argument(
        "--allow-versioned-filename",
        action="store_true",
        help=(
            "Allow an output filename like linkvitals-<version>.zip. "
            "Use only for archival copies, not for server file-manager uploads."
        ),
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    try:
        read_version()
        output = args.output or ROOT / f"{PLUGIN_SLUG}.zip"
        if not output.is_absolute():
            output = (ROOT / output).resolve()

        if output.suffix.lower() != ".zip":
            raise RuntimeError("Output path must end in .zip.")

        versioned_name = re.fullmatch(rf"{re.escape(PLUGIN_SLUG)}-\d+(?:\.\d+)*(?:[-\w.]*)?\.zip", output.name)
        if versioned_name and not args.allow_versioned_filename:
            raise RuntimeError(
                "Refusing to create a versioned upload filename. "
                f"Use {PLUGIN_SLUG}.zip for WordPress uploads, or pass "
                "--allow-versioned-filename only for archival copies."
            )

        output.parent.mkdir(parents=True, exist_ok=True)
        build_zip(output)
    except Exception as exc:
        print(f"Packaging failed: {exc}", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
