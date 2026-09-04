-- 学校版功能扩展：额度 / 生图生视频 / 用户组 / 智能体
-- 执行：mysql -u root -p campus_sso_chat < database/migrate_school_features.sql
--
-- 若报 users / llm_models 等核心表不存在，请先执行：
--   mysql -u root -p < database/schema.sql

USE campus_sso_chat;

-- ─── 基础表（缺失时补齐，避免 INSERT site_settings 报 #1146）────────────
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- model_type 列由 lib/models.php models_fix_schema() 自动补齐

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

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('daily_chat_limit', '100'),
  ('daily_image_limit', '20'),
  ('daily_video_limit', '10'),
  ('enable_image_gen', '1'),
  ('enable_video_gen', '1'),
  ('image_mention_aliases', '@图片,@image,@生图'),
  ('video_mention_aliases', '@视频,@video,@生视频'),
  ('default_user_group_id', '');

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

-- users.group_id（用户组归属；PHP 也会自动补齐，此处便于纯 SQL 迁移）
SET @has_group_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'group_id'
);
SET @sql_group_id := IF(
  @has_group_id = 0,
  'ALTER TABLE users ADD COLUMN group_id INT UNSIGNED NULL DEFAULT NULL AFTER auth_source, ADD KEY idx_users_group (group_id)',
  'SELECT 1'
);
PREPARE stmt_group_id FROM @sql_group_id;
EXECUTE stmt_group_id;
DEALLOCATE PREPARE stmt_group_id;

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
