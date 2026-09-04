<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/oidc.php';
require_once __DIR__ . '/oauth_state.php';
require_once __DIR__ . '/oidc_profile.php';

function sso_authorize_url(): string
{
    $state = oauth_issue_state();

    $params = [
        'response_type' => 'code',
        'client_id'     => OIDC_CLIENT_ID,
        'redirect_uri'  => sso_redirect_uri(),
        'scope'         => OIDC_SCOPES,
        'state'         => $state,
    ];

    $base = oidc_authorize_url();
    if ($base === '') {
        throw new RuntimeException('OIDC 授权地址未配置');
    }
    return $base . '?' . http_build_query($params);
}

/** 【OIDC 回调】须在认证平台登记为：{SITE_URL}/auth/callback.php */
function sso_redirect_uri(): string
{
    return rtrim(SITE_URL, '/') . '/auth/callback.php';
}

function sso_exchange_token(string $code): array
{
    $body = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => sso_redirect_uri(),
        'client_id'     => OIDC_CLIENT_ID,
        'client_secret' => OIDC_CLIENT_SECRET,
    ];
    return http_post_form(oidc_token_url(), $body);
}

function sso_fetch_userinfo(string $access_token): array
{
    $ch = curl_init(oidc_userinfo_url());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        throw new RuntimeException('获取 OIDC 用户信息失败 HTTP ' . $code);
    }
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }

    // 部分校方 /profile 直接返回 JWT 字符串
    $trimmed = trim($raw);
    if (substr_count($trimmed, '.') === 2) {
        return oidc_decode_jwt_payload($trimmed);
    }

    throw new RuntimeException('OIDC 用户信息非 JSON: ' . substr($trimmed, 0, 120));
}

function sso_resolve_profile(array $token, array $userinfo): array
{
    return oidc_build_profile($token, $userinfo);
}

function sso_extract_campus_uid(array $profile, array $userinfo = []): string
{
    $uid = oidc_extract_uid($profile);
    if ($uid === '') {
        throw new RuntimeException(
            'OIDC 未返回校内 UID。' . oidc_uid_error_hint($profile, $userinfo)
        );
    }
    return $uid;
}

function sso_extract_display_name(array $profile, string $campus_uid = ''): string
{
    return oidc_extract_display_name($profile, $campus_uid);
}

function http_post_form(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('OIDC token 请求失败');
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('OIDC token 响应无效: ' . substr((string) $raw, 0, 200));
    }
    if ($code >= 400 || empty($json['access_token'])) {
        $err = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code);
        throw new RuntimeException('OIDC token 错误: ' . (is_string($err) ? $err : json_encode($err)));
    }
    return $json;
}
