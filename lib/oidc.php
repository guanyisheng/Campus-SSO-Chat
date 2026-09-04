<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/**
 * OIDC 端点获取策略：
 * 1) config 中 OIDC_*_URL 若已填写 → 直接用
 * 2) OIDC_USE_DISCOVERY=true → 请求 .well-known/openid-configuration
 * 3) 发现失败 → 使用 config 中的 YNJW 备用地址
 */
function oidc_discovery(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    if (defined('OIDC_USE_DISCOVERY') && OIDC_USE_DISCOVERY) {
        $fetched = oidc_fetch_well_known();
        if ($fetched !== null) {
            $cached = $fetched;
            $cached['_source'] = 'well-known';
            return $cached;
        }
    }

    $cached = oidc_fallback_config();
    $cached['_source'] = 'config-fallback';
    return $cached;
}

function oidc_discovery_source(): string
{
    $d = oidc_discovery();
    return (string) ($d['_source'] ?? 'unknown');
}

/** 请求老师提供的 .well-known 地址 */
function oidc_fetch_well_known(): ?array
{
    $url = OIDC_PROVIDER_URL;
    if ($url === '') {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function oidc_fallback_config(): array
{
    return [
        'issuer'                 => 'https://authserver.ynjw.com/authserver/oidc/',
        'authorization_endpoint' => OIDC_AUTHORIZE_URL,
        'token_endpoint'         => OIDC_TOKEN_URL,
        'userinfo_endpoint'      => OIDC_USERINFO_URL,
        'end_session_endpoint'   => OIDC_LOGOUT_URL,
    ];
}

function oidc_authorize_url(): string
{
    $url = oidc_discovery()['authorization_endpoint'] ?? '';
    return $url !== '' ? $url : OIDC_AUTHORIZE_URL;
}

function oidc_token_url(): string
{
    $fromDisc = oidc_discovery()['token_endpoint'] ?? '';
    return $fromDisc !== '' ? $fromDisc : OIDC_TOKEN_URL;
}

function oidc_userinfo_url(): string
{
    $fromDisc = oidc_discovery()['userinfo_endpoint'] ?? '';
    return $fromDisc !== '' ? $fromDisc : OIDC_USERINFO_URL;
}

function oidc_pick_field(array $profile, string $fieldsCsv): string
{
    foreach (explode(',', $fieldsCsv) as $field) {
        $field = trim($field);
        if ($field !== '' && !empty($profile[$field])) {
            return trim((string) $profile[$field]);
        }
    }
    return '';
}
