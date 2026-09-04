#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ComfyUI 本地连通性自检
 * 用法：php scripts/check_comfyui.php
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/comfyui.php';

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

$base = comfyui_base_url();
check(is_file(comfyui_workflow_template_path()), 'workflow 模板存在', comfyui_workflow_template_path());

try {
    $json = comfyui_http('GET', '/system_stats', null, 10, $base);
    check(true, 'ComfyUI /system_stats 可达', $base);
    if (isset($json['system'])) {
        check(true, 'system_stats 返回 system 字段');
    }
} catch (Throwable $e) {
    check(false, 'ComfyUI /system_stats 可达', $e->getMessage());
}

check(
    media_provider_is_comfyui(['model_name' => 'comfyui', 'base_url' => '']),
    'media_provider_is_comfyui(comfyui)'
);
check(
    media_provider_is_comfyui(['model_name' => 'other', 'base_url' => 'http://127.0.0.1:8188']),
    'media_provider_is_comfyui(base_url:8188)'
);

require_once $root . '/lib/image_models.php';
$pony = media_image_model_resolve('pony_v6');
check($pony['checkpoint'] === 'ponyDiffusionV6XL_v6StartWithThisOne.safetensors', 'Pony V6 checkpoint 映射');
$jug = media_image_model_resolve('juggernaut_xl_v8');
check($jug['checkpoint'] === 'juggernautXL_v8Rundiffusion.safetensors', 'Juggernaut XL v8 checkpoint 映射');
check(media_image_model_resolve('')['key'] === media_image_model_default_key(), '默认模型 Pony V6 XL');
try {
    media_image_model_resolve('not_a_model');
    check(false, '非法 model 应拒绝');
} catch (InvalidArgumentException) {
    check(true, '非法 model 应拒绝');
}

$wf = comfyui_build_workflow([
    'positive' => 'test fox',
    'negative' => 'blur',
    'width'    => 512,
    'height'   => 512,
    'seed'     => 42,
]);
check(
    ($wf['4']['inputs']['ckpt_name'] ?? '') === comfyui_checkpoint_name(),
    'workflow checkpoint 注入',
    comfyui_checkpoint_name()
);
check((int) ($wf['5']['inputs']['width'] ?? 0) === 512, 'workflow width 注入');

echo "=== ComfyUI 自检 ===\n";
echo 'COMFYUI_BASE_URL=' . $base . "\n";
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
echo "\n全部通过。可在聊天页进入生图模式测试。\n";
