<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/**
 * @return \Redis|null
 */
function redis_client(): ?Redis
{
    static $client = null;
    static $failed = false;

    if ($failed || !class_exists('Redis')) {
        return null;
    }

    if ($client instanceof Redis) {
        try {
            $client->ping();
            return $client;
        } catch (Throwable $e) {
            $client = null;
            $failed = true;
            return null;
        }
    }

    try {
        $r = new Redis();
        $ok = $r->connect(REDIS_HOST, (int) REDIS_PORT, 2.5);
        if (!$ok) {
            throw new RuntimeException('connect failed');
        }
        if (REDIS_PASSWORD !== '') {
            $r->auth(REDIS_PASSWORD);
        }
        $r->select((int) REDIS_DB);
        $client = $r;
        return $client;
    } catch (Throwable $e) {
        $failed = true;
        error_log('Redis unavailable: ' . $e->getMessage());
        return null;
    }
}

function redis_key(string $suffix): string
{
    return REDIS_KEY_PREFIX . $suffix;
}
