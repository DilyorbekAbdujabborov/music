<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

function logError(string $message, $data = null): void {
    $logFile = __DIR__ . '/logs/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    if ($data !== null) {
        $logMessage .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $logMessage .= PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function logInfo(string $message, $data = null): void {
    $logFile = __DIR__ . '/logs/info.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    if ($data !== null) {
        $logMessage .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $logMessage .= PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

logError('=== BOT STARTED ===');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logError("PHP Error [$errno]: $errstr", ['file' => $errfile, 'line' => $errline]);
    return false;
});

set_exception_handler(function($e) {
    logError('UNCAUGHT EXCEPTION', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    echo "ERROR: " . $e->getMessage() . "\n";
});

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("{$key}={$value}");
            }
        }
    }
    logError('ENV loaded', ['BOT_TOKEN' => !empty(getenv('BOT_TOKEN')) ? 'SET' : 'MISSING']);
}

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('BOT_DUMP_CHAT', getenv('BOT_DUMP_CHAT') ?: '');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'Ritmchibot');
define('DB_HOST', getenv('DB_HOST') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('ITEMS_PER_PAGE', 48);

define('PAGE_SIZE', 10);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN);

logError('Config loaded', [
    'DB_HOST' => DB_HOST,
    'DB_USER' => DB_USER,
    'DB_NAME' => DB_NAME,
    'BOT_TOKEN_SET' => !empty(BOT_TOKEN),
    'BOT_DUMP_CHAT_SET' => !empty(BOT_DUMP_CHAT)
]);

$sourceDomains = [
    ['base' => 'https://eu.hitmo-top.com', 'download' => 'https://s2.deliciouspeaches.com'],
    ['base' => 'https://hitmo.top', 'download' => 'https://dl.hitmo.top'],
    ['base' => 'https://hitmo.me', 'download' => 'https://dl.hitmo.me'],
];
define('SOURCE_DOMAINS', $sourceDomains);
define('SOURCE_INDEX', 0);

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
];
define('USER_AGENTS', $userAgents);
