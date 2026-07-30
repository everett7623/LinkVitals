# CLAUDE.md

This file provides guidance to AI coding assistants (Claude Code, Cursor, GitHub Copilot, etc.) when working with code in this repository.

**完整开发文档请参考 `AGENTS.md`**，本文件仅作为快速参考。

## 快速上手

### 项目结构
- **插件源码**：`linkvitals/`（可安装的 WordPress 插件）
- **开发工具**：`tools/`（验证、打包、国际化脚本）
- **测试**：`tests/`（PHP 合约测试和 WordPress 集成测试）
- **发布包**：`linkvitals.zip`（WordPress 上传包）

### 常用命令

```bash
# 验证代码质量（提交前必须运行）
python tools/dev-verify.py

# 快速检查版本一致性
python tools/check-version.py

# 查看项目统计
python tools/stats.py

# 清理临时文件（预览）
python tools/clean.py

# 编译翻译文件（修改翻译后运行）
python generate-mo.py

# 构建发布包
python tools/package-release.py
```

### 版本更新检查清单

修改源码后必须同步更新 5 处版本号：
1. `linkvitals/linkvitals.php` - `Version:` 头部
2. `linkvitals/linkvitals.php` - `LHA_VERSION` 常量  
3. `linkvitals/readme.txt` - `Stable tag`
4. `linkvitals/readme.txt` - `Changelog` 顶部条目
5. `linkvitals/readme.txt` - `Upgrade Notice` 顶部条目

### 核心约定

- **类命名**：`LHA_*` 前缀，文件名 `class-lha-*.php`
- **手动加载**：新类必须添加到 `linkvitals.php` 的 `require_once` 列表
- **安全防护**：每个 PHP 文件开头 `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- **文本域**：所有翻译使用 `'linkvitals'`
- **前端零影响**：插件不得在公开页面加载任何资源

### 常见陷阱

⚠️ **仓库根目录 ≠ 插件根目录**  
编辑 `linkvitals/` 内的源码，不要直接编辑 `linkvitals.zip`

⚠️ **语言设置不要用 `sanitize_key()`**  
会将 `zh_CN` 转为 `zh_cn`，使用 `LHA_Settings` 的显式规范化

⚠️ **发布包可能过期**  
`linkvitals.zip` 仅在手动运行打包脚本后更新

## 详细文档

完整架构、类职责、数据模型、AJAX 操作、开发优先级请参考 [`AGENTS.md`](AGENTS.md)。
