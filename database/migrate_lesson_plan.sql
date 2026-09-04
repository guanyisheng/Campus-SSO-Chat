-- 教案生成记录（可选，用于统计与排查）
CREATE TABLE IF NOT EXISTS lesson_plan_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  course_name VARCHAR(255) NOT NULL DEFAULT '',
  model_name VARCHAR(160) NOT NULL DEFAULT '',
  lesson_count INT NOT NULL DEFAULT 0,
  input_json JSON NULL,
  error_text TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_lesson_plan_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
