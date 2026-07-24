<?php
/**
 * WGPlus — журнал в JSON для страницы «Журнал».
 *
 * Разбирает строки общего лога вида
 *   [2026-07-24 15:30:12] [OK] [панель] Текст
 * и отдаёт готовыми полями, чтобы фронт не занимался парсингом.
 *
 * GET:
 *   lines — 50..3000
 *   only  — problems (только предупреждения и ошибки)
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (wgp_session_invalid_reason() !== '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Нужен вход в панель']);
    exit;
}

$lines = (int) ($_GET['lines'] ?? 500);
$lines = max(50, min(3000, $lines));
$only  = ($_GET['only'] ?? '') === 'problems';

// Уровень из лога -> класс оформления. Всё, что не опознано, остаётся нейтральным.
$levelMap = [
    'OK'   => 'ok',
    'INFO' => '',
    'WARN' => 'warn',
    'ERR'  => 'err',
    'CRIT' => 'err',
];

$rows = [];
foreach (wgp_tail(WGP_LOG_FILE, $lines) as $raw) {
    if (preg_match('/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.*)$/u', $raw, $m)) {
        $lvl = strtoupper(trim($m[2]));
        if ($only && !in_array($lvl, ['WARN', 'ERR', 'CRIT'], true)) continue;
        $rows[] = [
            'time'   => trim($m[1]),
            'level'  => $levelMap[$lvl] ?? '',
            'source' => trim($m[3]),
            'text'   => trim($m[4]),
        ];
    } else {
        // Строка без разметки — показываем как есть, чтобы ничего не потерять.
        if ($only) continue;
        $rows[] = ['time' => '', 'level' => '', 'source' => '', 'text' => $raw];
    }
}

echo json_encode([
    'ok'    => true,
    'rows'  => $rows,
    'count' => count($rows),
    'size'  => is_readable(WGP_LOG_FILE) ? (int) @filesize(WGP_LOG_FILE) : 0,
    'at'    => date('H:i:s'),
], JSON_UNESCAPED_UNICODE);
