<?php
/**
 * 一次性安装检测（部署后可删除本文件）
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [];

try {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $checks[] = ['MySQL 连接', true, '成功'];

    $pdo->exec('USE `' . str_replace('`', '``', DB_NAME) . '`');
    $checks[] = ['数据库 ' . DB_NAME, true, '存在'];

    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $hasUsers = (bool) $stmt->fetch();
    $checks[] = ['users 表', $hasUsers, $hasUsers ? '已就绪' : '请执行 database/schema.sql'];
} catch (Throwable $e) {
    $checks[] = ['MySQL', false, $e->getMessage()];
}

$checks[] = ['curl 扩展', extension_loaded('curl'), extension_loaded('curl') ? '已启用' : '请启用 php-curl'];
$checks[] = ['pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已启用' : '请启用'];
$checks[] = ['SITE_URL', true, SITE_URL];
$checks[] = ['OIDC 回调（需在平台登记）', true, rtrim(SITE_URL, '/') . '/auth/callback.php'];
$checks[] = ['OIDC Client ID', OIDC_CLIENT_ID !== 'your_client_id', OIDC_CLIENT_ID];
$checks[] = ['本站注册', true, ENABLE_LOCAL_AUTH ? '已开启' : '已关闭'];
try {
    require_once __DIR__ . '/lib/oidc.php';
    $disc = oidc_discovery();
    $src = oidc_discovery_source();
  $checks[] = [
        '.well-known 自动发现',
        true,
        ($src === 'well-known' ? '已从 ' . OIDC_PROVIDER_URL . ' 加载' : '发现失败，已用 config 备用端点'),
    ];
    $checks[] = ['授权端点', true, oidc_authorize_url()];
    $checks[] = ['Token 端点', true, oidc_token_url()];
    $checks[] = ['UserInfo 端点', true, oidc_userinfo_url()];
} catch (Throwable $e) {
    $checks[] = ['OIDC', false, $e->getMessage()];
}
$checks[] = ['Ollama', true, OLLAMA_BASE_URL . ' · ' . OLLAMA_MODEL];

$storageParent = dirname(CONV_STORAGE_DIR);
if (!is_dir($storageParent)) {
    @mkdir($storageParent, 0755, true);
}
if (!is_dir(CONV_STORAGE_DIR)) {
    @mkdir(CONV_STORAGE_DIR, 0755, true);
}
$storageExists = is_dir(CONV_STORAGE_DIR);
$storageWritable = $storageExists && is_writable(CONV_STORAGE_DIR);
$storageDetail = CONV_STORAGE_DIR;
if (!$storageExists) {
    $storageDetail .= ' | 目录不存在，且 PHP 无权限创建';
} elseif (!$storageWritable) {
  $storageDetail .= ' | 755 但属主多为 root，PHP(www) 无法写入';
    if (function_exists('fileowner')) {
        $uid = @fileowner(CONV_STORAGE_DIR);
        if ($uid !== false && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && !empty($pw['name'])) {
                $storageDetail .= '（当前属主: ' . $pw['name'] . '）';
            }
        }
    }
}
$checks[] = ['对话存储目录', $storageWritable, $storageDetail];
if ($storageWritable) {
    $checks[] = [
        '存储结构',
        true,
        '{用户ID}/{对话ID}.json · {用户ID}/upload/ 附件',
    ];
}
if ($storageExists && !$storageWritable) {
    $fixPath = $storageParent;
    $checks[] = [
        '请在 SSH 执行',
        true,
        'chown -R www:www ' . $fixPath . ' && chmod -R 755 ' . $fixPath,
    ];
}

require_once __DIR__ . '/lib/redis_client.php';
$redis = redis_client();
$checks[] = ['Redis (DB' . REDIS_DB . ')', $redis !== null, $redis ? '已连接' : '未连接（将仅用 JSON 文件，需 php-redis 扩展）'];
if ($redis !== null) {
    $idleMin = (int) (defined('CONV_REDIS_IDLE_SECONDS') ? CONV_REDIS_IDLE_SECONDS : 1800) / 60;
    $checks[] = ['Redis 空闲清缓存', true, '用户 ' . $idleMin . ' 分钟未发消息则清空其热缓存（JSON 保留）'];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title>安装检测</title>
  <style>
    body { font-family: system-ui; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 0.5rem; border-bottom: 1px solid #eee; text-align: left; }
    .ok { color: #248a3d; }
    .fail { color: #c00; }
  </style>
</head>
<body>
  <h1>校园 SSO 智聊 — 环境检测</h1>
  <table>
    <tr><th>项目</th><th>状态</th><th>说明</th></tr>
    <?php foreach ($checks as $c): ?>
    <tr>
      <td><?= htmlspecialchars($c[0]) ?></td>
      <td class="<?= $c[1] ? 'ok' : 'fail' ?>"><?= $c[1] ? '✓' : '✗' ?></td>
      <td><?= htmlspecialchars($c[2] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p>配置完成后请修改 <code>config.php</code>，并删除本 install.php。</p>
</body>
</html>
