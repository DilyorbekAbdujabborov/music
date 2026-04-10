<?php

class Helpers {
    private static array $trackCache = [];
    private static array $fileIdCache = [];
    private static array $searchCache = [];
    
    public static function getMd5(string $url): string {
        return md5($url);
    }
    
    public static function cacheTrack(array $track): string {
        $key = self::getMd5($track['url'] ?? $track['source_url'] ?? '');
        if (!isset(self::$trackCache[$key])) {
            self::$trackCache[$key] = $track;
            if (count(self::$trackCache) > 1000) {
                array_shift(self::$trackCache);
            }
        }
        return $key;
    }
    
    public static function getCachedTrack(string $md5): ?array {
        return self::$trackCache[$md5] ?? null;
    }
    
    public static function cacheFileId(string $md5, string $fileId): void {
        self::$fileIdCache[$md5] = $fileId;
        if (count(self::$fileIdCache) > 5000) {
            array_shift(self::$fileIdCache);
        }
    }
    
    public static function hasFileId(string $md5): bool {
        return isset(self::$fileIdCache[$md5]);
    }
    
    public static function getFileId(string $md5): ?string {
        return self::$fileIdCache[$md5] ?? null;
    }
    
    public static function getRandomUA(): string {
        return USER_AGENTS[array_rand(USER_AGENTS)];
    }
    
    public static function getNextDomain(): array {
        static $index = 0;
        $domain = SOURCE_DOMAINS[$index % count(SOURCE_DOMAINS)];
        $index++;
        return $domain;
    }
    
    public static function httpRequest(string $url, int $retries = 3): array {
        $baseDelay = 1000;
        
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::getRandomUA(),
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                ],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response && $httpCode === 200) {
                return ['success' => true, 'data' => $response, 'code' => $httpCode];
            }
            
            $shouldRetry = $attempt < $retries && (
                $httpCode === 403 || $httpCode === 429 || $httpCode >= 500 ||
                str_contains($error, 'Connection refused') ||
                str_contains($error, 'Timeout') ||
                str_contains($error, 'Connection reset')
            );
            
            if ($shouldRetry) {
                $delay = ($baseDelay * pow(2, $attempt) + rand(0, 500)) / 1000;
                echo "[REQUEST] Retry {$attempt}/{$retries} for {$url} after {$delay}s\n";
                usleep((int)($delay * 1000000));
            } elseif ($attempt === $retries) {
                return ['success' => false, 'error' => $error ?: "HTTP $httpCode", 'code' => $httpCode];
            }
        }
        
        return ['success' => false, 'error' => 'Max retries exceeded'];
    }
    
    public static function downloadFile(string $url, int $retries = 3): array {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::getRandomUA(),
                    'Referer: https://hitmo.top/',
                ],
            ]);
            
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($data && $httpCode === 200) {
                return ['success' => true, 'data' => $data];
            }
            
            if ($httpCode === 403 || $httpCode === 429) {
                $delay = (1000 * pow(2, $attempt) + rand(0, 500)) / 1000;
                usleep((int)($delay * 1000000));
                continue;
            }
            
            if ($attempt < $retries) {
                usleep((int)(500000 * pow(2, $attempt)));
            }
        }
        
        return ['success' => false, 'error' => $error ?? 'Download failed'];
    }
    
    public static function apiRequest(string $method, array $data = []): array {
        $url = API_URL . '/' . $method;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => !empty($data),
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$response) {
            return ['ok' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        return $result ?? ['ok' => false, 'error' => 'Invalid JSON'];
    }
    
    public static function apiRequestJson(string $method, array $data = []): array {
        $url = API_URL . '/' . $method;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$response) {
            return ['ok' => false, 'error' => $error];
        }
        
        $result = json_decode($response, true);
        return $result ?? ['ok' => false, 'error' => 'Invalid JSON'];
    }
}

class Scraper {
    public static function buildSearchUrl(string $query, int $page = 1, array $domain = null): string {
        $domain = $domain ?? Helpers::getNextDomain();
        $base = $domain['base'] . '/search';
        $start = ($page - 1) * ITEMS_PER_PAGE;
        $q = urlencode($query);
        return $start === 0 ? "{$base}?q={$q}" : "{$base}/start/{$start}?q={$q}";
    }
    
    public static function parsePagination(string $html): array {
        $meta = ['currentPage' => 1, 'totalPages' => 1];
        
        if (preg_match('/pagination__item active[^>]*>.*?<b>([\d]+)<\/b>/s', $html, $m)) {
            $meta['currentPage'] = (int)$m[1];
        }
        
        if (preg_match('/pagination__list.*?href="([^"]*start\/(\d+)[^"]*)"/s', $html, $m)) {
            $meta['totalPages'] = (int)(floor((int)$m[2] / ITEMS_PER_PAGE)) + 1;
        }
        
        return $meta;
    }
    
    public static function scrapePage(string $query, int $page = 1, array $domainOverride = null): array {
        $domain = $domainOverride ?? Helpers::getNextDomain();
        $url = self::buildSearchUrl($query, $page, $domain);
        
        echo "[SCRAPE] Trying {$domain['base']} for: \"{$query}\" page: {$page}\n";
        
        $result = Helpers::httpRequest($url, 2);
        
        if (!$result['success']) {
            echo "[SCRAPE] ❌ Failed {$domain['base']}: {$result['error']}\n";
            
            if ($domainOverride === null) {
                foreach (SOURCE_DOMAINS as $fallback) {
                    if ($fallback['base'] !== $domain['base']) {
                        echo "[SCRAPE] 🔄 Trying fallback: {$fallback['base']}\n";
                        try {
                            return self::scrapePage($query, $page, $fallback);
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                }
            }
            
            throw new Exception("Scraping failed: {$result['error']}");
        }
        
        $tracks = [];
        $pattern = '/<div[^>]*class="tracks__item"[^>]*data-musmeta=\'({[^\']+})\'/s';
        
        if (preg_match_all($pattern, $result['data'], $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $musicMeta = json_decode(html_entity_decode($jsonStr), true);
                if (!$musicMeta || empty($musicMeta['title'])) continue;
                
                $downloadUrl = str_replace(
                    ['eu.hitmotop.com', 's2.deliciouspeaches.com', 'dl.hitmo.top', 'dl.hitmo.me'],
                    [$domain['download'], $domain['download'], $domain['download'], $domain['download']],
                    $musicMeta['url'] ?? ''
                );
                
                $tracks[] = [
                    'title' => $musicMeta['title'],
                    'artist' => $musicMeta['artist'],
                    'url' => $musicMeta['url'],
                    'img' => $musicMeta['img'] ?? null,
                    'download_url' => $downloadUrl,
                    'source_domain' => $domain,
                ];
            }
        }
        
        $pagination = self::parsePagination($result['data']);
        
        echo "[SCRAPE] ✅ Found " . count($tracks) . " tracks from {$domain['base']}\n";
        
        return ['tracks' => $tracks, 'pagination' => $pagination, 'domain' => $domain];
    }
}
