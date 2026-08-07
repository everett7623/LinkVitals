#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
快速启动开发环境检查的脚本。

在开始开发前运行此脚本，确保环境配置正确。
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path


# 设置 UTF-8 输出（Windows 兼容）
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')


ROOT = Path(__file__).resolve().parents[1]


def check_command(cmd: str, version_arg: str = "--version") -> tuple[bool, str]:
    """检查命令是否可用"""
    try:
        result = subprocess.run(
            [cmd, version_arg],
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace',
            timeout=5
        )
        if result.returncode == 0:
            # 提取版本号（第一行）
            version = result.stdout.strip().split('\n')[0] if result.stdout else "版本未知"
            return True, version
        return False, ""
    except (subprocess.TimeoutExpired, FileNotFoundError, Exception):
        return False, ""


def check_file_exists(path: Path, description: str) -> bool:
    """检查文件是否存在"""
    if path.exists():
        print(f"  [OK] {description}")
        return True
    else:
        print(f"  [MISS] {description}")
        return False


def main() -> int:
    print("=" * 60)
    print("LinkVitals 开发环境检查")
    print("=" * 60)
    print()

    all_ok = True

    # 检查必需的命令行工具
    print("命令行工具:")
    print("-" * 60)

    tools = [
        (sys.executable, "--version", "Python"),
        ("php", "--version", "PHP"),
        ("git", "--version", "Git"),
    ]

    for cmd, version_arg, name in tools:
        available, version = check_command(cmd, version_arg)
        if available:
            print(f"  [OK] {name}: {version}")
        else:
            print(f"  [MISS] {name}: 未找到")
            all_ok = False

    print()

    # 检查项目结构
    print("项目结构:")
    print("-" * 60)

    required_paths = [
        (ROOT / "linkvitals" / "linkvitals.php", "插件主文件"),
        (ROOT / "linkvitals" / "includes", "类文件目录"),
        (ROOT / "tools" / "dev-verify.py", "验证脚本"),
        (ROOT / "tests" / "run.php", "测试套件"),
        (ROOT / ".github" / "workflows" / "ci.yml", "CI 配置"),
        (ROOT / "AGENTS.md", "开发指南"),
        (ROOT / "CONTRIBUTING.md", "贡献指南"),
    ]

    for path, description in required_paths:
        if not check_file_exists(path, description):
            all_ok = False

    print()

    # 检查开发工具
    print("开发工具:")
    print("-" * 60)

    tools_dir = ROOT / "tools"
    if tools_dir.exists():
        tool_scripts = sorted(tools_dir.glob("*.py"))
        print(f"  [OK] 找到 {len(tool_scripts)} 个 Python 工具脚本:")
        for tool in tool_scripts:
            print(f"       - {tool.name}")
    else:
        print("  [MISS] tools/ 目录不存在")
        all_ok = False

    print()

    # 版本检查
    print("版本一致性:")
    print("-" * 60)

    try:
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools" / "check-version.py")],
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace',
            cwd=ROOT
        )
        if result.returncode == 0 and result.stdout:
            # 只显示第一行（版本一致性结果）
            first_line = result.stdout.strip().split('\n')[0] if result.stdout else ""
            if first_line:
                print(f"  {first_line}")
            else:
                print("  [OK] 版本检查完成")
        else:
            print("  [FAIL] 版本号不一致")
            all_ok = False
    except Exception as e:
        print(f"  [WARN] 无法检查版本: {e}")
        # 不设置 all_ok = False，因为这不是致命错误

    print()

    # 总结
    print("=" * 60)
    if all_ok:
        print("✓ 开发环境检查通过！")
        print()
        print("快速开始:")
        print("  python tools/dev-verify.py    - 运行完整验证")
        print("  python tools/stats.py          - 查看项目统计")
        print("  php tests/run.php              - 运行 PHP 测试")
    else:
        print("✗ 开发环境检查发现问题")
        print()
        print("请安装缺失的工具或修复文件结构问题。")
        print("参考 CONTRIBUTING.md 了解详细的环境设置说明。")

    print("=" * 60)

    return 0 if all_ok else 1


if __name__ == "__main__":
    sys.exit(main())
