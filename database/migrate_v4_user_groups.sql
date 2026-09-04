-- 用户组 + 分组额度 — 在已有库上执行
-- mysql -u root -p lunanai < database/migrate_v4_user_groups.sql

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

-- group_id 列与 user_groups 表均可由 lib/user_groups.php user_groups_fix_schema() 自动补齐

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('default_user_group_id', '');
