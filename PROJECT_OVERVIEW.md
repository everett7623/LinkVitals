# LinkVitals 项目概览

> 全面的 WordPress 链接健康和 SEO 审计插件

[![CI](https://github.com/everett7623/LinkVitals/actions/workflows/ci.yml/badge.svg)](https://github.com/everett7623/LinkVitals/actions/workflows/ci.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)

## 📊 项目统计

- **版本**: 0.3.30
- **代码行数**: 11,642 行（PHP: 10,282 | JS: 1,019 | CSS: 341）
- **测试行数**: 2,759 行
- **文档行数**: 运行 `python tools/stats.py` 查看当前统计
- **开发工具**: 7 个 Python 脚本
- **测试结果**: 运行 `php tests/run.php` 查看当前结果

## 🎯 核心功能

### 链接健康检查
- 扫描 posts、pages、custom post types、menus、taxonomy descriptions
- 检测破损链接（404、5xx错误）
- 识别重定向（301、302、307、308）
- 发现超时和 SSL/DNS 错误
- 支持 WooCommerce 产品图库

### 内部链接分析
- 孤立页面检测
- 锚点片段验证
- 内部链接统计

### SEO 检查
- 外部链接 nofollow 检查
- noopener/noreferrer 安全属性
- HTTP vs HTTPS 链接分析

### 修复和维护
- URL 批量替换
- 破损链接取消链接
- WordPress 图片尺寸自动修复
- 修复历史记录和保护性回滚
- CSV 导出

### AI 增强（可选）
- OpenAI / Anthropic 集成
- 孤立页面链接建议

## 🏗️ 架构特点

### 前端零影响
- 不在公开页面加载任何资源
- 所有功能限制在 admin 和 WP-Cron

### 队列化扫描
- 基于队列的批处理系统
- 原子 claim token 防止并发冲突
- 按域名限速
- 可暂停/恢复扫描

### 性能优化
- 单次聚合查询统计
- 100行批量插入
- 避免冗余 permalink 查询
- 禁用未使用的缓存

## 📁 项目结构

```
LinkVitals/
├── linkvitals/              # WordPress 插件源码
│   ├── linkvitals.php       # 主入口文件
│   ├── includes/            # 22 个 LHA_* 类
│   ├── assets/              # Admin CSS/JS
│   ├── languages/           # 翻译文件
│   └── uninstall.php        # 卸载脚本
├── tools/                   # 7 个开发工具脚本
│   ├── dev-check.py         # 环境检查
│   ├── dev-verify.py        # 完整验证
│   ├── check-version.py     # 版本检查
│   ├── stats.py             # 项目统计
│   ├── clean.py             # 临时文件清理
│   ├── i18n-sync.py         # 翻译同步
│   └── package-release.py   # 发布包构建
├── tests/                   # 测试套件
│   ├── run.php              # 合约测试
│   └── integration/         # WordPress 集成测试
├── .github/                 # GitHub 配置
│   ├── workflows/ci.yml     # CI/CD 流水线
│   ├── ISSUE_TEMPLATE/      # Issue 模板
│   └── PULL_REQUEST_TEMPLATE.md
├── AGENTS.md                # AI 工具开发指南
├── CLAUDE.md                # 快速参考
├── CONTRIBUTING.md          # 贡献指南
└── README.md                # 项目介绍
```

## 🛠️ 开发工具链

### 验证工具
- `dev-check.py` - 开发环境检查
- `dev-verify.py` - 完整代码质量验证（提交前必须）
- `check-version.py` - 快速版本一致性检查

### 维护工具
- `stats.py` - 项目代码统计
- `clean.py` - 临时文件清理

### 国际化
- `i18n-sync.py` - 翻译字符串同步
- `generate-mo.py` - 编译 .mo 文件

### 打包发布
- `package-release.py` - 构建 WordPress 上传包

## 📚 文档体系

### 层次结构
```
README.md          → 项目概述 + 快速开始
    ↓
CLAUDE.md          → 快速参考（命令 + 陷阱）
    ↓
AGENTS.md          → 完整开发指南（架构 + 规范）
    ↓
CONTRIBUTING.md    → 贡献流程详解
    ↓
tools/README.md    → 工具使用文档
```

### 受众定位
- **README.md** - 用户和新贡献者
- **CLAUDE.md** - AI 编码助手快速参考
- **AGENTS.md** - 完整开发指南（所有 AI 工具）
- **CONTRIBUTING.md** - 贡献者详细流程
- **tools/README.md** - 开发工具使用说明

## 🔄 完整工作流

### 1. 环境设置
```bash
git clone https://github.com/everett7623/LinkVitals.git
cd LinkVitals
python tools/dev-check.py
```

### 2. 开发流程
```bash
# 创建功能分支
git checkout -b feature/your-feature

# 编辑源码
vim linkvitals/includes/class-lha-*.php

# 更新翻译（如果修改了用户可见字符串）
python tools/i18n-sync.py
# 编辑 linkvitals/languages/linkvitals-zh_CN.po
python generate-mo.py

# 验证
python tools/check-version.py
python tools/dev-verify.py
php tests/run.php

# 提交
git add .
git commit -m "feat: your feature description"
git push origin feature/your-feature
```

### 3. 发布流程
```bash
# 更新版本号（5处）
# 1. linkvitals/linkvitals.php - Version 头部
# 2. linkvitals/linkvitals.php - LHA_VERSION 常量
# 3. linkvitals/readme.txt - Stable tag
# 4. linkvitals/readme.txt - Changelog
# 5. linkvitals/readme.txt - Upgrade Notice

# 验证版本
python tools/check-version.py

# 构建发布包
python tools/package-release.py

# 最终验证
python tools/dev-verify.py --require-release-zip

# 提交和标签
git commit -am "chore: bump version to 0.x.x"
git tag -a v0.x.x -m "Version 0.x.x"
git push origin main --tags
```

## 🧪 测试策略

### 合约测试（无需 WordPress）
- URL 规范化幂等性
- 队列 claim 原子性
- 翻译完整性
- 版本一致性
- 43 个测试用例

### 集成测试（GitHub Actions）
- WordPress 6.4 + PHP 8.0
- WordPress latest + PHP 8.3
- 多站点激活/停用/卸载
- MySQL 真实环境

## 🎯 代码质量保证

### 自动化验证
- PHP 8.0/8.3 语法检查
- WordPress 约定的静态契约检查
- 翻译字符串覆盖
- 发布包结构验证

### 安全实践
- 所有 PHP 文件 ABSPATH 检查
- SQL 查询使用 `$wpdb->prepare()`
- 输入使用 `sanitize_*`
- 输出使用 `esc_*`
- AJAX nonce 验证
- 权限检查 `current_user_can()`

## 🌟 项目亮点

### AI 工具中立
支持所有主流 AI 编码助手：
- Claude Code
- Cursor
- GitHub Copilot
- 其他遵循开发指南的工具

### 完善的基础设施
- ✅ 完整的文档体系
- ✅ 7 个实用开发工具
- ✅ GitHub Issue/PR 模板
- ✅ CI/CD 自动化测试
- ✅ Windows/macOS/Linux 兼容

### 开源友好
- 清晰的贡献指南
- 详细的架构文档
- 活跃的 CI 验证
- GPL v2 许可证

## 🔗 相关链接

- **GitHub**: https://github.com/everett7623/LinkVitals
- **Issues**: https://github.com/everett7623/LinkVitals/issues
- **License**: GPL v2 or later
- **Author**: everettlabs

## 📝 维护状态

- ✅ 活跃维护中
- ✅ 欢迎贡献
- ✅ Issue 响应及时
- ✅ CI 自动化测试

---

最后更新: 2026-07-30
