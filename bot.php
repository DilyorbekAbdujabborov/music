<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// Logs
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$errorLog = $logDir . '/error.log';
$infoLog = $logDir . '/info.log';

function lg($msg, $data = null) {
    global $errorLog;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . ($data ? ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    file_put_contents($errorLog, $line, FILE_APPEND);
    
    if (defined('DUMP_CHAT') && DUMP_CHAT && strlen($msg) > 0) {
        $url = 'https://api.telegram.org/bot' . API_KEY . '/sendMessage';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => DUMP_CHAT,
                'text' => "<code>" . $line . "</code>",
                'parse_mode' => 'HTML'
            ],
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

lg('=== BOT STARTED ===');

set_error_handler(function($e, $str, $file, $line) {
    lg("PHP ERROR [$e]: $str", ['file' => $file, 'line' => $line]);
});
set_exception_handler(function($e) {
    lg('EXCEPTION', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
});

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) {
        if (strpos(trim($l), '#') === 0) continue;
        if (strpos($l, '=') !== false) {
            list($k, $v) = explode('=', $l, 2);
            $k = trim($k);
            $v = trim($v);
            if (!getenv($k)) putenv("$k=$v");
        }
    }
}

define('API_KEY', getenv('BOT_TOKEN') ?: '');
define('ADMIN', getenv('ADMIN_ID') ?: '');
define('BOT_USER', getenv('BOT_USERNAME') ?: 'Ritmchibot');
define('DUMP_CHAT', getenv('BOT_DUMP_CHAT') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('PROXY', getenv('PROXY') ?: '');
define('PAGE_SIZE', 10);
define('ITEMS_PER_PAGE', 48);

lg('Config loaded', [
    'API_KEY_SET' => !empty(API_KEY),
    'DB_HOST' => DB_HOST,
    'DB_USER' => DB_USER
]);

// Bot function
function bot($method, $datas = []) {
    $url = 'https://api.telegram.org/bot' . API_KEY . '/' . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => !empty($datas),
        CURLOPT_POSTFIELDS => $datas,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        lg('CURL ERROR', ['method' => $method, 'error' => $err]);
        return null;
    }
    return json_decode($res, true);
}

function botJson($method, $datas = []) {
    $url = 'https://api.telegram.org/bot' . API_KEY . '/' . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($datas),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        lg('CURL ERROR', ['method' => $method, 'error' => $err]);
        return null;
    }
    return json_decode($res, true);
}

// Get input
$update = json_decode(file_get_contents('php://input'), true);

// Variables
$message = $update['message'] ?? [];
$callback_query = $update['callback_query'] ?? [];
$inline_query = $update['inline_query'] ?? [];

$chat_id = $message['chat']['id'] ?? 0;
$cid = $chat_id;
$mid = $message['message_id'] ?? 0;
$text = $message['text'] ?? '';
$tx = $text;
$cty = $message['chat']['type'] ?? 'private';
$type = $cty;
$uid = $message['from']['id'] ?? $callback_query['from']['id'] ?? $inline_query['from']['id'] ?? 0;
$ismi = $message['from']['first_name'] ?? $callback_query['from']['first_name'] ?? '';
$ismi2 = $message['from']['last_name'] ?? $callback_query['from']['last_name'] ?? '';
$username = $message['from']['username'] ?? $callback_query['from']['username'] ?? '';
$name = "<a href='tg://user?id=$uid'>$ismi $ismi2</a>";

// Callback variables
$cb_data = $callback_query['data'] ?? '';
$cb_id = $callback_query['id'] ?? '';
$cb_uid = $callback_query['from']['id'] ?? 0;
$cb_cid = $callback_query['message']['chat']['id'] ?? 0;
$cb_mid = $callback_query['message']['message_id'] ?? 0;
$inline_msg_id = $callback_query['inline_message_id'] ?? '';

// Inline variables
$inline_id = $inline_query['id'] ?? '';
$inline_query_text = trim($inline_query['query'] ?? '');
$inline_offset = isset($inline_query['offset']) && (int)$inline_query['offset'] > 0 ? (int)$inline_query['offset'] : 1;

// Database
$pdo = null;
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    lg('DB Connected');
    
    // Auto create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tracks` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `md5` VARCHAR(32) NOT NULL UNIQUE,
            `title` VARCHAR(255) NOT NULL,
            `artist` VARCHAR(255) NOT NULL,
            `source_url` TEXT,
            `download_url` TEXT,
            `img` TEXT,
            `file_id` VARCHAR(255) DEFAULT NULL,
            `file_id_saved_at` DATETIME DEFAULT NULL,
            `id3_tagged` TINYINT(1) DEFAULT 0,
            `downloads` INT UNSIGNED DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_md5` (`md5`),
            INDEX `idx_file_id` (`file_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `telegram_id` BIGINT UNSIGNED NOT NULL UNIQUE,
            `first_name` VARCHAR(255) DEFAULT NULL,
            `username` VARCHAR(255) DEFAULT NULL,
            `total_searches` INT UNSIGNED DEFAULT 0,
            `total_downloads` INT UNSIGNED DEFAULT 0,
            `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_telegram_id` (`telegram_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `search_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `query` VARCHAR(255) NOT NULL,
            `results_count` INT UNSIGNED DEFAULT 0,
            `source` ENUM('direct', 'inline') DEFAULT 'direct',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_query` (`query`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ads` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NOT NULL,
            `url` VARCHAR(500) DEFAULT '',
            `ad_type` ENUM('text', 'photo', 'video', 'gif', 'banner') DEFAULT 'text',
            `status` ENUM('pending', 'active', 'paused', 'expired', 'rejected') DEFAULT 'pending',
            `start_at` DATETIME NOT NULL,
            `end_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    lg('DB Tables initialized');
} catch (PDOException $e) {
    lg('DB Connection FAILED', ['error' => $e->getMessage()]);
}

// Caches
$trackCache = [];
$fileIdCache = [];

// Helpers
function md5hash($url) {
    return md5($url);
}

function cacheTrack($track, $key) {
    global $trackCache;
    $trackCache[$key] = $track;
}

function getCachedTrack($key) {
    global $trackCache;
    return $trackCache[$key] ?? null;
}

function cacheFileId($md5, $fileId) {
    global $fileIdCache;
    $fileIdCache[$md5] = $fileId;
}

function hasFileId($md5) {
    global $fileIdCache;
    return isset($fileIdCache[$md5]);
}

function getFileId($md5) {
    global $fileIdCache;
    return $fileIdCache[$md5] ?? null;
}

// DB functions
function upsertUser($pdo, $from) {
    $stmt = $pdo->prepare("
        INSERT INTO `users` (telegram_id, first_name, username, last_seen)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            username = VALUES(username),
            last_seen = NOW()
    ");
    $stmt->execute([
        $from['id'] ?? 0,
        $from['first_name'] ?? null,
        $from['username'] ?? null
    ]);
}

function logSearch($pdo, $userId, $query, $count, $source = 'direct') {
    $pdo->prepare("UPDATE `users` SET total_searches = total_searches + 1 WHERE telegram_id = ?")->execute([$userId]);
    $pdo->prepare("INSERT INTO `search_logs` (user_id, query, results_count, source) VALUES (?, ?, ?, ?)")->execute([$userId, $query, $count, $source]);
}

function logDownload($pdo, $userId, $md5) {
    $pdo->prepare("UPDATE `users` SET total_downloads = total_downloads + 1 WHERE telegram_id = ?")->execute([$userId]);
    $pdo->prepare("UPDATE `tracks` SET downloads = downloads + 1 WHERE md5 = ?")->execute([$md5]);
}

function getFileIdFromDB($pdo, $md5) {
    $stmt = $pdo->prepare("
        SELECT file_id FROM `tracks`
        WHERE md5 = ? AND file_id IS NOT NULL AND id3_tagged = 1
        AND file_id_saved_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 1
    ");
    $stmt->execute([$md5]);
    $row = $stmt->fetch();
    return $row ? $row['file_id'] : null;
}

function saveTrack($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO `tracks` (md5, title, artist, source_url, download_url, img, file_id, file_id_saved_at, id3_tagged)
        VALUES (:md5, :title, :artist, :source_url, :download_url, :img, :file_id, :file_id_saved_at, :id3_tagged)
        ON DUPLICATE KEY UPDATE
            file_id = COALESCE(VALUES(file_id), file_id),
            file_id_saved_at = COALESCE(VALUES(file_id_saved_at), file_id_saved_at)
    ");
    $stmt->execute([
        'md5' => $data['md5'],
        'title' => $data['title'],
        'artist' => $data['artist'],
        'source_url' => $data['source_url'] ?? null,
        'download_url' => $data['download_url'] ?? null,
        'img' => $data['img'] ?? null,
        'file_id' => $data['file_id'] ?? null,
        'file_id_saved_at' => $data['file_id_saved_at'] ?? null,
        'id3_tagged' => $data['id3_tagged'] ?? 0,
    ]);
}

// Scraper
$SOURCE_DOMAINS = [
    ['base' => 'https://eu.hitmo-top.com', 'download' => 'https://s2.deliciouspeaches.com'],
    ['base' => 'https://hitmo.top', 'download' => 'https://dl.hitmo.top'],
    ['base' => 'https://hitmo.me', 'download' => 'https://dl.hitmo.me'],
    ['base' => 'https://www.mp3juice.cc', 'download' => 'https://www.mp3juice.cc'],
];

$USER_AGENTS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:123.0) Gecko/20100101 Firefox/123.0',
    'Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
];

function getUA() {
    global $USER_AGENTS;
    return $USER_AGENTS[array_rand($USER_AGENTS)];
}

function httpRequest($url, $retries = 3) {
    global $PROXY;
    $baseDelay = 1500;
    
    for ($i = 0; $i <= $retries; $i++) {
        $ch = curl_init();
        $ua = getUA();
        
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                "User-Agent: $ua",
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,ru;q=0.8,uz;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Cache-Control: max-age=0',
                'DNT: 1',
            ],
        ];
        
        // Proxy qo'shish
        if (!empty($PROXY)) {
            $opts[CURLOPT_PROXY] = PROXY;
            $opts[CURLOPT_HTTPPROXYTUNNEL] = 1;
        }
        
        curl_setopt_array($ch, $opts);
        
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        // Decompress
        if ($data && strlen($data) > 0 && strpos($data, "\x1f\x8b") === 0) {
            $data = @gzdecode($data);
        }
        
        if ($data && $code === 200 && strlen($data) > 100) {
            return ['ok' => true, 'data' => $data];
        }
        
        if ($i < $retries) {
            $delay = ($baseDelay * pow(2, $i) + rand(0, 1000)) / 1000;
            lg("Retry $i for " . substr($url, 0, 50) . "... after {$delay}s", ['code' => $code, 'proxy' => !empty($PROXY)]);
            sleep((int)$delay);
        }
    }
    
    return ['ok' => false, 'error' => $err ?: "HTTP $code"];
}

function downloadFile($url, $retries = 2) {
    global $PROXY;
    for ($i = 0; $i <= $retries; $i++) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . getUA(),
                'Referer: https://hitmo.top/',
            ],
        ];
        
        if (!empty($PROXY)) {
            $opts[CURLOPT_PROXY] = PROXY;
            $opts[CURLOPT_HTTPPROXYTUNNEL] = 1;
        }
        
        curl_setopt_array($ch, $opts);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($data && $code === 200 && strlen($data) > 1000) {
            return ['ok' => true, 'data' => $data];
        }
        
        if ($i < $retries) {
            sleep(pow(2, $i));
        }
    }
    
    return ['ok' => false, 'error' => $err ?? 'Download failed'];
}

function scrapePage($query, $page = 1, $domain = null) {
    global $SOURCE_DOMAINS;
    
    if (!$domain) {
        $domain = $SOURCE_DOMAINS[0];
    }
    
    $base = $domain['base'];
    $download = $domain['download'];
    $start = ($page - 1) * ITEMS_PER_PAGE;
    $q = urlencode($query);
    $url = $start === 0 ? "$base/search?q=$q" : "$base/search/start/$start?q=$q";
    
    lg("Scraping $base for: $query page $page");
    
    $result = httpRequest($url);
    
    if (!$result['ok']) {
        lg("Scrape failed: $base", ['error' => $result['error']]);
        
        foreach ($SOURCE_DOMAINS as $fallback) {
            if ($fallback['base'] !== $domain['base']) {
                lg("Trying fallback: " . $fallback['base']);
                try {
                    return scrapePage($query, $page, $fallback);
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        
        throw new Exception("Scraping failed: " . ($result['error'] ?? 'Unknown'));
    }
    
    $html = $result['data'];
    $tracks = [];
    
    preg_match_all('/data-musmeta=\'([^\']+)\'/', $html, $matches);
    
    foreach ($matches[1] as $jsonStr) {
        $meta = json_decode(html_entity_decode($jsonStr), true);
        if (!$meta || empty($meta['title'])) continue;
        
        $downloadUrl = str_replace(
            ['eu.hitmotop.com', 's2.deliciouspeaches.com', 'dl.hitmo.top', 'dl.hitmo.me'],
            [$download, $download, $download, $download],
            $meta['url'] ?? ''
        );
        
        $tracks[] = [
            'title' => $meta['title'],
            'artist' => $meta['artist'],
            'url' => $meta['url'],
            'img' => $meta['img'] ?? null,
            'download_url' => $downloadUrl,
        ];
    }
    
    $pagination = ['currentPage' => 1, 'totalPages' => 1];
    if (preg_match('/pagination__item active[^>]*>.*?<b>([\d]+)<\/b>/s', $html, $m)) {
        $pagination['currentPage'] = (int)$m[1];
    }
    
    lg("Found " . count($tracks) . " tracks from $base");
    
    return ['tracks' => $tracks, 'pagination' => $pagination, 'domain' => $domain];
}

// Keyboard builders
function buildKeyboard($tracks, $query, $page, $totalPages) {
    $rows = [];
    
    $row1 = [];
    for ($i = 0; $i < 5 && $i < count($tracks); $i++) {
        $key = $tracks[$i]['key'];
        $ready = hasFileId($key) ? '✅' : '';
        $row1[] = ['text' => $ready . ($i + 1), 'callback_data' => "dl:$key"];
    }
    if ($row1) $rows[] = $row1;
    
    $row2 = [];
    for ($i = 5; $i < 10 && $i < count($tracks); $i++) {
        $key = $tracks[$i]['key'];
        $ready = hasFileId($key) ? '✅' : '';
        $row2[] = ['text' => $ready . ($i + 1), 'callback_data' => "dl:$key"];
    }
    if ($row2) $rows[] = $row2;
    
    $navRow = [];
    if ($page > 1) {
        $navRow[] = ['text' => '◀️', 'callback_data' => "more:" . urlencode($query) . ":" . ($page - 1)];
    } else {
        $navRow[] = ['text' => '◀️', 'callback_data' => 'noop'];
    }
    $navRow[] = ['text' => '✖️', 'callback_data' => 'close'];
    if ($page < $totalPages) {
        $navRow[] = ['text' => '▶️', 'callback_data' => "more:" . urlencode($query) . ":" . ($page + 1)];
    } else {
        $navRow[] = ['text' => '▶️', 'callback_data' => 'noop'];
    }
    $rows[] = $navRow;
    
    $rows[] = [['text' => '🔍 Yangi qidiruv', 'switch_inline_query_current_chat' => '']];
    $rows[] = [['text' => "🌐 Do'stga ulash", 'switch_inline_query' => $query]];
    
    return $rows;
}

function buildSearchMessage($tracks, $query, $page, $totalPages) {
    $list = '';
    foreach ($tracks as $i => $t) {
        $ready = hasFileId($t['key']) ? '✅ ' : '';
        $list .= ($i + 1) . ". {$ready}{$t['artist']} — {$t['title']}\n";
    }
    
    $pageInfo = $totalPages > 1 ? "📄 <b>{$page}/{$totalPages}</b> sahifa\n\n" : "\n";
    return "🎶 <b>{$query}</b> — natijalar\n{$pageInfo}{$list}";
}

function buildTrackResult($track, $key, $query) {
    $cachedFileId = getFileId($key);
    
    if ($cachedFileId) {
        return [
            'type' => 'audio',
            'id' => $key,
            'audio_file_id' => $cachedFileId,
            'title' => $track['title'],
            'performer' => $track['artist'],
            'caption' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>",
            'parse_mode' => 'HTML',
        ];
    }
    
    return [
        'type' => 'article',
        'id' => $key,
        'title' => "🎵 {$track['title']}",
        'description' => "👤 {$track['artist']} · ⏳ Yuklanadi",
        'thumb_url' => $track['img'] ?? null,
        'input_message_content' => [
            'message_text' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>",
            'parse_mode' => 'HTML',
        ],
        'reply_markup' => [
            'inline_keyboard' => [
                [['text' => '⬇️ Yuklab olish', 'callback_data' => "dl:$key"]],
                [['text' => '🔍 Bu chatda', 'switch_inline_query_current_chat' => '']],
            ],
        ],
    ];
}

// Upload file
function uploadFile($track, $md5) {
    global $pdo;
    $audioResult = downloadFile($track['download_url']);
    
    if (!$audioResult['ok']) {
        lg("Download failed: " . $audioResult['error']);
        return null;
    }
    
    $filename = preg_replace('/[\\/:"*?<>|]/', '', "{$track['artist']} - {$track['title']}.mp3");
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tempFile, $audioResult['data']);
    
    $sent = bot('sendAudio', [
        'chat_id' => DUMP_CHAT,
        'audio' => new CURLFile($tempFile),
        'title' => $track['title'],
        'performer' => $track['artist'],
    ]);
    
    @unlink($tempFile);
    
    if (!$sent || !($sent['ok'] ?? false)) {
        lg("Upload failed", ['result' => $sent ?? 'null']);
        return null;
    }
    
    $fileId = $sent['result']['audio']['file_id'] ?? null;
    
    if ($fileId) {
        cacheFileId($md5, $fileId);
        if ($pdo) {
            saveTrack($pdo, [
                'md5' => $md5,
                'title' => $track['title'],
                'artist' => $track['artist'],
                'source_url' => $track['url'] ?? null,
                'download_url' => $track['download_url'] ?? null,
                'img' => $track['img'] ?? null,
                'file_id' => $fileId,
                'file_id_saved_at' => date('Y-m-d H:i:s'),
                'id3_tagged' => 1,
            ]);
        }
    }
    
    return $fileId;
}

function getOrUploadFileId($track, $md5) {
    global $pdo;
    if (hasFileId($md5)) {
        return getFileId($md5);
    }
    
    if ($pdo) {
        $dbFileId = getFileIdFromDB($pdo, $md5);
        if ($dbFileId) {
            cacheFileId($md5, $dbFileId);
            return $dbFileId;
        }
    }
    
    return uploadFile($track, $md5);
}

// Progress frames
$PROGRESS = [
    '⬜⬜⬜⬜⬜⬜⬜⬜⬜⬜  0%',
    '🟩⬜⬜⬜⬜⬜⬜⬜⬜⬜ 10%',
    '🟩🟩⬜⬜⬜⬜⬜⬜⬜⬜ 20%',
    '🟩🟩🟩⬜⬜⬜⬜⬜⬜⬜ 30%',
    '🟩🟩🟩🟩⬜⬜⬜⬜⬜⬜ 40%',
    '🟩🟩🟩🟩🟩⬜⬜⬜⬜⬜ 50%',
    '🟩🟩🟩🟩🟩🟩⬜⬜⬜⬜ 60%',
    '🟩🟩🟩🟩🟩🟩🟩⬜⬜⬜ 70%',
    '🟩🟩🟩🟩🟩🟩🟩🟩⬜⬜ 80%',
    '🟩🟩🟩🟩🟩🟩🟩🟩🟩⬜ 90%',
    '🟩🟩🟩🟩🟩🟩🟩🟩🟩🟩 100%',
];

// =====================
// HANDLERS
// =====================

// Callback Query Handler
if ($cb_id) {
    lg("Callback: $cb_data from $cb_uid");
    
    if ($pdo) upsertUser($pdo, $callback_query['from']);
    
    bot('answerCallbackQuery', [
        'callback_query_id' => $cb_id,
        'text' => '⏳ Yuklanmoqda...',
    ]);
    
    if (strpos($cb_data, 'dl:') === 0) {
        $md5 = substr($cb_data, 3);
        $track = getCachedTrack($md5);
        
        if (!$track && $pdo) {
            $stmt = $pdo->prepare("SELECT * FROM `tracks` WHERE md5 = ?");
            $stmt->execute([$md5]);
            $row = $stmt->fetch();
            if ($row) {
                $track = [
                    'title' => $row['title'],
                    'artist' => $row['artist'],
                    'url' => $row['source_url'],
                    'download_url' => $row['download_url'],
                    'img' => $row['img'],
                ];
                cacheTrack($track, $md5);
            }
        }
        
        if ($track) {
            if ($pdo) logDownload($pdo, $cb_uid, $md5);
            
            if ($inline_msg_id) {
                $fileId = getOrUploadFileId($track, $md5);
                if ($fileId) {
                    bot('editMessageMedia', [
                        'inline_message_id' => $inline_msg_id,
                        'media' => json_encode([
                            'type' => 'audio',
                            'media' => $fileId,
                            'title' => $track['title'],
                            'performer' => $track['artist'],
                            'caption' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>",
                            'parse_mode' => 'HTML',
                        ]),
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '🎵 Botda qidirish', 'url' => "https://t.me/" . BOT_USER]],
                            ],
                        ]),
                    ]);
                }
            } else {
                botJson('editMessageText', [
                    'chat_id' => $cb_cid,
                    'message_id' => $cb_mid,
                    'text' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>\n{$PROGRESS[0]}",
                    'parse_mode' => 'HTML',
                ]);
                
                $fileId = getOrUploadFileId($track, $md5);
                
                if ($fileId) {
                    botJson('editMessageText', [
                        'chat_id' => $cb_cid,
                        'message_id' => $cb_mid,
                        'text' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>\n{$PROGRESS[10]}",
                        'parse_mode' => 'HTML',
                    ]);
                    
                    botJson('sendAudio', [
                        'chat_id' => $cb_cid,
                        'audio' => $fileId,
                        'title' => $track['title'],
                        'performer' => $track['artist'],
                        'caption' => "🎵 <b>{$track['artist']}</b> — <b>{$track['title']}</b>",
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '🔍 Yana qidirish', 'switch_inline_query_current_chat' => '']],
                                [['text' => "Kanalga o'tish", 'url' => 'https://t.me/RitmchiUz']],
                            ],
                        ]),
                    ]);
                    
                    bot('deleteMessage', ['chat_id' => $cb_cid, 'message_id' => $cb_mid]);
                } else {
                    botJson('editMessageText', [
                        'chat_id' => $cb_cid,
                        'message_id' => $cb_mid,
                        'text' => "❌ Yuklashda xato. Qayta urinib ko'ring.",
                        'parse_mode' => 'HTML',
                    ]);
                }
            }
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cb_id,
                'text' => "⚠️ Qo'shiq topilmadi",
                'show_alert' => true,
            ]);
        }
    } elseif (strpos($cb_data, 'more:') === 0) {
        $parts = explode(':', $cb_data, 3);
        $query = urldecode($parts[1] ?? '');
        $page = (int)($parts[2] ?? 1);
        
        $result = scrapePage($query, $page);
        $tracks = $result['tracks'];
        $shown = array_slice($tracks, 0, PAGE_SIZE);
        
        foreach ($shown as $i => $t) {
            $key = md5hash($t['url']);
            $shown[$i]['key'] = $key;
            cacheTrack($t, $key);
        }
        
        botJson('editMessageText', [
            'chat_id' => $cb_cid,
            'message_id' => $cb_mid,
            'text' => buildSearchMessage($shown, $query, $page, $result['pagination']['totalPages']),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => buildKeyboard($shown, $query, $page, $result['pagination']['totalPages'])]),
        ]);
        
        bot('answerCallbackQuery', ['callback_query_id' => $cb_id]);
    } elseif ($cb_data === 'close') {
        bot('deleteMessage', ['chat_id' => $cb_cid, 'message_id' => $cb_mid]);
        bot('answerCallbackQuery', ['callback_query_id' => $cb_id]);
    } elseif ($cb_data === 'noop') {
        bot('answerCallbackQuery', ['callback_query_id' => $cb_id]);
    }
}

// Inline Query Handler
elseif ($inline_id) {
    lg("Inline: $inline_query_text from $uid");
    
    if ($pdo) upsertUser($pdo, $inline_query['from']);
    
    if (empty($inline_query_text)) {
        bot('answerInlineQuery', [
            'inline_query_id' => $inline_id,
            'results' => json_encode([[
                'type' => 'article',
                'id' => 'ad_placeholder',
                'title' => '📢 Bu yerda sizning reklamangiz',
                'description' => 'Reklama joylashtirish uchun /adinfo',
                'input_message_content' => [
                    'message_text' => "📢 Bu yerda reklama bo'lishi mumkin!",
                    'parse_mode' => 'HTML',
                ],
            ]]),
            'cache_time' => 0,
        ]);
        ob_end_flush();
        exit;
    }
    
    try {
        $result = scrapePage($inline_query_text, $inline_offset);
        $tracks = $result['tracks'];
        
        if ($pdo) logSearch($pdo, $uid, $inline_query_text, count($tracks), 'inline');
        
        if (empty($tracks)) {
            bot('answerInlineQuery', [
                'inline_query_id' => $inline_id,
                'results' => json_encode([]),
                'cache_time' => 5,
            ]);
        } else {
            $uniqueTracks = [];
            $seen = [];
            foreach ($tracks as $t) {
                $key = md5hash($t['url']);
                if (!in_array($key, $seen)) {
                    $seen[] = $key;
                    $t['key'] = $key;
                    $uniqueTracks[] = $t;
                    cacheTrack($t, $key);
                }
            }
            
            $trackResults = array_map(fn($t) => buildTrackResult($t, $t['key'], $inline_query_text), $uniqueTracks);
            
            $nextOffset = $inline_offset < $result['pagination']['totalPages'] ? (string)($inline_offset + 1) : '';
            
            bot('answerInlineQuery', [
                'inline_query_id' => $inline_id,
                'results' => json_encode(array_values($trackResults)),
                'next_offset' => $nextOffset,
                'cache_time' => 300,
                'is_personal' => false,
            ]);
        }
    } catch (Exception $e) {
        lg("Inline error: " . $e->getMessage());
        bot('answerInlineQuery', [
            'inline_query_id' => $inline_id,
            'results' => json_encode([]),
            'cache_time' => 5,
        ]);
    }
}

// Message Handler
elseif ($cid && $uid && $text) {
    lg("Message: $text from $uid in $cid");
    
    if ($pdo) upsertUser($pdo, $message['from']);
    
    // /start
    if (strpos($text, '/start') === 0) {
        botJson('sendMessage', [
            'chat_id' => $cid,
            'text' => "👋 Salom, <b>{$ismi}</b>!\n\n🎵 Men musiqa qidiruv botiman.\n\n<b>Foydalanish:</b>\n• Qo'shiq nomini yozing\n• Inline: <code>@" . BOT_USER . " qo'shiq</code>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🔍 Bu chatda qidirish', 'switch_inline_query_current_chat' => '']],
                    [['text' => '🌐 Boshqa chatda', 'switch_inline_query' => '']],
                    [['text' => "Kanalga o'tish", 'url' => 'https://t.me/RitmchiUz']],
                ],
            ]),
        ]);
    }
    // /stats
    elseif (strpos($text, '/stats') === 0) {
        $text2 = "📊 <b>Sizning statistikangiz:</b>\n\n";
        
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT total_searches, total_downloads FROM `users` WHERE telegram_id = ?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch();
            $text2 .= "🔍 Jami qidiruvlar: <b>" . ($user['total_searches'] ?? 0) . "</b>\n";
            $text2 .= "⬇️ Jami yuklab olishlar: <b>" . ($user['total_downloads'] ?? 0) . "</b>";
        } else {
            $text2 .= "🔍 Jami qidiruvlar: <b>0</b>\n";
            $text2 .= "⬇️ Jami yuklab olishlar: <b>0</b>";
        }
        
        botJson('sendMessage', [
            'chat_id' => $cid,
            'text' => $text2,
            'parse_mode' => 'HTML',
        ]);
    }
    // /help
    elseif (strpos($text, '/help') === 0) {
        botJson('sendMessage', [
            'chat_id' => $cid,
            'text' => "📖 <b>Yordam:</b>\n\n1. Qo'shiq nomi yozing\n2. Nomerini bosing\n3. Inline: <code>@" . BOT_USER . " nomi</code>\n\n📌 /stats - Statistika",
            'parse_mode' => 'HTML',
        ]);
    }
    // Search
    elseif (!($message['via_bot'] ?? false)) {
        $loading = botJson('sendMessage', [
            'chat_id' => $cid,
            'text' => "🔍 <b>{$text}</b> qidirilmoqda...",
            'parse_mode' => 'HTML',
        ]);
        $loadingMid = $loading['result']['message_id'] ?? 0;
        
        try {
            $result = scrapePage($text, 1);
            $tracks = $result['tracks'];
            
            if ($pdo) logSearch($pdo, $uid, $text, count($tracks), 'direct');
            
            bot('deleteMessage', ['chat_id' => $cid, 'message_id' => $loadingMid]);
            
            if (empty($tracks)) {
                botJson('sendMessage', [
                    'chat_id' => $cid,
                    'text' => "❌ <b>{$text}</b> bo'yicha hech narsa topilmadi.",
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $shown = array_slice($tracks, 0, PAGE_SIZE);
                
                foreach ($shown as $i => $t) {
                    $key = md5hash($t['url']);
                    $shown[$i]['key'] = $key;
                    cacheTrack($t, $key);
                }
                
                botJson('sendMessage', [
                    'chat_id' => $cid,
                    'text' => buildSearchMessage($shown, $text, 1, $result['pagination']['totalPages']),
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => buildKeyboard($shown, $text, 1, $result['pagination']['totalPages'])]),
                ]);
            }
        } catch (Exception $e) {
            lg("Search error: " . $e->getMessage());
            botJson('editMessageText', [
                'chat_id' => $cid,
                'message_id' => $loadingMid,
                'text' => "❌ Qidiruvda xato. Qayta urinib ko'ring.",
                'parse_mode' => 'HTML',
            ]);
        }
    }
}

lg('=== REQUEST COMPLETE ===');
ob_end_flush();
