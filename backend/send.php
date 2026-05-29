<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

function respond(bool $success, string $message): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_string(string $value, int $maxLength): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[^\P{C}\t\r\n]+/u', '', $value) ?? '';
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function post_value(string $key, int $maxLength): string
{
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        $value = '';
    }
    return clean_string((string)$value, $maxLength);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip) ?: 'unknown';
}

function check_rate_limit(string $file, string $ip, int $seconds): bool
{
    $now = time();
    $data = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    foreach ($data as $storedIp => $timestamp) {
        if (!is_int($timestamp) && !ctype_digit((string)$timestamp)) {
            unset($data[$storedIp]);
            continue;
        }
        if ($now - (int)$timestamp > 86400) {
            unset($data[$storedIp]);
        }
    }

    if (isset($data[$ip]) && $now - (int)$data[$ip] < $seconds) {
        return false;
    }

    $data[$ip] = $now;
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return true;
}

function build_message(array $lead, array $config): string
{
    return "Новая заявка: {$config['site_name']}\n"
        . "Имя: {$lead['name']}\n"
        . "Телефон: {$lead['phone']}\n"
        . "Услуга: {$lead['service']}\n"
        . "Комментарий: {$lead['message']}\n"
        . "Страница: {$lead['page_url']}\n"
        . "Форма: {$lead['form_name']}\n"
        . "IP: {$lead['ip']}\n"
        . "Дата: {$lead['date']}";
}

function send_email(array $config, string $message): bool
{
    if (empty($config['email_enabled'])) {
        return true;
    }
    $to = (string)($config['email_to'] ?? '');
    $from = (string)($config['email_from'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = '=?UTF-8?B?' . base64_encode((string)$config['email_subject']) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $from,
    ];

    return mail($to, $subject, $message, implode("\r\n", $headers));
}

function send_telegram(array $config, string $message): bool
{
    if (empty($config['telegram_enabled'])) {
        return true;
    }
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    $chatId = trim((string)($config['telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '' || !function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => 'true',
        ],
    ]);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $result !== false && $httpCode >= 200 && $httpCode < 300;
}

function log_lead(string $file, array $lead): void
{
    $line = implode(' | ', [
        $lead['date'],
        $lead['ip'],
        $lead['phone'],
        $lead['service'],
        $lead['form_name'],
    ]);
    file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}

$ip = client_ip();
if (!check_rate_limit((string)$config['rate_file'], $ip, (int)$config['rate_limit_seconds'])) {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}

$honeypot = post_value('honeypot', 120);
if ($honeypot !== '') {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}

$startedAt = (int)post_value('form_started_at', 20);
if ($startedAt <= 0 || time() - $startedAt < (int)$config['min_form_seconds']) {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}

$allowedServices = [
    'Базовое обслуживание',
    'Шиномонтаж',
    'Ремонт двигателя',
    'Консультация',
    'Другое',
];

$lead = [
    'name' => post_value('name', 80),
    'phone' => post_value('phone', 40),
    'service' => post_value('service', 80),
    'message' => post_value('message', 800),
    'page_url' => post_value('page_url', 250),
    'form_name' => post_value('form_name', 120),
    'ip' => $ip,
    'date' => date('Y-m-d H:i:s'),
];

$phoneDigits = preg_replace('/\D+/', '', $lead['phone']);
if ($lead['name'] !== '' && (function_exists('mb_strlen') ? mb_strlen($lead['name'], 'UTF-8') < 2 : strlen($lead['name']) < 2)) {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}
if ($lead['phone'] === '' || strlen((string)$phoneDigits) < 10) {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}
if ($lead['service'] === '' || !in_array($lead['service'], $allowedServices, true)) {
    respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');
}

$message = build_message($lead, $config);
$emailOk = send_email($config, $message);
$telegramOk = send_telegram($config, $message);
log_lead((string)$config['log_file'], $lead);

if ($emailOk && $telegramOk) {
    respond(true, 'Заявка отправлена. Мы свяжемся с вами для уточнения записи.');
}

respond(false, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.');

