-- 校园 SSO 智聊 — MySQL 初始化脚本
-- 执行：mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS campus_sso_chat
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE campus_sso_chat;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campus_uid    VARCHAR(64)  NOT NULL COMMENT '校内 UID/学号/工号或本站用户名',
  display_name  VARCHAR(128) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NULL COMMENT '仅本站注册用户',
  auth_source   ENUM('local','oidc') NOT NULL DEFAULT 'oidc',
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_campus_uid (campus_uid),
  KEY idx_auth_source (auth_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llm_models (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(64) NOT NULL,
  base_url    VARCHAR(255) NOT NULL,
  api_key     VARCHAR(255) NOT NULL DEFAULT '',
  model_name  VARCHAR(128) NOT NULL,
  model_type  ENUM('chat','image','video') NOT NULL DEFAULT 'chat',
  is_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_enabled_sort (is_enabled, sort_order),
  KEY idx_type_enabled (model_type, is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_daily_usage (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  usage_date   DATE         NOT NULL,
  chat_rounds  INT UNSIGNED NOT NULL DEFAULT 0,
  image_count  INT UNSIGNED NOT NULL DEFAULT 0,
  video_count  INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, usage_date),
  KEY idx_usage_date (usage_date),
  CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_groups (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(64)  NOT NULL,
  slug               VARCHAR(32)  NOT NULL,
  daily_chat_limit   INT UNSIGNED NOT NULL DEFAULT 100 COMMENT '0=不限',
  daily_image_limit  INT UNSIGNED NOT NULL DEFAULT 20 COMMENT '0=不限',
  daily_video_limit  INT UNSIGNED NOT NULL DEFAULT 10 COMMENT '0=不限',
  can_access_admin   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order         INT          NOT NULL DEFAULT 0,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_agent_presets (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name  VARCHAR(64)  NOT NULL,
  description   VARCHAR(512) NOT NULL DEFAULT '',
  system_prompt MEDIUMTEXT   NOT NULL,
  avatar_file   VARCHAR(255) NOT NULL DEFAULT '',
  model_id      INT UNSIGNED NULL,
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_enabled_sort (is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_agents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  display_name  VARCHAR(64)  NOT NULL,
  description   VARCHAR(512) NOT NULL DEFAULT '',
  system_prompt MEDIUMTEXT   NOT NULL,
  avatar_file   VARCHAR(255) NOT NULL DEFAULT '',
  model_id      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_user_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_agent_assignments (
  user_id     INT UNSIGNED NOT NULL,
  preset_id   INT UNSIGNED NOT NULL,
  assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, preset_id),
  CONSTRAINT fk_assign_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_preset FOREIGN KEY (preset_id) REFERENCES ai_agent_presets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  model_id   INT UNSIGNED NULL,
  title      VARCHAR(200) NOT NULL DEFAULT '新对话',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_updated (user_id, updated_at),
  CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_model FOREIGN KEY (model_id) REFERENCES llm_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role            ENUM('user','assistant','system') NOT NULL,
  content         MEDIUMTEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_conv_id (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
