-- 可选：插入默认模型（按需修改 base_url / model_name 后执行）
USE campus_sso_chat;

DELETE FROM llm_models WHERE model_name IN ('gpt-oss:20b', 'deepseek-r1:14b', 'qwen:14b');

INSERT INTO llm_models (display_name, base_url, api_key, model_name, is_enabled, sort_order) VALUES
('GPT-OSS 20B',     'http://192.168.1.33:11435/v1', '', 'gpt-oss:20b',     1, 1),
('DeepSeek R1 14B', 'http://192.168.1.33:11435/v1', '', 'deepseek-r1:14b', 1, 2),
('Qwen 14B',        'http://192.168.1.33:11435/v1', '', 'qwen:14b',        1, 3);
