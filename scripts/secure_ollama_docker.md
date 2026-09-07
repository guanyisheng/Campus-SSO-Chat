# 安全部署 Ollama（Docker）

**不要**将 Ollama 的 `11434` 直接映射到公网（如 `ollama.ynjw.com`）。本地 Ollama **没有**鉴权，公网暴露会导致 `/api/tags` 等接口被未授权访问。

## 推荐架构

```
公网用户 → openai.ynjw.com（校园智聊 PHP，登录 / API Key）
              ↓ 仅本机
         127.0.0.1:11434（Ollama Docker，不对外）
```

## Docker 示例（仅本机）

```yaml
services:
  ollama:
    image: ollama/ollama:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:11434:11434"
    volumes:
      - ollama_data:/root/.ollama
volumes:
  ollama_data:
```

注意：`127.0.0.1:11434:11434` 而不是 `0.0.0.0:11434:11434`。

## 校园智聊 config.php

```php
define('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/v1');
define('USER_API_KEY_ENABLED', true);
```

## 对外 API（OpenAI 兼容）

用户登录 → 个人中心 → **生成 API Key**。

| 项 | 值 |
|---|---|
| **Base URL** | `https://openai.ynjw.com/api/v1` |
| **对话** | `POST https://openai.ynjw.com/api/v1/chat/completions` |
| **模型列表** | `GET https://openai.ynjw.com/api/v1/models` |
| **鉴权** | `Authorization: Bearer cschat_你的Key` |

Ollama 地址 never 暴露给客户端。

### Nginx 反代（若无 .htaccess）

```nginx
location = /api/v1/chat/completions {
    fastcgi_pass ...;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/api/v1/chat/completions.php;
}
location = /api/v1/models {
    fastcgi_pass ...;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/api/v1/models.php;
}
```

## 下线旧公网 Ollama

1. 停止并删除暴露 `ollama.ynjw.com` 的容器 / Nginx 反代
2. 删除 DNS 记录或改为内网
3. 校外验证：`curl http://ollama.ynjw.com/api/tags` 应失败

## 数据库迁移

```bash
mysql -u root -p campus_sso_chat < database/migrate_user_api_keys.sql
```

（首次打开个人中心也会尝试自动加列。）
