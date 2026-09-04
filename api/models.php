<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/models.php';

api_json_headers();

require_login();

$models = models_list_enabled();
echo json_encode(['models' => $models], JSON_UNESCAPED_UNICODE);
