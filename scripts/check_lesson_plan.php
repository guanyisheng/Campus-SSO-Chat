#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 教案融合静态自检（无需数据库）
 * 用法：php scripts/check_lesson_plan.php
 */

$root = dirname(__DIR__);
$errors = [];
$ok = [];

function check(bool $pass, string $label, string $detail = ''): void
{
    global $errors, $ok;
    if ($pass) {
        $ok[] = $label . ($detail !== '' ? " ($detail)" : '');
    } else {
        $errors[] = $label . ($detail !== '' ? ": $detail" : '');
    }
}

$phpFiles = [
    'lesson-plan.php',
    'api/lesson_plan_chat.php',
    'api/lesson_plan_models.php',
    'lib/lesson_plan.php',
    'lib/models.php',
    'lib/quota.php',
];

foreach ($phpFiles as $rel) {
    $path = $root . '/' . $rel;
    check(is_file($path), "文件存在 $rel");
    if (!is_file($path)) {
        continue;
    }
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP 语法 $rel", implode(' ', $out));
}

$assets = [
    'assets/lesson-plan/generator.css',
    'assets/lesson-plan/design-tokens.css',
    'assets/lesson-plan/dir-template.txt',
    'tools/lesson-plan/kunkeda.html',
    'database/migrate_lesson_plan.sql',
];

foreach ($assets as $rel) {
    check(is_file($root . '/' . $rel), "资源存在 $rel");
}

$template = @file_get_contents($root . '/tools/lesson-plan/kunkeda.html');
if (is_string($template)) {
    check(str_contains($template, 'LESSON_PLAN_CFG'), '模板含 LESSON_PLAN_CFG 注入点');
    check(!str_contains($template, '"/api/public_models.php"'), '模板未引用旧 public_models 接口');
    check(str_contains($template, 'DEFAULT_MODELS_URL'), '模板使用统一 models 接口变量');
    check(str_contains($template, 'refreshLessonQuota'), '模板含额度刷新逻辑');
}

require $root . '/config.php';
require $root . '/lib/models.php';

$mock = [
    'api_key'    => '',
    'base_url'   => 'https://example.com/v1/chat/completions',
    'model_name' => 'test-model',
    'model_type' => 'chat',
    'display_name' => 'Test',
    'id' => 1,
];
$runtime = model_chat_runtime($mock);
check($runtime['base_url'] === 'https://example.com/v1', 'model_chat_runtime 规范化 base_url');
check($runtime['model_name'] === 'test-model', 'model_chat_runtime 保留 model_name');

// 模拟 lesson-plan.php 占位符替换
$base = 'https://example.test';
$assetVer = '1';
$lessonCfg = [
    'chatApiUrl'     => $base . '/api/lesson_plan_chat.php',
    'modelsApiUrl'   => $base . '/api/lesson_plan_models.php',
    'quotaApiUrl'    => $base . '/api/quota.php',
    'dirTemplateUrl' => $base . '/assets/lesson-plan/dir-template.txt',
    'chatUrl'        => $base . '/chat.php',
];
$replacements = [
    '__HTML_CLASS__'           => '',
    '__HTML_THEME__'           => '',
    '__EMBED_THEME_CSS__'      => '',
    '__LESSON_DESIGN_LINK__'   => '<link rel="stylesheet" href="' . $base . '/assets/lesson-plan/design-tokens.css?v=' . $assetVer . '" />',
    '__LESSON_GENERATOR_CSS__' => $base . '/assets/lesson-plan/generator.css?v=' . $assetVer,
    '__CHAT_URL__'             => $base . '/chat.php',
    '__LESSON_PLAN_CFG__'      => json_encode($lessonCfg, JSON_UNESCAPED_UNICODE),
];
$html = str_replace(array_keys($replacements), array_values($replacements), $template ?: '');
check(!str_contains($html, '__LESSON_'), 'lesson-plan 占位符已全部替换');
check(str_contains($html, '/api/lesson_plan_chat.php'), '页面注入 chat API');
check(str_contains($html, '/api/lesson_plan_models.php'), '页面注入 models API');

$chatSrc = (string) file_get_contents($root . '/api/lesson_plan_chat.php');
check(str_contains($chatSrc, 'model_resolve_for_chat'), 'chat API 使用统一模型解析');
check(str_contains($chatSrc, 'model_chat_runtime'), 'chat API 使用统一 endpoint 解析');
check(str_contains($chatSrc, 'quota_refund'), 'chat API 失败时退回额度');

echo "=== 教案融合自检 ===\n";
echo '通过: ' . count($ok) . "\n";
foreach ($ok as $line) {
    echo "  ✓ $line\n";
}
if ($errors) {
    echo "\n失败: " . count($errors) . "\n";
    foreach ($errors as $line) {
        echo "  ✗ $line\n";
    }
    exit(1);
}
echo "\n全部通过。\n";
