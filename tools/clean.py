#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
清理项目中的临时文件和缓存。

安全清理：
- Python 缓存 (__pycache__, *.pyc)
- 编辑器临时文件
- 日志文件
- 操作系统临时文件

不会删除：
- 源码文件
- 发布包
- Git 历史
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


# 设置 UTF-8 输出（Windows 兼容）
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')


ROOT = Path(__file__).resolve().parents[1]

# 要清理的目录和文件模式
CLEANUP_PATTERNS = {
    "Python 缓存": ["__pycache__", "*.pyc", "*.pyo", "*.pyd"],
    "日志文件": ["*.log"],
    "临时文件": ["*.tmp", "*.temp"],
    "操作系统临时文件": [".DS_Store", "Thumbs.db", "desktop.ini"],
    "编辑器临时文件": ["*~", ".*.swp", ".*.swo"],
}


def get_tracked_paths() -> set[str]:
    """返回 Git 已跟踪路径，无法读取时拒绝执行清理。"""
    try:
        result = subprocess.run(
            ["git", "-C", str(ROOT), "ls-files", "-z"],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            check=False,
            timeout=5,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError(f"无法读取 Git 跟踪文件: {exc}") from exc

    if result.returncode != 0:
        message = result.stderr.strip() or "git ls-files 失败"
        raise RuntimeError(f"无法读取 Git 跟踪文件: {message}")

    return {
        item.replace("\\", "/").casefold()
        for item in result.stdout.split("\0")
        if item
    }


def relative_key(path: Path) -> str:
    """将仓库内路径规范化为可比较的 Git 路径。"""
    return path.relative_to(ROOT).as_posix().casefold()


def directory_contains_tracked_file(path: Path, tracked_paths: set[str]) -> bool:
    """检查目录自身或其子树是否包含 Git 跟踪文件。"""
    prefix = relative_key(path).rstrip("/") + "/"
    return any(item == prefix[:-1] or item.startswith(prefix) for item in tracked_paths)


def find_files_to_clean(dry_run: bool = True) -> dict[str, list[Path]]:
    """查找需要清理的文件"""
    found = {category: [] for category in CLEANUP_PATTERNS}
    tracked_paths = get_tracked_paths()

    for category, patterns in CLEANUP_PATTERNS.items():
        candidates: set[Path] = set()
        for pattern in patterns:
            if pattern.startswith("*."):
                # 文件扩展名模式
                for path in ROOT.rglob(pattern):
                    if path.is_file() and ".git" not in path.parts:
                        candidates.add(path)
            else:
                # 目录或具体文件名模式
                for path in ROOT.rglob(pattern):
                    if ".git" not in path.parts:
                        candidates.add(path)

        candidate_directories = {path for path in candidates if path.is_dir()}
        for path in sorted(candidates, key=lambda item: (len(item.parts), str(item))):
            if any(parent in candidate_directories for parent in path.parents):
                continue
            if path.is_dir() and directory_contains_tracked_file(path, tracked_paths):
                continue
            if path.is_file() and relative_key(path) in tracked_paths:
                continue
            found[category].append(path)

    return found


def format_size(size: int) -> str:
    """格式化文件大小"""
    for unit in ['B', 'KB', 'MB', 'GB']:
        if size < 1024.0:
            return f"{size:.1f} {unit}"
        size /= 1024.0
    return f"{size:.1f} TB"


def get_size(path: Path) -> int:
    """获取文件或目录大小"""
    if path.is_file():
        return path.stat().st_size
    elif path.is_dir():
        return sum(f.stat().st_size for f in path.rglob('*') if f.is_file())
    return 0


def clean_files(files: dict[str, list[Path]], dry_run: bool = True) -> tuple[int, int, int]:
    """清理文件"""
    total_count = 0
    total_size = 0
    failed_count = 0

    for category, paths in files.items():
        if not paths:
            continue

        print(f"\n{category}:")
        print("-" * 50)

        for path in paths:
            size = get_size(path)
            rel_path = path.relative_to(ROOT)

            if dry_run:
                print(f"  [预览] {rel_path} ({format_size(size)})")
            else:
                try:
                    if path.is_dir():
                        import shutil
                        shutil.rmtree(path)
                    else:
                        path.unlink()
                    print(f"  [删除] {rel_path} ({format_size(size)})")
                    total_count += 1
                    total_size += size
                except Exception as e:
                    print(f"  [失败] {rel_path}: {e}")
                    failed_count += 1

    return total_count, total_size, failed_count


def main() -> int:
    parser = argparse.ArgumentParser(
        description="清理项目临时文件和缓存",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
示例:
  python tools/clean.py              # 预览模式（默认）
  python tools/clean.py --execute    # 执行清理
  python tools/clean.py -e           # 执行清理（简写）
        """
    )
    parser.add_argument(
        "-e", "--execute",
        action="store_true",
        help="实际执行清理（默认为预览模式）"
    )

    args = parser.parse_args()

    print("=" * 50)
    if args.execute:
        print("LinkVitals 项目清理 - 执行模式")
    else:
        print("LinkVitals 项目清理 - 预览模式")
    print("=" * 50)

    try:
        files = find_files_to_clean(not args.execute)
    except RuntimeError as exc:
        print(f"\n[失败] {exc}", file=sys.stderr)
        return 1

    total_files = sum(len(paths) for paths in files.values())
    total_size = sum(get_size(p) for paths in files.values() for p in paths)

    if total_files == 0:
        print("\n✓ 没有需要清理的文件")
        return 0

    count, size, failed_count = clean_files(files, not args.execute)

    print("\n" + "=" * 50)
    if args.execute:
        print(f"已清理: {count} 个文件/目录，释放 {format_size(size)}")
        if failed_count:
            print(f"失败: {failed_count} 个文件/目录")
            return 1
    else:
        print(f"预览: {total_files} 个文件/目录，共 {format_size(total_size)}")
        print("\n使用 --execute 或 -e 参数执行实际清理")
    print("=" * 50)

    return 0


if __name__ == "__main__":
    sys.exit(main())
