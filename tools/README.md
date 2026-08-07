# LinkVitals 开发工具

本目录包含 LinkVitals 项目的开发和维护工具。

## 可用工具

### 代码质量

#### `dev-verify.py`
完整的代码质量验证工具。

```bash
python tools/dev-verify.py
```

**检查内容:**
- 文件结构完整性
- 版本号一致性
- PHP 语法（如果 PHP 可用）
- 翻译完整性
- 发布包验证（存在 `linkvitals.zip` 时）
- 依赖关系检查

**使用场景:** 提交代码前必须运行

发布前要求发布包存在时，使用：

```bash
python tools/dev-verify.py --require-release-zip
```

---

#### `check-version.py`
快速检查版本号一致性。

```bash
python tools/check-version.py
```

**检查位置:**
- `linkvitals.php` - Version 头部
- `linkvitals.php` - LHA_VERSION 常量
- `readme.txt` - Stable tag
- `readme.txt` - Changelog 顶部条目
- `readme.txt` - Upgrade Notice 顶部条目

**使用场景:** 更新版本号后快速验证

---

#### `dev-check.py`
开发环境检查工具。

```bash
python tools/dev-check.py
```

**检查内容:**
- 必需工具是否安装（PHP, Git, Python）
- 项目结构完整性
- 版本一致性
- 开发工具可用性

**使用场景:** 首次克隆仓库或切换开发环境时

---

### 项目维护

#### `clean.py`
清理临时文件和缓存。

```bash
# 预览模式（默认）
python tools/clean.py

# 执行清理
python tools/clean.py --execute
python tools/clean.py -e
```

**清理内容:**
- Python 缓存 (`__pycache__`, `*.pyc`)
- 日志文件 (`*.log`)
- 临时文件 (`*.tmp`)
- 操作系统临时文件 (`.DS_Store`, `Thumbs.db`)

**使用场景:** 定期清理或构建发布包前

---

#### `stats.py`
项目统计信息。

```bash
python tools/stats.py
```

**统计内容:**
- 代码行数（PHP, JS, CSS）
- 测试文件和行数
- 文档数量
- 工具脚本数量

**使用场景:** 了解项目规模和结构

---

### 国际化

#### `i18n-sync.py`
同步翻译字符串到目录文件。

```bash
python tools/i18n-sync.py
```

**功能:**
- 扫描源码中的翻译函数调用
- 同步到 `.pot` 和 `.po` 文件
- 不翻译字符串，仅添加缺失的 msgid

**使用场景:** 添加或修改用户可见字符串后

---

### 打包发布

#### `package-release.py`
构建 WordPress 可安装的发布包。

```bash
# 构建标准发布包
python tools/package-release.py

# 构建到指定路径
python tools/package-release.py --output /path/to/output.zip

# 允许版本化文件名（仅用于归档）
python tools/package-release.py --output /path/to/linkvitals-1.2.3.zip --allow-versioned-filename
```

**功能:**
- 验证版本号一致性
- 打包 `linkvitals/` 目录
- 排除开发文件
- 验证包结构

**使用场景:** 准备发布新版本时

---

## 开发工作流

### 1. 环境检查
```bash
python tools/dev-check.py
```

### 2. 日常开发
编辑源码...

### 3. 修改翻译（如需要）
```bash
python tools/i18n-sync.py
# 编辑 linkvitals/languages/linkvitals-zh_CN.po
python generate-mo.py
```

### 4. 提交前验证
```bash
python tools/dev-verify.py
```

### 5. 发布准备
```bash
# 更新版本号（5处）
python tools/check-version.py

# 构建发布包
python tools/package-release.py

# 最终验证
python tools/dev-verify.py --require-release-zip
```

---

## 工具依赖

- **Python 3.7+**: 所有工具脚本
- **PHP 8.0+**: `dev-verify.py` 的语法检查功能（可选）
- **Git**: 版本控制（推荐）

---

## 常见问题

### Q: Windows 上出现编码错误？
A: 所有工具已配置 UTF-8 输出，应该能正常工作。如仍有问题，请报告 issue。

### Q: 工具脚本没有执行权限？
A: Unix/Linux 系统可能需要：
```bash
chmod +x tools/*.py
```

### Q: PHP 不在 PATH 中？
A: `dev-verify.py` 会自动跳过 PHP 相关检查，使用 Python 替代验证。

---

## 贡献

欢迎改进开发工具！提交 PR 前请确保：

1. 工具脚本添加适当的文档字符串
2. 支持 Windows 和 Unix 系统
3. 提供清晰的错误消息
4. 更新本 README
