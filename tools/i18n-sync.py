#!/usr/bin/env python3
"""
Synchronize source translation msgids into the manual POT/PO catalogs.

This does not translate strings. It appends any source msgid that is missing
from the catalog so translators can fill it later and dev verification can
catch future drift without requiring WP-CLI.
"""

from __future__ import annotations

import re
from collections import defaultdict
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "linkvitals"
POT = PLUGIN / "languages" / "linkvitals.pot"
PO = PLUGIN / "languages" / "linkvitals-zh_CN.po"

I18N_PATTERN = re.compile(
    r"(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)"
    r"\(\s*(['\"])((?:\\.|(?!\1).)*?)\1\s*,\s*['\"]linkvitals['\"]",
    re.S,
)
MSGID_PATTERN = re.compile(r'^msgid "((?:[^"\\]|\\.)*)"', re.M)


def unescape_php_string(value: str) -> str:
    return (
        value.replace("\\'", "'")
        .replace('\\"', '"')
        .replace("\\$", "$")
        .replace("\\\\", "\\")
    )


def escape_po_string(value: str) -> str:
    return (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
    )


def source_msgids() -> dict[str, list[str]]:
    references: dict[str, list[str]] = defaultdict(list)
    paths = sorted((PLUGIN / "includes").glob("*.php")) + [PLUGIN / "linkvitals.php"]

    for path in paths:
        text = path.read_text(encoding="utf-8")
        rel = path.relative_to(PLUGIN).as_posix()
        for match in I18N_PATTERN.finditer(text):
            msgid = unescape_php_string(match.group(2))
            if not msgid:
                continue
            line = text[: match.start()].count("\n") + 1
            references[msgid].append(f"{rel}:{line}")

    return dict(sorted(references.items(), key=lambda item: item[0].lower()))


def catalog_msgids(path: Path) -> set[str]:
    text = path.read_text(encoding="utf-8")
    return {unescape_php_string(match.group(1)) for match in MSGID_PATTERN.finditer(text)}


def append_missing(path: Path, references: dict[str, list[str]]) -> int:
    existing = catalog_msgids(path)
    missing = {msgid: refs for msgid, refs in references.items() if msgid not in existing}
    if not missing:
        return 0

    blocks = ["", "# Source strings synced by tools/i18n-sync.py"]
    include_refs = path.suffix == ".pot"

    for msgid, refs in missing.items():
        blocks.append("")
        if include_refs:
            for ref in refs:
                blocks.append(f"#: {ref}")
        blocks.append(f'msgid "{escape_po_string(msgid)}"')
        blocks.append('msgstr ""')

    text = path.read_text(encoding="utf-8").rstrip() + "\n" + "\n".join(blocks) + "\n"
    path.write_text(text, encoding="utf-8")
    return len(missing)


def main() -> int:
    references = source_msgids()
    pot_count = append_missing(POT, references)
    po_count = append_missing(PO, references)
    print(f"Source msgids: {len(references)}")
    print(f"POT appended: {pot_count}")
    print(f"PO appended: {po_count}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
