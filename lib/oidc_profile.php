<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/oidc.php';

/**
 * 合并 userinfo、id_token、常见嵌套结构，供 UID 提取
 */
function oidc_build_profile(array $token, array $userinfo): array
{
    $profile = [];

    if (!empty($token['id_token']) && is_string($token['id_token'])) {
        $profile = array_merge($profile, oidc_decode_jwt_payload($token['id_token']));
    }

    $profile = array_merge($profile, oidc_unwrap_nested($userinfo));

    return $profile;
}

function oidc_unwrap_nested(array $data): array
{
    $out = $data;
    foreach (['data', 'attributes', 'user', 'profile', 'result', 'userInfo'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $out = array_merge($out, $data[$key]);
        }
    }
    return $out;
}

/** @return array<string, mixed> */
function oidc_decode_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }
    $payload = $parts[1];
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($json === false) {
        return [];
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

/**
 * 是否为有效的「校内用户唯一标识」（排除应用 client_id、邮箱等）
 */
function oidc_is_valid_campus_uid(string $val): bool
{
    $val = trim($val);
    if ($val === '' || oidc_looks_like_email($val)) {
        return false;
    }
    if (defined('OIDC_CLIENT_ID') && OIDC_CLIENT_ID !== '' && $val === (string) OIDC_CLIENT_ID) {
        return false;
    }
    return true;
}

function oidc_extract_uid(array $profile): string
{
    $tryKeys = [];
    foreach (explode(',', OIDC_UID_FIELDS) as $field) {
        $field = trim($field);
        if ($field !== '') {
            $tryKeys[] = $field;
        }
    }
    $tryKeys = array_merge($tryKeys, [
        'sub', 'uid', 'userId', 'user_id', 'userid',
        'loginName', 'login_name', 'username', 'userName', 'account',
        'employee_id', 'employeeId', 'student_id', 'studentId',
        'job_number', 'jobNumber', 'campus_id', 'campusId',
        'preferred_username',
    ]);

    foreach (array_unique($tryKeys) as $key) {
        if (empty($profile[$key]) || !is_scalar($profile[$key])) {
            continue;
        }
        $val = trim((string) $profile[$key]);
        if (oidc_is_valid_campus_uid($val)) {
            return $val;
        }
    }

    foreach (oidc_flatten($profile) as $key => $val) {
        if (!is_scalar($val)) {
            continue;
        }
        $val = trim((string) $val);
        if (!oidc_is_valid_campus_uid($val)) {
            continue;
        }
        if (preg_match('/^(uid|user_?id|login_?name|username|account|student|employee|job|campus|sub)/i', (string) $key)) {
            return $val;
        }
    }

    return '';
}

function oidc_uid_error_hint(array $profile, array $userinfo): string
{
    $keys = array_keys(oidc_flatten($profile));
    $rawKeys = array_keys($userinfo);
    $hint = 'OIDC_UID_FIELDS=' . OIDC_UID_FIELDS;
    if ($keys !== []) {
        $hint .= '；合并后字段: ' . implode(', ', array_slice($keys, 0, 30));
        if (count($keys) > 30) {
            $hint .= '…';
        }
    } elseif ($rawKeys !== []) {
        $hint .= '；userinfo 顶层字段: ' . implode(', ', $rawKeys);
    } else {
        $hint .= '；userinfo 为空，请确认 scope 含 openid profile';
    }
    return $hint;
}

/** @return array<string, scalar> */
function oidc_flatten(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out = array_merge($out, oidc_flatten($v, $key));
        } elseif (is_scalar($v)) {
            $out[$key] = $v;
        }
    }
    return $out;
}

function oidc_looks_like_email(string $val): bool
{
    return strpos($val, '@') !== false && strpos($val, '.') !== false;
}

/** 纯数字、超长 ID 等不宜当姓名展示 */
function oidc_looks_like_internal_id(string $val): bool
{
    $val = trim($val);
    if ($val === '') {
        return true;
    }
    if (preg_match('/^\d{8,}$/', $val)) {
        return true;
    }
    if (preg_match('/^[a-f0-9\-]{20,}$/i', $val)) {
        return true;
    }
    if (strlen($val) >= 16 && preg_match('/^[a-zA-Z0-9_\-]+$/', $val) && !preg_match('/[\x{4e00}-\x{9fff}]/u', $val)) {
        return true;
    }
    return false;
}

/**
 * 从 OIDC 资料取真实姓名，绝不返回学号/工号/数字 ID
 */
function oidc_extract_display_name(array $profile, string $campus_uid = ''): string
{
    foreach (explode(',', OIDC_NAME_FIELDS) as $field) {
        $field = trim($field);
        if ($field === '' || empty($profile[$field])) {
            continue;
        }
        $val = trim((string) $profile[$field]);
        if ($val === '' || oidc_looks_like_email($val)) {
            continue;
        }
        if (oidc_looks_like_internal_id($val)) {
            continue;
        }
        if ($campus_uid !== '' && $val === $campus_uid) {
            continue;
        }
        return $val;
    }

    $given = trim((string) ($profile['given_name'] ?? $profile['givenName'] ?? ''));
    $family = trim((string) ($profile['family_name'] ?? $profile['familyName'] ?? ''));
    $full = trim($family . $given);
    if ($full !== '' && !oidc_looks_like_internal_id($full) && $full !== $campus_uid) {
        return $full;
    }

    return oidc_pick_chinese_name_from_profile($profile, $campus_uid);
}

/**
 * 扫描 userinfo / id_token 中含中文的姓名字段（各校 OIDC 字段名不统一）
 */
function oidc_pick_chinese_name_from_profile(array $profile, string $campus_uid = ''): string
{
    $nameKeys = [
        'name', 'nickname', 'nick_name', 'nickName', 'displayName', 'display_name',
        'realname', 'real_name', 'realName', 'user_name', 'userName', 'userRealName',
        'cn', 'given_name', 'family_name', 'xm', '姓名', 'trueName', 'true_name',
    ];
    foreach ($nameKeys as $key) {
        if (empty($profile[$key]) || !is_scalar($profile[$key])) {
            continue;
        }
        $val = trim((string) $profile[$key]);
        if (oidc_is_plausible_person_name($val, $campus_uid)) {
            return $val;
        }
    }

    foreach (oidc_flatten($profile) as $key => $val) {
        if (!is_scalar($val)) {
            continue;
        }
        $val = trim((string) $val);
        if (!preg_match('/(name|nick|real|display|cn|xm|姓名|true)/i', (string) $key)) {
            continue;
        }
        if (oidc_is_plausible_person_name($val, $campus_uid)) {
            return $val;
        }
    }

    return '';
}

function oidc_is_plausible_person_name(string $val, string $campus_uid = ''): bool
{
    if ($val === '' || oidc_looks_like_email($val) || oidc_looks_like_internal_id($val)) {
        return false;
    }
    if ($campus_uid !== '' && $val === $campus_uid) {
        return false;
    }
    if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $val)) {
        return false;
    }
    if (mb_strlen($val) > 24) {
        return false;
    }
    return true;
}

function oidc_sanitize_display_name(string $name, string $campus_uid = ''): string
{
    $name = trim($name);
    if ($name === '' || oidc_looks_like_internal_id($name)) {
        return '';
    }
    if ($campus_uid !== '' && $name === $campus_uid) {
        return '';
    }
    return $name;
}
