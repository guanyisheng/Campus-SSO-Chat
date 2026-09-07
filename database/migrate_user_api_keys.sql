-- 用户 API Key（登录后在个人中心生成，用于 /api/v1/* 网关）
-- 执行：mysql -u root -p campus_sso_chat < database/migrate_user_api_keys.sql

USE campus_sso_chat;

ALTER TABLE users
  ADD COLUMN api_key_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA256(api key)' AFTER last_login_at,
  ADD COLUMN api_key_prefix VARCHAR(16) NULL DEFAULT NULL COMMENT 'Key 前缀便于识别' AFTER api_key_hash,
  ADD COLUMN api_key_created_at DATETIME NULL DEFAULT NULL AFTER api_key_prefix,
  ADD UNIQUE KEY uk_api_key_hash (api_key_hash);
