-- 将 users.id = 25 设为管理员（归属 user_groups.slug = 'admin'）
-- 在 phpMyAdmin 选中 campus_sso_chat 库后执行本脚本

USE campus_sso_chat;

-- 若尚未创建用户组，先插入默认组
INSERT IGNORE INTO user_groups (name, slug, daily_chat_limit, daily_image_limit, daily_video_limit, can_access_admin, sort_order)
VALUES ('普通用户', 'member', 100, 20, 10, 0, 0);

INSERT IGNORE INTO user_groups (name, slug, daily_chat_limit, daily_image_limit, daily_video_limit, can_access_admin, sort_order)
VALUES ('管理员', 'admin', 0, 0, 0, 1, 1);

-- 确保 group_id 列存在（已存在则跳过）
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

UPDATE users
SET group_id = (SELECT id FROM user_groups WHERE slug = 'admin' LIMIT 1)
WHERE id = 25;

-- 验证
SELECT u.id, u.campus_uid, u.display_name, u.group_id, g.name AS group_name, g.can_access_admin
FROM users u
LEFT JOIN user_groups g ON g.id = u.group_id
WHERE u.id = 25;
