<?php
return [
    'site_name' => 'Zln Garage',
    'site_url' => 'https://rombensonn.github.io/zln-garage-landing/',
    'phone' => '+7 (925) 053-88-33',
    'timezone' => 'Europe/Moscow',

    // Email-настройки: замените адреса на реальные перед запуском.
    'email_enabled' => false,
    'email_to' => 'leads@example.com',
    'email_from' => 'no-reply@example.com',
    'email_subject' => 'Новая заявка с сайта Zln Garage',

    // Telegram-настройки: токен и chat_id храните только на сервере.
    'telegram_enabled' => false,
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',

    'rate_limit_seconds' => 60,
    'min_form_seconds' => 3,
    'log_file' => __DIR__ . '/leads.log',
    'rate_file' => __DIR__ . '/rate-limit.json',
];
