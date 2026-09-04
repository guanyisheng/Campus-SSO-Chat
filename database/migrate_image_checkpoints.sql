-- ComfyUI checkpoint 后台管理表
-- 执行：mysql -u root -p campus_sso_chat < database/migrate_image_checkpoints.sql

USE campus_sso_chat;

CREATE TABLE IF NOT EXISTS image_checkpoints (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_key     VARCHAR(64)  NOT NULL COMMENT '前端/API 参数 key',
  display_name  VARCHAR(128) NOT NULL COMMENT '显示名称',
  checkpoint    VARCHAR(255) NOT NULL COMMENT 'ComfyUI ckpt_name 文件名',
  output_prefix VARCHAR(64)  NOT NULL DEFAULT 'CampusChat' COMMENT 'ComfyUI 输出文件名前缀',
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_model_key (model_key),
  UNIQUE KEY uk_checkpoint (checkpoint),
  KEY idx_enabled_sort (is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 已有 ComfyUI checkpoint（与 lib/image_models.php 内置 catalog 一致）
INSERT INTO image_checkpoints (model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order)
VALUES
  ('pony_v6', 'Pony V6 XL', 'ponyDiffusionV6XL_v6StartWithThisOne.safetensors', 'Pony_API', 1, 1, 0),
  ('juggernaut_xl_v8', 'Juggernaut XL v8', 'juggernautXL_v8Rundiffusion.safetensors', 'Juggernaut_API', 1, 0, 1)
ON DUPLICATE KEY UPDATE
  display_name  = VALUES(display_name),
  output_prefix = VALUES(output_prefix),
  is_enabled    = VALUES(is_enabled),
  sort_order    = VALUES(sort_order);
