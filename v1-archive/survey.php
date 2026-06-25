<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo '{"ok":false}'; exit; }
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$station = substr(strip_tags($data['station'] ?? $_POST['station'] ?? ''), 0, 120);
$rating  = (int)($data['rating'] ?? $_POST['rating'] ?? 999);
if (!in_array($rating, [-1, 0, 1])) { echo '{"ok":false}'; exit; }
$line = implode('|', [date('Y-m-d H:i:s'), $rating, str_replace('|','%7C',$station), $_SERVER['REMOTE_ADDR']??'-']) . "\n";
@file_put_contents(__DIR__ . '/data/survey.csv', $line, FILE_APPEND | LOCK_EX);
echo '{"ok":true}';
