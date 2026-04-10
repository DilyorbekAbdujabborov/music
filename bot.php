<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

logError('Bot loading...');

$isWeb = php_sapi_name() !== 'cli';

if ($isWeb) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Bot Status: Running\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Error Log: /logs/error.log\n";
    echo "\nLast 20 lines of error log:\n";
    echo "========================\n";
    
    $logFile = __DIR__ . '/logs/error.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $lastLines = array_slice($lines, -20);
        echo implode('', $lastLines);
    } else {
        echo "No logs yet\n";
    }
    exit;
}

class MusicBot {
    private int $updateId = 0;
    private float $lastBackup = 0;
    private int $backupInterval = 3600;
    
    public function __construct() {
        logError('MusicBot constructing...');
        Database::connect();
        echo "🤖 Bot started\n";
        logError('Bot constructed successfully');
    }
    
    public function run(): void {
        logError('Bot run loop started');
        
        while (true) {
            try {
                $updates = $this->getUpdates();
                
                if (!empty($updates)) {
                    foreach ($updates as $update) {
                        $this->processUpdate($update);
                        $this->updateId = $update['update_id'] + 1;
                    }
                }
                
                $this->checkBackup();
                
                sleep(1);
            } catch (Exception $e) {
                logError('Run loop error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                echo "ERROR: " . $e->getMessage() . "\n";
                sleep(5);
            }
        }
    }
    
    private function getUpdates(): array {
        $result = Helpers::apiRequest('getUpdates', [
            'offset' => $this->updateId,
            'timeout' => 30,
            'limit' => 100,
        ]);
        
        if (!($result['ok'] ?? false)) {
            return [];
        }
        
        return $result['result'] ?? [];
    }
    
    private function processUpdate(array $update): void {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['inline_query'])) {
            $this->handleInline($update['inline_query']);
        }
    }
    
    private function handleMessage(array $msg): void {
        $chatId = $msg['chat']['id'];
        $from = $msg['from'];
        $text = $msg['text'] ?? '';
        
        Database::upsertUser($from);
        
        if (str_starts_with($text, '/start')) {
            $this->sendStart($chatId, $from['first_name'] ?? 'Friend');
        } elseif (str_starts_with($text, '/stats')) {
            $this->sendStats($chatId, $from['id']);
        } elseif (str_starts_with($text, '/help')) {
            $this->sendHelp($chatId);
        } elseif (!str_starts_with($text, '/') && !($msg['via_bot'] ?? false)) {
            $this->handleSearch($chatId, $from, $text);
        }
    }
    
    private function handleCallback(array $query): void {
        $data = $query['data'] ?? '';
        $chatId = $query['message']['chat']['id'] ?? null;
        $msgId = $query['message']['message_id'] ?? null;
        $inlineMsgId = $query['inline_message_id'] ?? null;
        $from = $query['from'];
        
        Database::upsertUser($from);
        
        Helpers::apiRequest('answerCallbackQuery', [
            'callback_query_id' => $query['id'],
            'text' => '⏳ Yuklanmoqda...',
        ]);
        
        if (str_starts_with($data, 'dl:')) {
            $md5 = substr($data, 3);
            $this->handleDownload($from, $chatId, $msgId, $inlineMsgId, $md5);
        } elseif (str_starts_with($data, 'more:')) {
            $parts = explode(':', $data, 3);
            $query2 = $parts[1] ?? '';
            $page = (int)($parts[2] ?? 1);
            $this->handleMore($from, $chatId, $msgId, $query2, $page);
        } elseif ($data === 'close') {
            if ($msgId) {
                Helpers::apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
            }
        }
    }
    
    private function handleInline(array $query): void {
        $queryId = $query['id'];
        $queryText = trim($query['query']);
        $offset = (int)($query['offset'] ?: 1);
        $fromId = $query['from']['id'] ?? 0;
        
        Database::upsertUser($query['from']);
        
        if (empty($queryText)) {
            $ad = Database::getActiveAd('inline');
            $results = [$this->buildAdResult($ad)];
            Helpers::apiRequest('answerInlineQuery', [
                'inline_query_id' => $queryId,
                'results' => json_encode($results),
                'cache_time' => 0,
            ]);
            return;
        }
        
        try {
            $result = Scraper::scrapePage($queryText, $offset);
            $tracks = $result['tracks'];
            
            Database::logSearch($fromId, $queryText, count($tracks), 'inline');
            
            if (empty($tracks)) {
                Helpers::apiRequest('answerInlineQuery', [
                    'inline_query_id' => $queryId,
                    'results' => json_encode([]),
                    'cache_time' => 5,
                ]);
                return;
            }
            
            $seen = [];
            $uniqueTracks = [];
            foreach ($tracks as $track) {
                $key = Helpers::cacheTrack($track);
                if (!in_array($key, $seen)) {
                    $seen[] = $key;
                    $uniqueTracks[] = $track;
                }
            }
            
            $trackResults = array_map(fn($t) => $this->buildTrackResult($t, $queryText), $uniqueTracks);
            
            $ad = Database::getActiveAd('inline');
            $adResult = $this->buildAdResult($ad);
            
            if ($offset === 1) {
                array_unshift($trackResults, $adResult);
            }
            
            $nextOffset = $offset < $result['pagination']['totalPages'] ? (string)($offset + 1) : '';
            
            Helpers::apiRequest('answerInlineQuery', [
                'inline_query_id' => $queryId,
                'results' => json_encode(array_values($trackResults)),
                'next_offset' => $nextOffset,
                'cache_time' => 300,
                'is_personal' => false,
            ]);
            
        } catch (Exception $e) {
            echo "[INLINE] ❌ {$e->getMessage()}\n";
            Helpers::apiRequest('answerInlineQuery', [
                'inline_query_id' => $queryId,
                'results' => json_encode([]),
                'cache_time' => 5,
            ]);
        }
    }
    
    private function sendStart(int $chatId, string $name): void {
        $text = "👋 Salom, <b>{$name}</b>!\n\n";
        $text .= "🎵 Men musiqa qidiruv botiman.\n\n";
        $text .= "<b>Foydalanish:</b>\n";
        $text .= "• Qo'shiq nomini yozing\n";
        $text .= "• Istalgan chatda: <code>@" . BOT_USERNAME . " Billie Jean</code>";
        
        Helpers::apiRequestJson('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
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
    
    private function sendStats(int $chatId, int $userId): void {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT total_searches, total_downloads FROM `users` WHERE telegram_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        $stmt2 = $pdo->prepare("SELECT query, created_at FROM `search_logs` WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt2->execute([$userId]);
        $recent = $stmt2->fetchAll();
        
        $text = "📊 <b>Sizning statistikangiz:</b>\n\n";
        $text .= "🔍 Jami qidiruvlar: <b>" . ($user['total_searches'] ?? 0) . "</b>\n";
        $text .= "⬇️ Jami yuklab olishlar: <b>" . ($user['total_downloads'] ?? 0) . "</b>\n\n";
        $text .= "🕐 <b>So'nggi qidiruvlar:</b>\n";
        
        if (empty($recent)) {
            $text .= "—";
        } else {
            foreach ($recent as $r) {
                $text .= "• <code>{$r['query']}</code>\n";
            }
        }
        
        Helpers::apiRequestJson('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }
    
    private function sendHelp(int $chatId): void {
        $text = "📖 <b>Yordam:</b>\n\n";
        $text .= "1. Qo'shiq nomi yozing - natijalar chiqadi\n";
        $text .= "2. Nomerini bosing - yuklab olish boshlanadi\n";
        $text .= "3. Inline: <code>@" . BOT_USERNAME . " qo'shiq nomi</code>\n\n";
        $text .= "📌 <b>Buyruqlar:</b>\n";
        $text .= "/stats - Statistika\n";
        $text .= "/help - Yordam";
        
        Helpers::apiRequestJson('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }
    
    private function handleSearch(int $chatId, array $from, string $query): void {
        $msg = Helpers::apiRequestJson('sendMessage', [
            'chat_id' => $chatId,
            'text' => "🔍 <b>{$query}</b> qidirilmoqda...",
            'parse_mode' => 'HTML',
        ]);
        
        $loadingMsgId = $msg['result']['message_id'] ?? null;
        
        try {
            $result = Scraper::scrapePage($query, 1);
            $tracks = $result['tracks'];
            
            Database::logSearch($from['id'], $query, count($tracks), 'direct');
            
            if ($loadingMsgId) {
                Helpers::apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $loadingMsgId]);
            }
            
            if (empty($tracks)) {
                Helpers::apiRequestJson('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ <b>{$query}</b> bo'yicha hech narsa topilmadi.\nBoshqa kalit so'z bilan urinib ko'ring.",
                    'parse_mode' => 'HTML',
                ]);
                return;
            }
            
            $shown = array_slice($tracks, 0, PAGE_SIZE);
            
            foreach ($shown as $i => $track) {
                $key = Helpers::cacheTrack($track);
                $ready = Helpers::hasFileId($key);
                $badge = $ready ? '✅ ' : '';
                $shown[$i]['_key'] = $key;
            }
            
            $text = $this->buildSearchMessage($shown, $query, 1, $result['pagination']['totalPages']);
            $keyboard = $this->buildTrackKeyboard($shown, $query, 1, $result['pagination']['totalPages']);
            
            Helpers::apiRequestJson('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
            
        } catch (Exception $e) {
            echo "[SEARCH] ❌ {$e->getMessage()}\n";
            if ($loadingMsgId) {
                Helpers::apiRequestJson('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $loadingMsgId,
                    'text' => "❌ Qidiruvda xato yuz berdi. Qayta urinib ko'ring.",
                    'parse_mode' => 'HTML',
                ]);
            }
        }
    }
    
    private function handleDownload(array $from, ?int $chatId, ?int $msgId, ?string $inlineMsgId, string $md5): void {
        $track = Helpers::getCachedTrack($md5);
        
        if (!$track) {
            $dbTrack = Database::getTrack($md5);
            if ($dbTrack) {
                $track = [
                    'title' => $dbTrack['title'],
                    'artist' => $dbTrack['artist'],
                    'url' => $dbTrack['source_url'],
                    'download_url' => $dbTrack['download_url'],
                    'img' => $dbTrack['img'],
                ];
                Helpers::cacheTrack($track);
            }
        }
        
        if (!$track) {
            Helpers::apiRequest('answerCallbackQuery', [
                'callback_query_id' => '', 
                'text' => "⚠️ Qo'shiq topilmadi",
                'show_alert' => true,
            ]);
            return;
        }
        
        Database::logDownload($from['id'], $md5);
        
        if ($inlineMsgId) {
            $this->handleInlineDownload($inlineMsgId, $track, $md5);
        } elseif ($chatId && $msgId) {
            $this->handleDirectDownload($chatId, $msgId, $track, $md5);
        }
    }
    
    private function handleInlineDownload(string $msgId, array $track, string $md5): void {
        $fileId = $this->getOrUploadFileId($track, $md5);
        
        if (!$fileId) {
            return;
        }
        
        Helpers::apiRequest('editMessageMedia', [
            'inline_message_id' => $msgId,
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
                    [['text' => '🎵 Bot orqali qidirish', 'url' => "https://t.me/" . BOT_USERNAME]],
                ],
            ]),
        ]);
    }
    
    private function handleDirectDownload(int $chatId, int $msgId, array $track, string $md5): void {
        $progressFrames = [
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
        
        $label = "🎵 <b>{$track['artist']} — {$track['title']}</b>";
        Helpers::apiRequestJson('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "{$label}\n{$progressFrames[0]}",
            'parse_mode' => 'HTML',
        ]);
        
        $fileId = $this->getOrUploadFileId($track, $md5);
        
        if (!$fileId) {
            Helpers::apiRequestJson('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "❌ Yuklashda xato yuz berdi. Qayta urinib ko'ring.",
                'parse_mode' => 'HTML',
            ]);
            return;
        }
        
        Helpers::apiRequestJson('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "{$label}\n{$progressFrames[10]}",
            'parse_mode' => 'HTML',
        ]);
        
        Helpers::apiRequestJson('sendAudio', [
            'chat_id' => $chatId,
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
        
        Helpers::apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
    }
    
    private function getOrUploadFileId(array $track, string $md5): ?string {
        if (Helpers::hasFileId($md5)) {
            return Helpers::getFileId($md5);
        }
        
        $dbFileId = Database::getFileIdFromDB($md5);
        if ($dbFileId) {
            Helpers::cacheFileId($md5, $dbFileId);
            return $dbFileId;
        }
        
        $audioResult = Helpers::downloadFile($track['download_url']);
        if (!$audioResult['success']) {
            echo "[UPLOAD] ❌ Audio download failed: {$audioResult['error']}\n";
            return null;
        }
        
        $audioData = $audioResult['data'];
        
        $filename = preg_replace('/[\\/:"*?<>|]/', '', "{$track['artist']} - {$track['title']}.mp3");
        
        $result = Helpers::apiRequestJson('sendAudio', [
            'chat_id' => BOT_DUMP_CHAT,
            'audio' => 'https://' . $_SERVER['HTTP_HOST'] . '/upload.php?file=' . urlencode($filename),
            'title' => $track['title'],
            'performer' => $track['artist'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🎵 Siz ham sinab ko'ring", 'url' => "https://t.me/" . BOT_USERNAME]],
                ],
            ]),
        ]);
        
        if (!($result['ok'] ?? false)) {
            $result = Helpers::apiRequest('sendDocument', [
                'chat_id' => BOT_DUMP_CHAT,
                'document' => 'https://' . $_SERVER['HTTP_HOST'] . '/upload.php?file=' . urlencode($filename),
            ]);
        }
        
        $fileId = $result['result']['audio']['file_id'] ?? $result['result']['document']['file_id'] ?? null;
        
        if ($fileId) {
            Helpers::cacheFileId($md5, $fileId);
            Database::saveTrack([
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
        
        return $fileId;
    }
    
    private function handleMore(array $from, ?int $chatId, ?int $msgId, string $query, int $page): void {
        if (!$msgId) return;
        
        try {
            $result = Scraper::scrapePage($query, $page);
            $tracks = $result['tracks'];
            
            if (empty($tracks)) {
                Helpers::apiRequestJson('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $msgId,
                    'text' => "❌ Boshqa natija topilmadi.",
                    'parse_mode' => 'HTML',
                ]);
                return;
            }
            
            $shown = array_slice($tracks, 0, PAGE_SIZE);
            
            foreach ($shown as $i => $track) {
                $key = Helpers::cacheTrack($track);
                $ready = Helpers::hasFileId($key);
                $shown[$i]['_key'] = $key;
            }
            
            $text = $this->buildSearchMessage($shown, $query, $page, $result['pagination']['totalPages']);
            $keyboard = $this->buildTrackKeyboard($shown, $query, $page, $result['pagination']['totalPages']);
            
            Helpers::apiRequestJson('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
            
        } catch (Exception $e) {
            echo "[MORE] ❌ {$e->getMessage()}\n";
        }
    }
    
    private function buildSearchMessage(array $tracks, string $query, int $page, int $totalPages): string {
        $list = '';
        foreach ($tracks as $i => $t) {
            $key = $t['_key'] ?? Helpers::cacheTrack($t);
            $ready = Helpers::hasFileId($key);
            $badge = $ready ? '✅ ' : '';
            $list .= ($i + 1) . ". {$badge}{$t['artist']} — {$t['title']}\n";
        }
        
        $pageInfo = $totalPages > 1 ? "📄 <b>{$page}/{$totalPages}</b> sahifa\n\n" : "\n";
        return "🎶 <b>{$query}</b> — natijalar\n{$pageInfo}{$list}";
    }
    
    private function buildTrackKeyboard(array $tracks, string $query, int $page, int $totalPages): array {
        $rows = [];
        
        $row1 = [];
        for ($i = 0; $i < 5 && $i < count($tracks); $i++) {
            $key = $tracks[$i]['_key'] ?? '';
            $ready = Helpers::hasFileId($key);
            $row1[] = ['text' => ($ready ? '✅' : '') . ($i + 1), 'callback_data' => "dl:{$key}"];
        }
        if (!empty($row1)) $rows[] = $row1;
        
        $row2 = [];
        for ($i = 5; $i < 10 && $i < count($tracks); $i++) {
            $key = $tracks[$i]['_key'] ?? '';
            $ready = Helpers::hasFileId($key);
            $row2[] = ['text' => ($ready ? '✅' : '') . ($i + 1), 'callback_data' => "dl:{$key}"];
        }
        if (!empty($row2)) $rows[] = $row2;
        
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
    
    private function buildTrackResult(array $track, string $query): array {
        $key = Helpers::cacheTrack($track);
        $cachedFileId = Helpers::getFileId($key);
        
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
        
        $thumbUrl = $track['img'] ?? null;
        return [
            'type' => 'article',
            'id' => $key,
            'title' => "🎵 {$track['title']}",
            'description' => "👤 {$track['artist']} · ⏳ Yuklanadi",
            'thumb_url' => $thumbUrl,
            'input_message_content' => [
                'message_text' => "🎵 <b>{$track['artist']} — {$track['title']}</b>",
                'parse_mode' => 'HTML',
            ],
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '⬇️ Yuklab olish', 'callback_data' => "dl:{$key}"]],
                    [['text' => '🔍 Bu chatda qidirish', 'switch_inline_query_current_chat' => '']],
                ],
            ],
        ];
    }
    
    private function buildAdResult(?array $ad): array {
        if ($ad) {
            $thumbUrl = $ad['thumb'] ?: null;
            return [
                'type' => 'article',
                'id' => "ad_{$ad['id']}",
                'title' => "📢 {$ad['title']}",
                'description' => $ad['description'],
                'thumb_url' => $thumbUrl,
                'input_message_content' => [
                    'message_text' => "📢 <b>{$ad['title']}</b>\n\n{$ad['description']}",
                    'parse_mode' => 'HTML',
                ],
            ];
        }
        
        return [
            'type' => 'article',
            'id' => 'ad_placeholder',
            'title' => '📢 Bu yerda sizning reklamangiz',
            'description' => 'Reklama joylashtirish uchun /adinfo',
            'input_message_content' => [
                'message_text' => "📢 <b>Bu yerda sizning reklamangiz bo'lishi mumkin!</b>\n\nReklama: /adinfo",
                'parse_mode' => 'HTML',
            ],
        ];
    }
    
    private function checkBackup(): void {
        $now = microtime(true);
        
        if ($now - $this->lastBackup >= $this->backupInterval) {
            $this->performBackup();
            $this->lastBackup = $now;
        }
    }
    
    private function performBackup(): void {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = __DIR__ . '/backups/' . $filename;
        
        if (!is_dir(__DIR__ . '/backups')) {
            mkdir(__DIR__ . '/backups', 0755, true);
        }
        
        $pdo = Database::connect();
        
        $tables = ['tracks', 'users', 'search_logs', 'ads'];
        $sql = "-- Music Bot Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll();
            
            if (empty($rows)) continue;
            
            $sql .= "INSERT INTO `{$table}` VALUES\n";
            $values = [];
            
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . addslashes($v) . "'", array_values($row));
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            
            $sql .= implode(",\n", $values) . ";\n\n";
        }
        
        file_put_contents($filepath, $sql);
        
        $size = filesize($filepath);
        echo "✅ Backup created: {$filename} (" . number_format($size / 1024, 2) . " KB)\n";
        
        $this->sendBackupToTelegram($filepath);
        
        $this->cleanOldBackups();
    }
    
    private function sendBackupToTelegram(string $filepath): void {
        if (!file_exists($filepath)) return;
        
        $filename = basename($filepath);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendDocument',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => BOT_DUMP_CHAT,
                'document' => new CURLFile($filepath),
                'caption' => "📦 Database Backup\n📅 " . date('Y-m-d H:i:s'),
            ],
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        if ($result['ok'] ?? false) {
            echo "✅ Backup sent to Telegram\n";
        }
    }
    
    private function cleanOldBackups(): void {
        $backupDir = __DIR__ . '/backups/';
        $files = glob($backupDir . 'backup_*.sql');
        
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        
        $keep = 24;
        $delete = array_slice($files, $keep);
        
        foreach ($delete as $file) {
            unlink($file);
            echo "🗑️ Deleted old backup: " . basename($file) . "\n";
        }
    }
}

if (php_sapi_name() === 'cli') {
    $bot = new MusicBot();
    $bot->run();
}
