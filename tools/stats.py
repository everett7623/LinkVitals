#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
快速统计项目信息的工具。
"""

from __future__ import annotations

import sys
from pathlib import Path


# 设置 UTF-8 输出（Windows 兼容）
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "linkvitals"


def count_lines(file_path: Path) -> int:
    """计算文件行数"""
    try:
        return len(file_path.read_text(encoding='utf-8').splitlines())
    except Exception:
        return 0


def main() -> int:
    print("=" * 50)
    print("LinkVitals 项目统计")
    print("=" * 50)
    print()

    # 统计 PHP 文件
    php_files = list(PLUGIN.rglob("*.php"))
    php_lines = sum(count_lines(f) for f in php_files)

    # 统计 JS 文件
    js_files = list((PLUGIN / "assets" / "js").glob("*.js")) if (PLUGIN / "assets" / "js").exists() else []
    js_lines = sum(count_lines(f) for f in js_files)

    # 统计 CSS 文件
    css_files = list((PLUGIN / "assets" / "css").glob("*.css")) if (PLUGIN / "assets" / "css").exists() else []
    css_lines = sum(count_lines(f) for f in css_files)

    # 统计类文件
    class_files = list((PLUGIN / "includes").glob("class-lha-*.php")) if (PLUGIN / "includes").exists() else []

    # 统计测试文件
    test_files = list((ROOT / "tests").rglob("*.php")) if (ROOT / "tests").exists() else []
    test_lines = sum(count_lines(f) for f in test_files)

    # 统计文档
    doc_files = list(ROOT.glob("*.md"))
    doc_lines = sum(count_lines(f) for f in doc_files)

    # 统计工具脚本
    tool_files = list((ROOT / "tools").glob("*.py")) if (ROOT / "tools").exists() else []

    print("代码统计")
    print("-" * 50)
    print(f"  PHP 文件:        {len(php_files):3d} 个  ({php_lines:,} 行)")
    print(f"  JavaScript 文件: {len(js_files):3d} 个  ({js_lines:,} 行)")
    print(f"  CSS 文件:        {len(css_files):3d} 个  ({css_lines:,} 行)")
    print(f"  类文件:          {len(class_files):3d} 个")
    print()

    print("测试覆盖")
    print("-" * 50)
    print(f"  测试文件:        {len(test_files):3d} 个  ({test_lines:,} 行)")
    print()

    print("文档")
    print("-" * 50)
    print(f"  Markdown 文件:   {len(doc_files):3d} 个  ({doc_lines:,} 行)")
    for doc in sorted(doc_files):
        lines = count_lines(doc)
        print(f"    - {doc.name:20s} {lines:4d} 行")
    print()

    print("开发工具")
    print("-" * 50)
    print(f"  Python 脚本:     {len(tool_files):3d} 个")
    for tool in sorted(tool_files):
        print(f"    - {tool.name}")
    print()

    total_code_lines = php_lines + js_lines + css_lines
    print("=" * 50)
    print(f"总代码行数: {total_code_lines:,} 行")
    print(f"总测试行数: {test_lines:,} 行")
    print(f"总文档行数: {doc_lines:,} 行")
    print("=" * 50)

    return 0


if __name__ == "__main__":
    sys.exit(main())
