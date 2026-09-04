<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function lesson_plan_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS lesson_plan_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                course_name VARCHAR(255) NOT NULL DEFAULT \'\',
                model_name VARCHAR(160) NOT NULL DEFAULT \'\',
                lesson_count INT NOT NULL DEFAULT 0,
                input_json JSON NULL,
                error_text TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_lesson_plan_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable) {
        // 表不存在时不阻断主流程
    }
}

/** @return array{course_name:string,lesson_count:int} */
function lesson_plan_parse_payload(array $payload): array
{
    $courseName = '';
    $lessonCount = 0;
    try {
        $messages = $payload['messages'] ?? [];
        if (is_array($messages)) {
            $last = end($messages);
            $content = is_array($last) ? ($last['content'] ?? '') : '';
            if (is_string($content)) {
                $pos = mb_strpos($content, "输入数据如下：\n");
                if ($pos !== false) {
                    $jsonPart = mb_substr($content, $pos + mb_strlen("输入数据如下：\n"));
                    $inputData = json_decode($jsonPart, true);
                    if (is_array($inputData)) {
                        $courseName = (string) ($inputData['courseName'] ?? '');
                        $lessonCount = is_array($inputData['lessons'] ?? null)
                            ? count($inputData['lessons'])
                            : 0;
                    }
                }
            }
        }
    } catch (Throwable) {
        // ignore
    }

    return [
        'course_name'  => $courseName,
        'lesson_count' => $lessonCount,
    ];
}

function lesson_plan_record_start(int $userId, array $meta, array $payload, string $modelName): ?int
{
    lesson_plan_ensure_schema();
    try {
        $stmt = db()->prepare(
            'INSERT INTO lesson_plan_records (user_id, course_name, model_name, lesson_count, input_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            (string) ($meta['course_name'] ?? ''),
            $modelName,
            (int) ($meta['lesson_count'] ?? 0),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) db()->lastInsertId();
    } catch (Throwable) {
        return null;
    }
}

function lesson_plan_record_error(?int $recordId, string $error): void
{
    if ($recordId === null || $recordId <= 0) {
        return;
    }
    lesson_plan_ensure_schema();
    try {
        db()->prepare('UPDATE lesson_plan_records SET error_text = ? WHERE id = ?')
            ->execute([$error, $recordId]);
    } catch (Throwable) {
        // ignore
    }
}
