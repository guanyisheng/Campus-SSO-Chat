<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/settings.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . site_base_url() . '/admin/dashboard.php');
    exit;
}

$cb = static fn(string $name): string => isset($_POST[$name]) ? '1' : '0';

setting_save_many([
    'ollama_max_tokens'    => (string) max(256, (int) ($_POST['ollama_max_tokens'] ?? 2048)),
    'ollama_num_ctx'       => (string) max(1024, (int) ($_POST['ollama_num_ctx'] ?? 8192)),
    'ollama_temperature'   => (string) ($_POST['ollama_temperature'] ?? '0.7'),
    'ollama_top_p'         => (string) ($_POST['ollama_top_p'] ?? '0.9'),
    'ollama_history_turns' => (string) max(1, (int) ($_POST['ollama_history_turns'] ?? 12)),
    'ollama_timeout'       => (string) max(30, (int) ($_POST['ollama_timeout'] ?? 180)),
    'enable_chat'          => $cb('enable_chat'),
    'enable_oidc_auth'     => $cb('enable_oidc_auth'),
    'enable_local_auth'    => $cb('enable_local_auth'),
    'chat_notice_enabled'  => $cb('chat_notice_enabled'),
    'chat_notice_title'    => trim((string) ($_POST['chat_notice_title'] ?? '')),
    'chat_notice_html'     => (string) ($_POST['chat_notice_html'] ?? ''),
    'daily_chat_limit'     => (string) max(0, (int) ($_POST['daily_chat_limit'] ?? 100)),
    'daily_image_limit'    => (string) max(0, (int) ($_POST['daily_image_limit'] ?? 20)),
    'daily_video_limit'    => (string) max(0, (int) ($_POST['daily_video_limit'] ?? 10)),
    'enable_image_gen'     => $cb('enable_image_gen'),
    'enable_video_gen'     => $cb('enable_video_gen'),
    'enable_lesson_plan'   => $cb('enable_lesson_plan'),
    'lesson_plan_max_tokens' => (string) max(16384, min(131072, (int) ($_POST['lesson_plan_max_tokens'] ?? 65536))),
    'image_mention_aliases'=> trim((string) ($_POST['image_mention_aliases'] ?? '@图片,@image,@生图')),
    'video_mention_aliases'=> trim((string) ($_POST['video_mention_aliases'] ?? '@视频,@video,@生视频')),
    'content_policy_enabled'    => $cb('content_policy_enabled'),
    'content_policy_refusal'    => trim((string) ($_POST['content_policy_refusal'] ?? '')),
    'content_policy_system_extra'=> trim((string) ($_POST['content_policy_system_extra'] ?? '')),
    'content_sensitive_words'   => trim((string) ($_POST['content_sensitive_words'] ?? '')),
]);

header('Location: ' . site_base_url() . '/admin/dashboard.php?saved=1');
exit;
