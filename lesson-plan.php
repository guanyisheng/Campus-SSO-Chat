<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/quota.php';
require_once __DIR__ . '/lib/site.php';
require_once __DIR__ . '/lib/sso_consent.php';

require_login();
sso_require_consent_page();

if (!setting_bool('enable_lesson_plan', true)) {
    header('Location: ' . site_base_url() . '/chat.php');
    exit;
}

$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';
$base = site_base_url();
$assetVer = (string) max(
    (int) @filemtime(__DIR__ . '/assets/lesson-plan/generator.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/lesson-plan-embed.css'),
    (int) @filemtime(__DIR__ . '/tools/lesson-plan/kunkeda.html')
);
$lessonCfg = [
    'chatApiUrl'      => $base . '/api/lesson_plan_chat.php',
    'modelsApiUrl'    => $base . '/api/lesson_plan_models.php',
    'quotaApiUrl'     => $base . '/api/quota.php',
    'dirTemplateUrl'  => $base . '/assets/lesson-plan/dir-template.txt',
    'chatUrl'         => $base . '/chat.php',
    'embedMode'       => $embed,
];
$templatePath = __DIR__ . '/tools/lesson-plan/kunkeda.html';
if (!is_file($templatePath)) {
    http_response_code(500);
    echo '教案页面模板缺失';
    exit;
}

$html = file_get_contents($templatePath);
if ($html === false) {
    http_response_code(500);
    echo '无法读取教案页面';
    exit;
}

$embedThemeCss = $embed
    ? '<link rel="stylesheet" href="' . htmlspecialchars($base . '/assets/ui/css/tokens.css?v=' . $assetVer, ENT_QUOTES, 'UTF-8') . '" />'
    . '<link rel="stylesheet" href="' . htmlspecialchars($base . '/assets/ui/css/lesson-plan-embed.css?v=' . $assetVer, ENT_QUOTES, 'UTF-8') . '" />'
    : '';

$designLink = $embed
    ? ''
    : '<link rel="stylesheet" href="' . htmlspecialchars($base . '/assets/lesson-plan/design-tokens.css?v=' . $assetVer, ENT_QUOTES, 'UTF-8') . '" />';

$replacements = [
    '__HTML_CLASS__'           => $embed ? 'lesson-plan-embed' : '',
    '__HTML_THEME__'           => $embed ? ' data-theme="dark"' : '',
    '__EMBED_THEME_CSS__'      => $embedThemeCss,
    '__LESSON_DESIGN_LINK__'   => $designLink,
    '__LESSON_GENERATOR_CSS__' => htmlspecialchars($base . '/assets/lesson-plan/generator.css?v=' . $assetVer, ENT_QUOTES, 'UTF-8'),
    '__CHAT_URL__'             => htmlspecialchars($base . '/chat.php', ENT_QUOTES, 'UTF-8'),
    '__LESSON_PLAN_CFG__'      => json_encode($lessonCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
];

echo str_replace(array_keys($replacements), array_values($replacements), $html);
