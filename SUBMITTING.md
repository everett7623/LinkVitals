# WordPress.org 上架指南

本文档描述 LinkVitals 从仓库现状到 WordPress.org 插件目录（Plugin Directory）
正式上架的完整流程。日常开发与版本发布流程见
[CONTRIBUTING.md](CONTRIBUTING.md#版本发布流程)。

## 1. 前置条件

- 一个 WordPress.org 账号（当前维护者账号：`everettlabs`）。提交、审核沟通、
  SVN 提交都绑定这个账号，插件归属不可转移给他人（只能转让给组织）。
- **插件 slug 确认**：提交时填写的 slug 决定目录 URL（`wordpress.org/plugins/<slug>/`）
  和 SVN 仓库地址，**通过后永久不可更改**。本项目必须使用 `linkvitals`，
  与 `linkvitals/` 目录名、文本域、`linkvitals.zip` 一致。
- **插件显示名确认**：`LinkVitals – Link Health & SEO Auditor`。不得包含
  他人商标（如 "WordPress"、"WooCommerce" 作为名称前缀）。

## 2. 提交前自检清单

在仓库根目录逐项执行：

```bash
# 全量验证：lint、53 个契约测试、翻译同步、版本一致性、zip 结构
python tools/dev-verify.py

# 版本号五处一致性单独复查
python tools/check-version.py

# 确认发布包与源码同步且结构正确（顶层只有一个 linkvitals/ 目录）
python tools/package-release.py
```

人工核对：

- [ ] `readme.txt` 通过官方 [readme 验证器](https://wordpress.org/plugins/developers/readme-validator/)
- [ ] `Stable tag` 与 `linkvitals.php` 的 `Version:` / `LHA_VERSION` 三者一致
- [ ] `Tested up to` 不低于当前 WordPress 主版本（目录页会因过时值显示
      "未随最新 WP 测试"警告条）
- [ ] `Requires at least: 6.4`、`Requires PHP: 8.0` 与 CI 矩阵一致
- [ ] `readme.txt` 的 `Contributors:` 是 wordpress.org 用户名（不是 GitHub 用户名）
- [ ] `== Description ==` 中对外部服务（OpenAI/Anthropic，用户自带 key、
      默认关闭、发送内容范围）有明确披露——这是审核必查项
- [ ] 提交用的 zip 就是 `python tools/package-release.py` 刚构建的
      `linkvitals.zip`，不是仓库里可能过期的旧产物

## 3. 提交步骤

1. 登录 wordpress.org 账号，打开
   [Add Your Plugin](https://wordpress.org/plugins/developers/plugin-submission/)
   （即 `wordpress.org/plugins/add/`）。
2. 表单填写：插件名称 `LinkVitals – Link Health & SEO Auditor`、slug
   `linkvitals`、上传 `linkvitals.zip`。
3. 提交后是**人工审核**，通常 1–10 天。结果发到账号邮箱：
   - 通过：邮件里给出 SVN 地址与提交指引。
   - 需要修改：按邮件意见在仓库修复、bump 版本、重建 zip 后回复邮件重新提交。
4. 注意流程是两阶段的：除了提交时的表单，登录后还应在插件页面查看完整的
   自动化检查结果（部分问题只在第二阶段暴露），不要只等邮件。

## 4. 审核红线与本项目的对应状态

Plugin Review Team 的完整规则见
[Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)。
对照本项目当前状态：

| 审核红线 | 本项目状态 |
| --- | --- |
| 代码混淆 / 加密 | 无。`base64` 仅用于 API key 加密的 IV 打包，明文可读 |
| 未经用户操作外发数据（phone-home） | 无遥测；AI 请求仅在管理员配置 key 并主动触发时发生，readme 已披露 |
| 外部资源（CDN 脚本/字体） | 无，全部本地打包 |
| SQL 注入 | 全部 `$wpdb->prepare()`，排序/过滤字段走白名单 |
| nonce + capability | 所有 AJAX 走 `LHA_Security::ajax_check()`（`manage_options` + nonce） |
| 前台影响 | 零前台足迹（AGENTS.md 设计约束） |
| 许可证兼容 | GPLv2 or later，源码携带 LICENSE |
| 服务端文件写入/远程代码执行 | 无文件写入功能；修复操作只改文章内容并有快照回滚 |

## 5. 通过后：首次 SVN 发布

审核通过会收到 `https://plugins.svn.wordpress.org/linkvitals` 的 SVN 仓库，
结构如下：

```
linkvitals/
├── assets/     # 目录页图标、横幅（不在 trunk 里！）
├── tags/       # 每个发布版本一个目录
└── trunk/      # 当前开发版，readme.txt 的 Stable tag 指向 tags/ 中的版本
```

首次发布：

```bash
svn checkout https://plugins.svn.wordpress.org/linkvitals svn-linkvitals
cd svn-linkvitals

# 1. 把 linkvitals/ 插件源码（含 readme.txt）复制进 trunk/（不含仓库工具文件）
# 2. 准备目录页素材（可选但强烈建议）：
#    assets/icon-256.png（或 icon.svg）
#    assets/banner-772x250.png、assets/banner-1544x500.png（高分屏）
# 3. 如需 readme 展示截图：trunk/screenshot-1.png 等 + readme.txt 增加
#    == Screenshots == 段落

svn add --force trunk/ assets/
svn ci -m "Initial release of LinkVitals 0.3.x"

# 4. 打 tag：trunk 复制为 tags/0.3.x（目录名必须等于 Stable tag）
svn copy trunk tags/0.3.x
svn ci -m "Tagging 0.3.x"
```

Stable tag 指向哪个 tag，wordpress.org 就向用户分发哪个版本。

## 6. 后续版本发布

1. 在 GitHub 仓库完成开发：bump 五处版本号、`python tools/package-release.py`、
   全量验证、合并到 `main`、按 [CONTRIBUTING.md](CONTRIBUTING.md#版本发布流程)
   打 tag 发 Release。
2. 同步 SVN：

   ```bash
   cd svn-linkvitals
   # 把新版 linkvitals/ 源码同步进 trunk/（删除旧文件后整体替换最稳）
   svn copy trunk tags/<新版本号>
   svn ci -m "Release <新版本号>"
   ```

3. wordpress.org 目录页通常几分钟内刷新；版本进入用户的更新队列。

## 7. 上架后的可选优化

- 把 `linkvitals.php` 头部的 `Plugin URI` 和 readme 的 `Plugin URI`
  换成 `https://wordpress.org/plugins/linkvitals/`（目录页会显示主页链接）。
- 在 WordPress.org 翻译平台（translate.wordpress.org）启用社区翻译后，
  可以考虑停止随包分发 `zh_CN` 语言包，改由平台分发——但当前用户群以
  中文为主，继续随包分发响应更快。
- readme.txt 增加 `== Screenshots ==` 段落和截图，提高目录页转化率。
