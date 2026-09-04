<?php
/**
 * =============================================================================
 * 校园 SSO 智聊 — 全局配置模板
 * 部署时复制为 config.php 并填写实际值：cp config.example.php config.php
 * =============================================================================
 */

declare(strict_types=1);

// ─── MySQL 数据库 ───────────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'campus_sso_chat');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// ─── 站点基础 URL（必须与 OIDC 控制台登记的回调域名一致，末尾不要 /）──────
// 必须是 http(s) 公网/内网地址，例如 https://192.168.1.33:18481 — 不要填本机磁盘路径
define('SITE_URL', 'https://chat.example.edu.cn');

define('SESSION_SECRET', 'CHANGE_ME_TO_RANDOM_64_CHARS');

// ─── OIDC 统一认证（可选，不用 SSO 时可关闭 ENABLE_LOCAL_AUTH 仅用本地登录）──
// 支持 .well-known 自动发现：填写 OIDC_PROVIDER_URL 即可自动获取端点。
// 须在认证平台登记回调：{SITE_URL}/auth/callback.php

define('OIDC_PROVIDER_URL', 'https://auth.example.edu.cn/.well-known/openid-configuration');
define('OIDC_USE_DISCOVERY', true);

define('OIDC_CLIENT_ID', 'your_client_id');
define('OIDC_CLIENT_SECRET', 'your_client_secret');
define('OIDC_PROVIDER_NAME', '统一认证登录');
define('OIDC_SCOPES', 'openid profile');

define('OIDC_AUTHORIZE_URL', '');
define('OIDC_TOKEN_URL', '');
define('OIDC_USERINFO_URL', '');
define('OIDC_LOGOUT_URL', '');

define('OIDC_UID_FIELDS', 'sub,uid,userId,loginName,username,student_id,employee_id,account,preferred_username');
define('OIDC_NAME_FIELDS', 'name,nickname,given_name,family_name,cn,realname,real_name,displayName,userName,user_name,userRealName,nick_name,trueName');

define('SSO_CLIENT_ID', OIDC_CLIENT_ID);
define('SSO_CLIENT_SECRET', OIDC_CLIENT_SECRET);
define('SSO_SCOPE', OIDC_SCOPES);
define('SSO_LOGOUT_URL', OIDC_LOGOUT_URL);
define('SSO_FIELD_UID', 'preferred_username');
define('SSO_FIELD_NAME', 'name');

// ─── 本站注册 / 登录（与 SSO 二选一或并存）──────────────────────────────────
define('ENABLE_LOCAL_AUTH', true);
define('LOCAL_UID_MIN_LEN', 4);
define('LOCAL_UID_MAX_LEN', 32);
define('LOCAL_PASSWORD_MIN_LEN', 6);

// ─── 默认 LLM（Ollama 或 OpenAI 兼容 API）──────────────────────────────────
define('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/v1');
define('OLLAMA_API_KEY', '');
define('OLLAMA_MODEL', 'qwen2.5:7b');

define('OLLAMA_MAX_TOKENS', 2048);
define('OLLAMA_NUM_CTX', 8192);
define('OLLAMA_TEMPERATURE', 0.7);
define('OLLAMA_TOP_P', 0.9);
define('OLLAMA_HISTORY_TURNS', 12);
define('OLLAMA_TIMEOUT', 180);

// ─── ComfyUI 本地生图（model_type=image 且 model_name=comfyui 时走此线路）────────
// 可选 checkpoint 默认值（未传 model 时由 lib/image_models.php 白名单决定）
define('COMFYUI_BASE_URL', 'http://127.0.0.1:8188');
define('COMFYUI_CHECKPOINT', 'sd_xl_base_1.0.safetensors');
define('COMFYUI_DEFAULT_STEPS', 20);
define('COMFYUI_DEFAULT_CFG', 7.0);
define('COMFYUI_POLL_TIMEOUT', 600);

// ─── 生图提示词优化（Ollama 翻译/润色，默认 gemma4:31b）────────────────────
define('IMAGE_PROMPT_OPTIMIZE_ENABLED', true);
define('IMAGE_PROMPT_OPTIMIZE_MODEL', 'gemma4:31b');
define('IMAGE_PROMPT_OPTIMIZE_TIMEOUT', 60);

// ─── 管理后台（独立账号；也可将用户加入「管理员」用户组后 SSO 直进后台）────
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'change_me_strong_password');

// ─── 附件上传（txt / pdf 文本层 / docx / xlsx，不做 OCR）────────────────────
define('UPLOAD_MAX_BYTES', 10 * 1024 * 1024);
define('UPLOAD_MAX_TEXT_CHARS', 48000);
define('UPLOAD_ALLOWED_EXT', ['txt', 'pdf', 'docx', 'xlsx', 'xls', 'csv']);

// ─── 对话存储：Redis 热数据 + 按用户分目录 JSON 文件 ─────────────────────────
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');
define('REDIS_DB', 0);
define('REDIS_KEY_PREFIX', 'campus_chat:');
define('CONV_REDIS_TTL', 2592000);
define('CONV_REDIS_IDLE_SECONDS', 1800);
define('CONV_STORAGE_DIR', __DIR__ . '/storage/conversations');
define('BRANDING_STORAGE_DIR', __DIR__ . '/storage/branding');

// ─── 应用 ─────────────────────────────────────────────────────────────────────
define('APP_NAME', '校园智聊');
define('APP_SUBTITLE', '智能对话');
define('ALLOW_GUEST', false);

date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/lib/site.php';

$_apiScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (str_contains($_apiScript, '/api/')) {
    require_once __DIR__ . '/lib/api_json.php';
    api_json_begin();
}
