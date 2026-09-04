-- 可选：插入 ComfyUI 本地生图 provider（model_name=comfyui 时走 lib/comfyui.php）
-- 执行：mysql -u root -p campus_sso_chat < database/seed_comfyui_image.sql

USE campus_sso_chat;

INSERT INTO llm_models (display_name, base_url, api_key, model_name, model_type, is_enabled, sort_order)
SELECT 'ComfyUI SDXL', 'http://127.0.0.1:8188', '', 'comfyui', 'image', 1, -10
WHERE NOT EXISTS (
    SELECT 1 FROM llm_models WHERE model_type = 'image' AND model_name = 'comfyui'
);
