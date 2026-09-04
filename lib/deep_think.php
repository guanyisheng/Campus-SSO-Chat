<?php
declare(strict_types=1);

/** 深度思考开关：请求参数与输出过滤 */

function deep_think_enabled_from_input(array $input): bool
{
    return !empty($input['deep_think']);
}

/** @param array<string, mixed> $payload */
function deep_think_apply_to_payload(array &$payload, bool $enabled, string $modelName = ''): void
{
    if ($enabled) {
        $payload['think'] = true;
        if (!isset($payload['options']) || !is_array($payload['options'])) {
            $payload['options'] = [];
        }
        $payload['options']['think'] = true;
        return;
    }

    unset($payload['think']);
    if (!isset($payload['options']) || !is_array($payload['options'])) {
        $payload['options'] = [];
    }
    $payload['think'] = false;
    $payload['options']['think'] = false;
    // OpenAI 兼容层（Ollama /v1）常用 extra_body 传递 think
    $payload['extra_body'] = ['think' => false];
    $payload['chat_template_kwargs'] = ['enable_thinking' => false];

    $model = strtolower($modelName);
    if (str_contains($model, 'deepseek-r1') || str_contains($model, 'deepseek_r1')) {
        // R1 蒸馏模型在 think:false 时仍可能输出推理，靠系统提示 + 输出过滤兜底
        $payload['temperature'] = min((float) ($payload['temperature'] ?? 0.7), 0.3);
    }
}

/** @param list<array{role:string,content:string}> $messages @return list<array{role:string,content:string}> */
function deep_think_adjust_messages(array $messages, bool $enabled): array
{
    if ($enabled) {
        return $messages;
    }

    $direct = '请直接给出简洁准确的最终答案。不要输出思考过程、推理步骤、内心独白或标签。';
    $userHint = '（无需深度思考，直接回答即可。）';
    $hasSystem = false;

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') !== 'user') {
            continue;
        }
        $content = trim((string) ($messages[$i]['content'] ?? ''));
        if ($content !== '' && !str_contains($content, '无需深度思考')) {
            $messages[$i]['content'] = $content . "\n\n" . $userHint;
        }
        break;
    }

    foreach ($messages as &$m) {
        if (($m['role'] ?? '') !== 'system') {
            continue;
        }
        $hasSystem = true;
        $content = trim((string) ($m['content'] ?? ''));
        if ($content !== '' && !str_contains($content, '不要输出思考过程')) {
            $m['content'] = $content . "\n\n" . $direct;
        }
    }
    unset($m);

    if (!$hasSystem) {
        array_unshift($messages, ['role' => 'system', 'content' => $direct]);
    }

    return $messages;
}

function deep_think_tag_open(): string
{
    return '<' . 'think' . '>';
}

function deep_think_tag_close(): string
{
    return '<' . '/think' . '>';
}

function deep_think_strip_tags(string $text): string
{
    if ($text === '') {
        return '';
    }
    $open = preg_quote(deep_think_tag_open(), '/');
    $close = preg_quote(deep_think_tag_close(), '/');
    $text = preg_replace('/' . $open . '.*?' . $close . '/is', '', $text) ?? $text;
    $text = preg_replace('/' . $open . '[^>]*>/i', '', $text) ?? $text;
    $text = preg_replace('/' . $close . '/i', '', $text) ?? $text;
    $text = preg_replace('/<reasoning[^>]*>.*?<\/reasoning>/is', '', $text) ?? $text;

    return trim($text);
}

/** 流式过滤：去掉 think 标签内容与 reasoning 字段 */
final class DeepThinkStreamFilter
{
    private string $buf = '';
    private bool $inThink = false;

    public function filterContent(string $chunk): string
    {
        if ($chunk === '') {
            return '';
        }
        $this->buf .= $chunk;
        $out = '';
        $open = deep_think_tag_open();
        $close = deep_think_tag_close();

        while ($this->buf !== '') {
            if ($this->inThink) {
                $end = stripos($this->buf, $close);
                if ($end === false) {
                    $this->buf = '';
                    break;
                }
                $this->buf = substr($this->buf, $end + strlen($close));
                $this->inThink = false;
                continue;
            }

            $start = stripos($this->buf, $open);
            if ($start === false) {
                $keep = strlen($open) - 1;
                $safeLen = max(0, strlen($this->buf) - $keep);
                if ($safeLen > 0) {
                    $out .= substr($this->buf, 0, $safeLen);
                    $this->buf = substr($this->buf, $safeLen);
                }
                break;
            }

            if ($start > 0) {
                $out .= substr($this->buf, 0, $start);
            }
            $this->buf = substr($this->buf, $start + strlen($open));
            $this->inThink = true;
        }

        return $out;
    }

    public function flush(): string
    {
        if ($this->inThink) {
            $this->buf = '';
            $this->inThink = false;
            return '';
        }
        $out = deep_think_strip_tags($this->buf);
        $this->buf = '';
        return $out;
    }
}
