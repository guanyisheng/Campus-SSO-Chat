<?php
declare(strict_types=1);

/**
 * 从上传文件中提取纯文本（PDF 仅文本层，不 OCR）
 */
function file_extract_text(string $path, string $ext): string
{
    $ext = strtolower($ext);
    if ($ext === 'txt' || $ext === 'csv') {
        $text = file_extract_txt($path);
    } elseif ($ext === 'pdf') {
        $text = file_extract_pdf($path);
    } elseif ($ext === 'docx') {
        $text = file_extract_docx($path);
    } elseif ($ext === 'xlsx') {
        $text = file_extract_xlsx($path);
    } elseif ($ext === 'xls') {
        $text = file_extract_xls($path);
    } else {
        throw new InvalidArgumentException('不支持的文件类型: ' . $ext);
    }

    $text = file_normalize_text($text);
    if ($text === '') {
        throw new RuntimeException('未能从文件中提取到文字（扫描版 PDF 需 OCR，本系统不支持）');
    }

    $max = defined('UPLOAD_MAX_TEXT_CHARS') ? UPLOAD_MAX_TEXT_CHARS : 48000;
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max) . "\n\n…（内容过长已截断）";
    }

    return $text;
}

function file_normalize_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function file_extract_txt(string $path): string
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('无法读取文件');
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'GB18030,GBK,UTF-8,ISO-8859-1');
    }
    return $raw;
}

function file_extract_pdf(string $path): string
{
    if (function_exists('shell_exec')) {
        $out = tempnam(sys_get_temp_dir(), 'pdf_') . '.txt';
        $cmd = 'pdftotext -enc UTF-8 -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>&1';
        @exec($cmd, $_, $code);
        if ($code === 0 && is_file($out)) {
            $text = file_get_contents($out);
            @unlink($out);
            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }
        @unlink($out);
    }

    return file_extract_pdf_fallback($path);
}

function file_extract_pdf_fallback(string $path): string
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('无法读取 PDF');
    }

    $text = '';

    if (preg_match_all('/\((?:\\\\.|[^\\\\])*?\)/s', $data, $m)) {
        foreach ($m[0] as $part) {
            $part = substr($part, 1, -1);
            $part = str_replace(['\\(', '\\)', '\\\\n', '\\r', '\\t'], ["(", ")", "\n", "\r", "\t"], $part);
            $part = stripcslashes($part);
            if (preg_match('/[\x20-\x7E\x{4e00}-\x{9fff}]/u', $part)) {
                $text .= $part . ' ';
            }
        }
    }

    if (preg_match_all('/<[0-9A-Fa-f\s]+>/', $data, $hexParts)) {
        foreach ($hexParts[0] as $hex) {
            $hex = trim($hex, '<>');
            $hex = preg_replace('/\s+/', '', $hex);
            if ($hex === null || strlen($hex) < 4 || strlen($hex) % 2 !== 0) {
                continue;
            }
            $bin = @hex2bin($hex);
            if ($bin === false) {
                continue;
            }
            if (preg_match('/[\x20-\x7E\x{4e00}-\x{9fff}]/u', $bin)) {
                $text .= $bin . ' ';
            }
        }
    }

    return $text;
}

function file_extract_docx(string $path): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('服务器未启用 ZipArchive，无法解析 docx');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('无法打开 docx');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false || $xml === '') {
        throw new RuntimeException('docx 内容为空');
    }

    $xml = str_replace('</w:p>', "\n", $xml);
    $xml = str_replace('</w:tr>', "\n", $xml);
    $xml = str_replace('<w:tab/>', "\t", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return $text;
}

function file_extract_xlsx(string $path): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('服务器未启用 ZipArchive，无法解析 xlsx');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('无法打开 xlsx');
    }

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $shared = file_xlsx_parse_shared_strings($ssXml);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#xl/worksheets/sheet\d+\.xml#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('xlsx 中未找到工作表');
    }

    return file_xlsx_sheet_to_text($sheetXml, $shared);
}

function file_xlsx_parse_shared_strings(string $xml): array
{
    $strings = [];
    if (preg_match_all('/<si>(.*?)<\/si>/s', $xml, $blocks)) {
        foreach ($blocks[1] as $block) {
            $t = '';
            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $block, $ts)) {
                foreach ($ts[1] as $piece) {
                    $t .= html_entity_decode($piece, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            $strings[] = $t;
        }
    }
    return $strings;
}

function file_xlsx_sheet_to_text(string $xml, array $shared): string
{
    $rows = [];
    if (!preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rowBlocks)) {
        return '';
    }

    foreach ($rowBlocks[1] as $rowXml) {
        $cells = [];
        if (preg_match_all('/<c([^>]*)>(?:<v>(.*?)<\/v>)?/s', $rowXml, $cellM, PREG_SET_ORDER)) {
            foreach ($cellM as $c) {
                $attrs = $c[1];
                $val = $c[2] ?? '';
                if (strpos($attrs, 't="s"') !== false && $val !== '' && isset($shared[(int) $val])) {
                    $cells[] = $shared[(int) $val];
                } elseif ($val !== '') {
                    $cells[] = $val;
                }
            }
        }
        if ($cells !== []) {
            $rows[] = implode("\t", $cells);
        }
    }

    return implode("\n", $rows);
}

function file_extract_xls(string $path): string
{
    // 旧版 .xls：尝试当作文本或提示转 xlsx
    $head = file_get_contents($path, false, null, 0, 8);
    if ($head !== false && strpos($head, "\xD0\xCF\x11\xE0") === 0) {
        throw new RuntimeException('旧版 .xls 请另存为 .xlsx 后上传');
    }
    return file_extract_txt($path);
}

function file_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('上传失败，错误码: ' . ($file['error'] ?? 'unknown'));
    }

    $max = defined('UPLOAD_MAX_BYTES') ? UPLOAD_MAX_BYTES : 10485760;
    if (($file['size'] ?? 0) > $max) {
        throw new RuntimeException('文件过大，最大 ' . round($max / 1048576, 1) . ' MB');
    }

    $name = (string) ($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = defined('UPLOAD_ALLOWED_EXT') ? UPLOAD_ALLOWED_EXT : ['txt', 'pdf', 'docx', 'xlsx'];

    if (!in_array($ext, $allowed, true)) {
        throw new InvalidArgumentException('仅支持: ' . implode(', ', $allowed));
    }

    return ['name' => $name, 'ext' => $ext, 'tmp' => (string) $file['tmp_name']];
}
