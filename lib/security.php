<?php
declare(strict_types=1);

function security_send_html_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function security_install_enabled(): bool
{
    return defined('INSTALL_CHECK_ENABLED') && INSTALL_CHECK_ENABLED;
}

function security_require_install_page(): void
{
    if (!security_install_enabled()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
}

function security_session_secret_ok(): bool
{
    if (!defined('SESSION_SECRET')) {
        return false;
    }

    $secret = (string) SESSION_SECRET;

    return $secret !== ''
        && $secret !== 'CHANGE_ME_TO_RANDOM_64_CHARS'
        && strlen($secret) >= 32;
}

function code_run_enabled(): bool
{
    return !defined('CODE_RUN_ENABLED') || CODE_RUN_ENABLED;
}
