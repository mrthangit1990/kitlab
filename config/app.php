<?php
return [
 'name' => env('APP_NAME', 'KITLAB'),
 'env' => env('APP_ENV', 'production'),
 'debug' => (bool) env('APP_DEBUG', false),
 'url' => env('APP_URL', 'http://127.0.0.1:8000'),
 'timezone' => 'Asia/Ho_Chi_Minh',
 'locale' => env('APP_LOCALE', 'vi'),
 'fallback_locale' => 'en',
 'faker_locale' => 'en_US',
 'cipher' => 'AES-256-CBC',
 'key' => env('APP_KEY'),
];
