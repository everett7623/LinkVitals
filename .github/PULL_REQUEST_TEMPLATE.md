# Pull Request

## 变更类型

- [ ] Bug 修复（不破坏现有功能的改动）
- [ ] 新功能（不破坏现有功能的新增）
- [ ] 破坏性变更（可能影响现有功能的修复或功能）
- [ ] 文档更新
- [ ] 代码重构（不改变功能的代码改进）
- [ ] 性能优化
- [ ] 测试相关

## 变更描述

简要描述这个 PR 做了什么。

关联 Issue: #(issue编号)

## 测试

描述你如何测试这些变更：

- [ ] 运行了 `python tools/dev-verify.py`（通过）
- [ ] 运行了 `php tests/run.php`（通过）
- [ ] 手动测试了相关功能
- [ ] 添加了新的测试用例
- [ ] 更新了相关文档

## 变更影响范围

这个 PR 影响哪些部分？

- [ ] 核心扫描逻辑
- [ ] 数据库结构或查询
- [ ] Admin UI
- [ ] AJAX 处理
- [ ] 翻译
- [ ] 文档

## 版本号更新

如果需要更新版本号：

- [ ] 已更新 `linkvitals/linkvitals.php` - Version 头部
- [ ] 已更新 `linkvitals/linkvitals.php` - LHA_VERSION 常量
- [ ] 已更新 `linkvitals/readme.txt` - Stable tag
- [ ] 已更新 `linkvitals/readme.txt` - Changelog
- [ ] 已更新 `linkvitals/readme.txt` - Upgrade Notice
- [ ] 运行了 `python tools/check-version.py` 验证一致性

## 翻译

如果修改了用户可见字符串：

- [ ] 运行了 `python tools/i18n-sync.py`
- [ ] 更新了 `.po` 文件中的翻译
- [ ] 运行了 `python generate-mo.py` 编译翻译

## 截图（如适用）

如果是 UI 变更，请添加截图。

## 检查清单

- [ ] 代码遵循项目编码规范
- [ ] 添加了必要的注释（特别是复杂逻辑）
- [ ] 更新了相关文档（AGENTS.md, CLAUDE.md）
- [ ] 所有新增代码都有 ABSPATH 检查
- [ ] 使用 `$wpdb->prepare()` 处理所有 SQL 查询
- [ ] 使用 `sanitize_*` 和 `esc_*` 函数
- [ ] Commit 消息清晰描述了变更
- [ ] 我已经阅读了 CONTRIBUTING.md

## 额外说明

添加其他需要说明的内容。
