<?php
/**
 * WGPlus — Log API
 *
 * Отдаёт хвост лог-файла в JSON для страницы «Логи».
 *
 * GET:
 *   source — panel | health | events   (строго из whitelist, НЕ путь)
 *   lines  — 50..2000
 *
 * Безопасность: источник никогда не берётся из ввода как путь — только ключ
 * словаря. Иначе получили бы чтение произвольных файлов через ../.
 */

require_once __DIR__ . '/includes/wgp_helpers.php';

// Единая проверка: авторизация + таймауты + привязка к IP.
// Для AJAX отвечаем 403 JSON'ом вместо редиректа — fetch() не поймёт 302 на HTML.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (wgp_session_invalid_reason() !== '') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sources = [
    'panel'  => ['file' => WGP_LOG_PANEL,  'title' => 'Панель'],
    'health' => ['file' => WGP_LOG_HEALTH, 'title' => 'Healthcheck'],
    'events' => ['file' => WGP_LOG_EVENTS, 'title' => 'События'],
];

$key = $_GET['source'] ?? 'panel';
if (!isset($sources[$key])) $key = 'panel';

$lines = (int) ($_GET['lines'] ?? 200);
$lines = max(50, min(2000, $lines));

$file   = $sources[$key]['file'];
$exists = is_readable($file);
$rows   = $exists ? wgp_tail($file, $lines) : [];

echo json_encode([
    'ok'      => true,
    'source'  => $key,
    'title'   => $sources[$key]['title'],
    'file'    => $file,
    'exists'  => $exists,
    'size'    => $exists ? (int) @filesize($file) : 0,
    'count'   => count($rows),
    'lines'   => $rows,
    'fetched' => date('H:i:s'),
], JSON_UNESCAPED_UNICODE);
