#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
快速检查版本号是否同步的工具脚本。

比 dev-verify.py 更轻量，只关注版本一致性。
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


# 设置 UTF-8 输出（Windows 兼容）
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')


ROOT = Path(__file__).resolve().parents[1]
MAIN_FILE = ROOT / "linkvitals" / "linkvitals.php"
README_FILE = ROOT / "linkvitals" / "readme.txt"


def extract_version(pattern: str, text: str, label: str) -> str | None:
    match = re.search(pattern, text, re.MULTILINE)
    if not match:
        print(f"[FAIL] 未找到 {label}")
        return None
    return match.group(1)


def main() -> int:
    main_text = MAIN_FILE.read_text(encoding="utf-8")
    readme_text = README_FILE.read_text(encoding="utf-8")

    versions = {
        "Plugin Header": extract_version(
            r"^\s*\*\s*Version:\s*([^\s]+)", main_text, "Plugin Header Version"
        ),
        "LHA_VERSION": extract_version(
            r"define\(\s*'LHA_VERSION'\s*,\s*'([^']+)'\s*\)",
            main_text,
            "LHA_VERSION constant",
        ),
        "Stable Tag": extract_version(
            r"^Stable tag:\s*([^\s]+)", readme_text, "Stable tag"
        ),
    }

    if None in versions.values():
        return 1

    unique_versions = set(versions.values())

    if len(unique_versions) == 1:
        version = list(unique_versions)[0]
        print(f"[OK] 版本号一致: {version}")
        print()
        print("位置检查:")
        print(f"  - Plugin Header: {versions['Plugin Header']}")
        print(f"  - LHA_VERSION:   {versions['LHA_VERSION']}")
        print(f"  - Stable Tag:    {versions['Stable Tag']}")
        return 0
    else:
        print("[FAIL] 版本号不一致！")
        print()
        for location, version in versions.items():
            print(f"  - {location}: {version}")
        print()
        print("请同步更新以下 5 个位置的版本号：")
        print("  1. linkvitals/linkvitals.php - Version: 头部")
        print("  2. linkvitals/linkvitals.php - LHA_VERSION 常量")
        print("  3. linkvitals/readme.txt - Stable tag")
        print("  4. linkvitals/readme.txt - Changelog 顶部条目")
        print("  5. linkvitals/readme.txt - Upgrade Notice 顶部条目")
        return 1


if __name__ == "__main__":
    sys.exit(main())
