-- 智能体：预设（管理员）+ 用户自建 + 预设分发
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
