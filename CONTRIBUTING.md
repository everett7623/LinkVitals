# 贡献指南

感谢你对 LinkVitals 的关注！本文档说明如何为项目做出贡献。

## 开发环境设置

### 必需工具

- **PHP 8.0+**（用于语法检查和测试）
- **Python 3.7+**（用于开发脚本）
- **Git**

### 克隆仓库

```bash
git clone https://github.com/everett7623/LinkVitals.git
cd LinkVitals
```

### 验证环境

```bash
# 运行开发验证脚本
python tools/dev-verify.py

# 运行 PHP 合约测试
php tests/run.php
```

## 开发工作流

### 1. 创建功能分支

```bash
git checkout -b feature/your-feature-name
```

### 2. 进行更改

编辑 `linkvitals/` 目录下的插件源码。

**重要约定**：
- 所有类前缀 `LHA_`
- 文件命名 `class-lha-*.php`
- 新类必须添加到 `linkvitals.php` 的 `require_once` 列表
- 每个 PHP 文件开头必须有 ABSPATH 检查

### 3. 更新翻译（如果修改了用户可见字符串）

```bash
# 同步翻译字符串
python tools/i18n-sync.py

# 编辑 linkvitals/languages/linkvitals-zh_CN.po 中的 msgstr

# 编译翻译
python generate-mo.py
```

### 4. 运行测试

```bash
# 运行所有验证
python tools/dev-verify.py

# 运行 PHP 测试
php tests/run.php
```

### 5. 提交更改

```bash
git add .
git commit -m "feat: 你的功能描述"
```

**Commit 消息格式**：
- `feat:` - 新功能
- `fix:` - 错误修复
- `docs:` - 文档更新
- `refactor:` - 代码重构
- `test:` - 测试相关
- `chore:` - 构建/工具更改

### 6. 推送并创建 Pull Request

```bash
git push origin feature/your-feature-name
```

然后在 GitHub 上创建 Pull Request。

## 代码规范

### PHP 编码标准

- 遵循 WordPress 编码标准
- 使用 Tab 缩进（4 空格宽度）
- 函数/方法使用 snake_case
- 类使用 PascalCase，前缀 `LHA_`

### 安全要求

- **输入验证**：使用 `sanitize_*` 函数
- **输出转义**：使用 `esc_*` 函数
- **SQL 查询**：必须使用 `$wpdb->prepare()`
- **Nonce 验证**：AJAX 请求必须验证 nonce
- **权限检查**：使用 `current_user_can()`

### 翻译

- 所有用户可见字符串必须使用翻译函数
- 文本域统一使用 `'linkvitals'`
- 示例：`__( 'Your text', 'linkvitals' )`

## 版本发布流程

版本发布由维护者处理，流程如下：

### 1. 更新版本号（5 处）

1. `linkvitals/linkvitals.php` - `Version:` 头部
2. `linkvitals/linkvitals.php` - `LHA_VERSION` 常量
3. `linkvitals/readme.txt` - `Stable tag`
4. `linkvitals/readme.txt` - `Changelog` 顶部
5. `linkvitals/readme.txt` - `Upgrade Notice` 顶部

### 2. 构建发布包

```bash
python tools/package-release.py
```

### 3. 验证发布包

```bash
python tools/dev-verify.py
```

### 4. 创建 Git Tag

```bash
git tag -a v0.x.x -m "Version 0.x.x"
git push origin v0.x.x
```

### 5. 在 GitHub 上创建 Release

上传生成的 `linkvitals.zip` 文件。

## 测试

### 单元测试

```bash
# 运行依赖无关的 PHP 合约测试
php tests/run.php
```

这些测试不需要 WordPress 环境。

### 集成测试

集成测试在 GitHub Actions CI 中运行，需要真实的 WordPress 环境。

参见 `.github/workflows/ci.yml` 配置。

## 报告问题

在提交 issue 之前：

1. 搜索现有 issues 避免重复
2. 使用 issue 模板
3. 提供详细的重现步骤
4. 包含 WordPress 版本、PHP 版本、插件版本

## 行为准则

- 保持尊重和包容
- 接受建设性批评
- 关注对项目最有利的事情
- 对社区成员表现出同理心

## 许可证

提交代码即表示你同意将其纳入 GPL v2 或更高版本许可证。

## 获取帮助

- 查看 [AGENTS.md](AGENTS.md) 获取完整开发指南
- 查看 [CLAUDE.md](CLAUDE.md) 获取快速参考
- 在 GitHub issues 中提问

感谢你的贡献！
