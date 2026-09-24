# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

LinkVitals 是一个 WordPress 链接健康与 SEO 审计插件（admin-only，前端零影响）。
**完整开发指南见 [`AGENTS.md`](AGENTS.md)**（全部 AJAX action、全部设置键、逐条设计约束）；本文件是日常工作所需的核心信息。

## 常用命令

```bash
# 提交前必须运行：结构/版本/翻译/源码契约检查 + PHP lint + 契约测试
python tools/dev-verify.py

# 单独运行依赖无关的 PHP 契约测试（53 个）
php tests/run.php

# 单独 lint 某个类文件
php -l linkvitals/includes/class-lha-scanner.php

# 修改用户可见字符串后：同步 catalog → 手工翻译 .po → 编译 .mo
python tools/i18n-sync.py
python generate-mo.py

# 版本一致性快速检查（5 处）
python tools/check-version.py

# 构建发布包 + 发布前完整验证
python tools/package-release.py
python tools/dev-verify.py --require-release-zip
```

`dev-verify.py` 是 `tests/run.php` 的超集：它先跑自己的 Python 检查，再自动调用 `php -l` 和 `php tests/run.php`（PHP 不在 PATH 时降级为 warn 并跳过）。日常只跑 `python tools/dev-verify.py` 即可。

**测试无法按名字过滤。** `tests/run.php` 用 `lha_test( '名称', callable )` 注册进一个数组后全量执行，没有 argv/env 过滤开关。要单独调试一个用例，临时注释掉其他 `lha_test(...)` 调用，或直接读输出里的 `[FAIL] <名称>` 定位。

集成测试需要真实 WordPress + MySQL，只在 GitHub Actions 跑（`wp eval-file tests/integration/run.php`），本地无法直接执行。

## ⚠️ 测试是"源码文本契约"，不是行为测试

这是本仓库最反直觉的一点，**重构前必读**：

`tests/run.php` 的 53 个用例中有 50 个用 `file_get_contents()` + `str_contains()` 断言**源码里的字面文本**；`tools/dev-verify.py` 的多数 `check_*` 函数同理。被断言的对象包括：

- 方法签名原文，如 `public function update_status( int $id, string $status, string $claim_token ): bool`
- SQL 片段原文，甚至出现次数，如 `WHERE id = %d AND status = 'processing' AND claim_token = %s` 必须恰好出现 2 次
- 调用点原文，如 `$this->queue->update_status( (int) $item['id'], 'done', $claim_token )`
- 集成测试里的**断言失败消息文案**，如 `'A stale worker completed an item that another worker had reclaimed.'`
- `.github/workflows/ci.yml` 的内容（矩阵版本、各步骤命令行）

后果：**重命名方法、调整参数顺序、改写 SQL、修改测试文案、改动 CI 配置，都会让"测试"失败——即使行为完全没变。** 修复方式是同步更新 `tests/run.php` / `tools/dev-verify.py` 里对应的字面量，而不是把改动回滚。反过来，这些"测试"通过也**不代表运行时行为正确**，真实行为验证靠 `tests/integration/`。

另一个隐蔽契约：翻译 catalog 头部的 `Project-Id-Version: LinkVitals <版本>` 必须跟随 `LHA_VERSION`（`i18n-sync.py` 不会自动更新它），否则 `keeps release metadata tied to the plugin version` 用例失败。

## 架构要点

### 启动
`LinkVitals_Plugin`（单例）在 `plugins_loaded` 启动 → `init` 载入 textdomain → `admin_init` 执行 `check_version()` → 仅在 `is_admin()` 时构造 admin 对象 → cron 始终注册。**没有 autoloader**，`linkvitals/linkvitals.php` 顶部是手写的 `require_once` 列表。

### 扫描流水线（`LHA_Scanner` 编排）
```
开始扫描 → LHA_Queue 填充待扫对象（100 行批量插入，taxonomy 按 term ID 分 100 条窗口）
  → lha_process_queue（每 5 分钟）或 AJAX 批处理领取任务，标记 processing
  → LHA_Link_Extractor 用 DOMDocument 解析、解析相对 URL、分类链接
  → 提取成功后才删除该对象的旧 occurrences（提取失败保留上次良好结果并重试，3 次后 failed）
  → LHA_DB::upsert_link()（按 normalized_url 的 SHA-256 去重）+ insert_occurrence()（每次出现一行）
  → LHA_Link_Checker 检查 pending 链接（HEAD → GET 回退，按域名限速）
  → 队列与 pending 链接都排空后状态置 completed
```

### 四把锁/令牌（改动扫描或升级逻辑时必须理解）
| 机制 | 存储 | 作用 |
|------|------|------|
| `claim_token` | `lha_queue` 行字段 | 每批次领取令牌。完成/重试转换都带令牌校验，阻止过期 worker 覆盖已被他人重新领取的任务 |
| `lha_scan_token` | option | 扫描代际令牌。防止旧 worker 写入新一轮扫描的完成时间和增量游标 |
| `lha_scan_state_lock` | option | 串行化扫描初始化与完成状态转换，防止 full/incremental/recheck 并发启动互相清空队列 |
| `lha_upgrade_lock` | option | 带 owner token 的升级互斥，加锁后重读 `lha_version`，支持过期恢复的 CAS（CAS 直写 options 表后必须同时失效 `options` 和 `alloptions` 两处缓存，否则 autoload 的锁行会让释放方读到旧 token 而拒绝释放），只有持有者能释放 |

关键不变量：只有**全新安装**在激活时提交 `lha_version`；已有安装保留旧版本标记，直到升级例程成功才提交，失败/被锁则保持不变以便下次重试。

### 数据模型（`$wpdb->prefix . 'lha_' . $name`）
`lha_links`（按规范化 URL 去重，一 URL 一行）、`lha_occurrences`（一处出现一行）、`lha_queue`、`lha_logs`、`lha_repairs`（含 `old_content`/`new_content` 快照与内容哈希，支撑守卫式回滚）。

不变量：`LHA_DB::normalize_url()` 必须幂等；重扫对象前删除其旧 occurrences；被忽略的链接除 Ignored 筛选外不出现在报表；所有变量 SQL 走 `$wpdb->prepare()`。

### 问题统计口径
统计只按 **status** 计算（`LHA_DB::get_issue_statuses()` 是唯一真源）。404/5xx 等 code 分桶只作为次要诊断展示，**不得**计入 actionable 总数，否则会重复计数。报表筛选键先经 `LHA_DB::sanitize_report_filter_key()` 收敛。

CSV 导出按 1000 行分批流式写出（单次查询上限会静默截断大报表），单元格过 `LHA_Exporter::guard_cell()` 中和表格公式注入，`get_links()` 的 ORDER BY 带 `l.id` 裁决字段保证分页窗口稳定。

### AI 旁路
AI 建议独立于扫描流水线：admin 投递一个 `lha_process_ai_orphan_job` 单次事件 → transient 支撑的稳定状态轮询 → 最多展示 3 条建议。`LHA_AI_Internal` 只发送最多 10 条标题/摘要上下文，并**丢弃任何不在服务端候选映射里的模型返回 ID**；仅建议，绝不写入文章内容。任务去重与状态轮询按发起管理员隔离。

API key 加密只在站点定义了 `AUTH_KEY` 时进行；缺失时设置页报错并保留旧 key，**绝不**用代码内固定盐加密（源码公开等于明文）。

## 代码约定

- 类名前缀 `LHA_`，文件名 `class-lha-<name>.php`，**新类必须手工加入 `linkvitals.php` 的 `require_once` 列表**（`dev-verify.py` 的 `check_manual_requires` 会校验）
- 每个 PHP 文件开头：`if ( ! defined( 'ABSPATH' ) ) { exit; }`
- 输入 `sanitize_*`、输出 `esc_*`、SQL `$wpdb->prepare()`、AJAX 用 `wp_send_json_success()` / `wp_send_json_error()`
- AJAX 统一校验 `lha_ajax_nonce` + `manage_options`；设置保存用 `check_admin_referer( 'lha_settings_nonce' )`；修复类操作还要按文章校验 `edit_post`
- 文本域一律 `'linkvitals'`
- 前端零影响：不得在公开页面加载任何资源、查询或重钩子
- 缩进：PHP/JS/CSS 用 tab（宽 4），Python/MD/YAML 用 4 空格（见 `.editorconfig`）

## 国际化工作流

翻译是**手工维护**的（非 WP-CLI 生成）。改动用户可见字符串后必须：`python tools/i18n-sync.py` → 手工填写 `linkvitals-zh_CN.po` 的 msgstr → `python generate-mo.py`。

CI 会重跑这两个脚本并执行 `git diff --exit-code`，所以 **`.pot` / `.po` / `.mo` 三个生成文件都必须提交**。`dev-verify.py` 另外按 mtime 校验 `.mo` 不旧于 `.po`，并逐条解码校验若干关键中文译文。

## 版本发布

任何被接受的源码/文档/打包改动都要 bump 版本，**5 处必须一致**：
1. `linkvitals/linkvitals.php` — `Version:` 头部
2. `linkvitals/linkvitals.php` — `LHA_VERSION` 常量
3. `linkvitals/readme.txt` — `Stable tag`
4. `linkvitals/readme.txt` — `Changelog` 顶部条目
5. `linkvitals/readme.txt` — `Upgrade Notice` 顶部条目

上架 WordPress.org 插件目录的完整流程（提交表单、审核、SVN 发布）见 [`SUBMITTING.md`](SUBMITTING.md)。

## 陷阱

- **仓库根 ≠ 插件根**：编辑 `linkvitals/` 下的源码；`linkvitals.zip` 是产物（且被 `.gitignore` 忽略），只在跑打包脚本后更新，随时可能过期
- **发布包必须叫 `linkvitals.zip`**，不能是 `linkvitals-<version>.zip`：部分主机的文件管理器会按 zip 名建目录，产生 `wp-content/plugins/linkvitals-<version>/`，破坏 WordPress 的删除与升级
- **`language` 设置不能用 `sanitize_key()`**：会把 `zh_CN` 变成 `zh_cn`。用 `LHA_Settings` 的显式规范化，同时兼容历史小写值。默认值是 `auto`（跟随站点语言，`zh*` → 简体中文）
- **不要同时激活 0.2.x 插件目录和 `linkvitals/`**：二者刻意共用 `LHA_*` 类与 `lha_*` 表/option/AJAX/cron 标识以保持数据兼容
- `assets/` 下的 `.sync-conflict-*` 是 Syncthing 垃圾文件，不是源码（`dev-verify.py` 会报错）
- `LHA_AI` 是可选的，未配置 provider key 时必须优雅降级
