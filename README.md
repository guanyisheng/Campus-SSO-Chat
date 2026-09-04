# 校园智聊（Campus SSO Chat）

面向校园与机构的 **PHP + MySQL** 大模型对话系统。支持 **OIDC 统一认证** 与 **本站注册/登录**，对接 **Ollama** 或任意 **OpenAI 兼容 API**，提供多模型切换、流式对话、生图/生视频、智能体、用户组额度、管理后台等功能。

仓库地址：[github.com/guanyisheng/Campus-SSO-Chat](https://github.com/guanyisheng/Campus-SSO-Chat)

---

## 功能特性

### 认证与权限

- **双模式登录**：OIDC SSO（支持 `.well-known` 自动发现）+ 本地账号注册/登录
- **用户组与额度**：按组配置每日对话 / 生图 / 生视频次数；`0` 表示不限
- **管理员**：独立后台账号，或将用户加入「管理员」组后 SSO 直进后台

### 对话能力

- **多模型管理**：后台配置多个 LLM 端点，对话页可切换模型
- **流式输出**：SSE 流式回复，Markdown 渲染与代码高亮
- **附件上传**：txt、pdf（文本层）、docx、xlsx 等，提取文本后送入模型
- **编程模式**：代码工作区，支持 HTML 预览与本地运行提示
- **对话存储**：Redis 热缓存 + 按用户分目录 JSON 持久化

### 多模态与智能体

- **图像 / 视频生成**：@提及 或 Composer 模式触发，支持排队与进度展示
- **智能体**：预设智能体 + 用户自建智能体，可绑定模型与系统提示词
- **媒体管理**：生成结果可预览、下载；后台可查看媒体任务队列

### 界面

- 深色 / 浅色主题切换
- 侧栏会话历史、智能体列表、额度展示
- 管理后台：系统设置、模型、用户、用户组、智能体、媒体队列

---

## 环境要求

| 组件 | 版本 / 说明 |
|------|-------------|
| PHP | 8.0+，扩展：`pdo_mysql`、`curl`、`json`、`mbstring` |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Redis | 可选，用于对话热缓存（无 Redis 时仅用 JSON 文件） |
| Web 服务器 | Nginx / Apache + PHP-FPM |
| LLM 服务 | Ollama 或 OpenAI 兼容 API |
| 生图 / 生视频 | 需在后台单独配置对应 API 端点 |

---

## 快速开始

### 1. 获取代码

```bash
git clone https://github.com/guanyisheng/Campus-SSO-Chat.git
cd Campus-SSO-Chat
```

### 2. 配置

```bash
cp config.example.php config.php
# 编辑 config.php
```

**必改项：**

| 配置项 | 说明 |
|--------|------|
| `DB_*` | 数据库连接 |
| `SITE_URL` | 站点公网地址（末尾不要 `/`） |
| `SESSION_SECRET` | 随机字符串（建议 32 字节以上） |
| `ADMIN_PASSWORD` | 管理后台密码 |
| `OLLAMA_BASE_URL` / `OLLAMA_MODEL` | 默认对话模型后端 |

**若使用 OIDC：**

1. 在认证平台创建应用，获取 `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET`
2. 登记回调地址：`{SITE_URL}/auth/callback.php`
3. 填写 `OIDC_PROVIDER_URL`（推荐 `OIDC_USE_DISCOVERY = true`）

**若仅用本地登录：** 保持 `ENABLE_LOCAL_AUTH = true`，可不配置 OIDC。

### 3. 初始化数据库

**全新安装：**

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p campus_sso_chat < database/seed_models.sql   # 可选：示例模型
```

**已有旧库升级（生图 / 生视频 / 额度 / 用户组 / 智能体）：**

```bash
mysql -u root -p campus_sso_chat < database/migrate_school_features.sql
```

若报 `site_settings` 或核心表不存在，请先执行 `schema.sql`。

### 4. 目录权限

```bash
mkdir -p storage/conversations storage/branding
chown -R www-data:www-data storage    # 按实际 PHP 运行用户调整
chmod -R 755 storage
```

或执行：

```bash
bash scripts/fix_storage_permissions.sh
```

### 5. 环境检测

浏览器访问 `https://your-domain/install.php`，确认 MySQL、OIDC、Redis、存储目录等检测通过。

**检测通过后请删除 `install.php`。**

### 6. 设置管理员（可选）

**方式 A — 用户组（推荐，SSO 用户可用）：**

```sql
UPDATE users
SET group_id = (SELECT id FROM user_groups WHERE slug = 'admin' LIMIT 1)
WHERE id = YOUR_USER_ID;
```

**方式 B — 后台独立账号：** 使用 `config.php` 中的 `ADMIN_USERNAME` / `ADMIN_PASSWORD` 登录 `/admin/`。

---

## 目录结构

```
├── index.php / login.php / register.php / chat.php / privacy.php
├── config.example.php          # 配置模板（复制为 config.php）
├── auth/                       # OIDC 与本地登录
├── api/                        # 对话、模型、上传、生图/视频、智能体等
├── admin/                      # 后台：设置、模型、用户、用户组、智能体、媒体队列
├── includes/                   # 页面片段、公告、隐私政策
├── lib/                        # 业务逻辑
├── assets/ui/css/              # 主题与组件样式
├── assets/js/                  # chat.js 及前端模块
├── database/                   # schema、迁移脚本、种子数据
├── storage/                    # 对话 JSON、品牌资源（运行时，勿提交）
└── scripts/                    # 运维脚本
```

---

## 主要页面

| 路径 | 说明 |
|------|------|
| `/` | 首页（已登录跳转对话页） |
| `/login.php` | 登录（本地 + 统一认证） |
| `/register.php` | 注册（需开启本地认证） |
| `/chat.php` | 对话主界面 |
| `/privacy.php` | 隐私政策 |
| `/admin/` | 管理后台 |

---

## 数据库迁移说明

| 脚本 | 用途 |
|------|------|
| `database/schema.sql` | 全量建库建表 |
| `database/seed_models.sql` | 插入示例模型 |
| `database/migrate_school_features.sql` | 额度、用户组、智能体、生图/生视频等扩展 |
| `database/migrate_v2_quota_media.sql` | 早期额度与媒体字段 |
| `database/migrate_v4_user_groups.sql` | 用户组表 |
| `database/migrate_v6_agents.sql` | 智能体表 |

新部署直接跑 `schema.sql` 即可；从旧版升级按需执行迁移脚本。

---

## 对接其他 LLM / 生图 / 生视频

1. **对话模型**：管理后台 → 模型管理，填写 `base_url`、`api_key`（可选）、`model_name`
2. **生图 / 生视频**：后台添加 `model_type` 为 `image` / `video` 的模型，并开启对应开关
3. 兼容 OpenAI Chat Completions 格式的服务均可使用（Ollama、vLLM、One API 等）

---

## 自定义（可选）

| 文件 | 说明 |
|------|------|
| `includes/brand_logo.php` | 站点 Logo |
| `includes/yszx.html` | 隐私政策 HTML |
| 管理后台「对话页公告」 | 启用后可配置欢迎弹窗 |

---

## 安全建议

- 生产环境使用 HTTPS
- 修改默认 `ADMIN_PASSWORD` 与 `SESSION_SECRET`
- **勿将 `config.php` 提交到版本库**
- 部署后删除 `install.php`
- Redis、MySQL 勿暴露公网
- 定期备份 `storage/conversations` 与数据库

---

## 许可证

[MIT License](LICENSE)

---

## 致谢

适用于校园、企事业单位私有化部署大模型对话服务。欢迎提交 Issue 与 Pull Request。
