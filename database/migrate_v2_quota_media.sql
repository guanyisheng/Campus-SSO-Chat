-- 额度 + 生图/生视频 API 类型 — 在已有库上执行
-- mysql -u root -p campus_sso_chat < database/migrate_v2_quota_media.sql

USE campus_sso_chat;

-- model_type 列由 lib/models.php models_fix_schema() 自动补齐（兼容旧库）

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
  ('video_mention_aliases', '@视频,@video,@生视频');
