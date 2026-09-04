<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/privacy_policy.php';

/** 对话页公告默认 HTML（管理员未配置时使用） */
function chat_notice_default_html(string $base): string
{
    $base = rtrim($base, '/');
    $logoMain = htmlspecialchars($base . '/logo.webp', ENT_QUOTES, 'UTF-8');
    $logoPartner = htmlspecialchars($base . '/' . rawurlencode('透明ai.png'), ENT_QUOTES, 'UTF-8');
    $privacyUrl = htmlspecialchars(privacy_policy_url($base), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="chat-notice-inner">
  <div class="chat-notice-inner__head">欢迎使用昆明科技职业大学大模型系统</div>

  <p>本模型为本地生成部署，运行于人工智能学院29栋服务器。</p>
  <p>我们将严格保障您的隐私安全，<a href="{$privacyUrl}" target="_blank" rel="noopener noreferrer">点击查看隐私政策</a>。</p>

  <div class="chat-notice-inner__thanks">
    <div class="chat-notice-inner__thanks-title">特别致谢：</div>
    <p>支持：学校信息中心 王文林老师</p>
    <p>支持：人工智能学院 周军老师</p>
    <p>总体协调：人工智能学院 宋林老师</p>
    <p>AI模型部署与技术人员：24级计算机应用技术一班 管乙聲</p>
  </div>

  <div class="chat-notice-inner__logos">
    <div class="chat-notice-inner__logo-item">
      <img src="{$logoMain}" alt="提供商">
      <p class="chat-notice-inner__logo-caption">提供商</p>
    </div>
    <div class="chat-notice-inner__logo-item">
      <img src="{$logoPartner}" alt="路南云">
      <p class="chat-notice-inner__logo-caption">技术支持</p>
    </div>
  </div>
  <p class="chat-notice-inner__footnote">技术支持路南云</p>
</div>
HTML;
}

/** 读取公告 HTML：数据库为空则返回内置默认 */
function chat_notice_html_resolved(string $base): string
{
    require_once __DIR__ . '/settings.php';
    $html = setting('chat_notice_html', '');
    if (trim($html) === '') {
        return chat_notice_default_html($base);
    }
    return $html;
}
