<?php

class Database {
    private static ?PDO $pdo = null;
    
    public static function connect(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        
        logError('DB Connecting', [
            'host' => DB_HOST,
            'user' => DB_USER,
            'db' => DB_NAME
        ]);
        
        try {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            );
            logError('DB Connected successfully');
        } catch (PDOException $e) {
            logError('DB Connection FAILED', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'host' => DB_HOST,
                'user' => DB_USER
            ]);
            
            if (strpos($e->getMessage(), 'Unknown database') !== false) {
                logError('Creating database...');
                self::createDatabase();
                return self::connect();
            }
            
            throw new Exception('DB Connection Error: ' . $e->getMessage());
        }
        
        return self::$pdo;
    }
    
    private static function createDatabase(): void {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        
        self::createTables($pdo);
        
        self::$pdo = $pdo;
    }
    
    private static function createTables(PDO $pdo): void {
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
                INDEX `idx_file_id` (`file_id`),
                INDEX `idx_downloads` (`downloads`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ads` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NOT NULL,
                `url` VARCHAR(500) DEFAULT '',
                `thumb` VARCHAR(500) DEFAULT '',
                `advertiser_name` VARCHAR(255) NOT NULL,
                `advertiser_contact` VARCHAR(255) DEFAULT NULL,
                `ad_type` ENUM('text', 'photo', 'video', 'gif', 'banner') DEFAULT 'text',
                `media_file_id` VARCHAR(255) DEFAULT '',
                `media_url` VARCHAR(500) DEFAULT '',
                `display_in` ENUM('chat', 'inline', 'both') DEFAULT 'both',
                `chat_trigger` ENUM('after_download', 'after_search', 'periodic') DEFAULT 'after_download',
                `chat_frequency` INT UNSIGNED DEFAULT 5,
                `status` ENUM('pending', 'active', 'paused', 'expired', 'rejected') DEFAULT 'pending',
                `rejection_reason` TEXT DEFAULT NULL,
                `plan_type` ENUM('starter', 'standard', 'premium') DEFAULT 'standard',
                `plan_data` JSON DEFAULT NULL,
                `paid_at` DATETIME DEFAULT NULL,
                `start_at` DATETIME NOT NULL,
                `end_at` DATETIME NOT NULL,
                `impressions` INT UNSIGNED DEFAULT 0,
                `clicks` INT UNSIGNED DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`),
                INDEX `idx_dates` (`start_at`, `end_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Database tables created/verified\n";
    }
    
    public static function upsertUser(array $from): void {
        $pdo = self::connect();
        $stmt = $pdo->prepare("
            INSERT INTO `users` (telegram_id, first_name, username, last_seen)
            VALUES (:telegram_id, :first_name, :username, NOW())
            ON DUPLICATE KEY UPDATE
                first_name = VALUES(first_name),
                username = VALUES(username),
                last_seen = NOW()
        ");
        $stmt->execute([
            'telegram_id' => $from['id'],
            'first_name' => $from['first_name'] ?? null,
            'username' => $from['username'] ?? null,
        ]);
    }
    
    public static function logSearch(int $userId, string $query, int $resultsCount, string $source = 'direct'): void {
        $pdo = self::connect();
        $pdo->prepare("UPDATE `users` SET total_searches = total_searches + 1 WHERE telegram_id = ?")->execute([$userId]);
        $pdo->prepare("INSERT INTO `search_logs` (user_id, query, results_count, source) VALUES (?, ?, ?, ?)")->execute([$userId, $query, $resultsCount, $source]);
    }
    
    public static function logDownload(int $userId, string $md5): void {
        $pdo = self::connect();
        $pdo->prepare("UPDATE `users` SET total_downloads = total_downloads + 1 WHERE telegram_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE `tracks` SET downloads = downloads + 1 WHERE md5 = ?")->execute([$md5]);
    }
    
    public static function getTrack(string $md5): ?array {
        $pdo = self::connect();
        $stmt = $pdo->prepare("SELECT * FROM `tracks` WHERE `md5` = ?");
        $stmt->execute([$md5]);
        return $stmt->fetch() ?: null;
    }
    
    public static function saveTrack(array $data): void {
        $pdo = self::connect();
        $stmt = $pdo->prepare("
            INSERT INTO `tracks` (md5, title, artist, source_url, download_url, img, file_id, file_id_saved_at, id3_tagged)
            VALUES (:md5, :title, :artist, :source_url, :download_url, :img, :file_id, :file_id_saved_at, :id3_tagged)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                artist = VALUES(artist),
                source_url = VALUES(source_url),
                download_url = VALUES(download_url),
                img = VALUES(img),
                file_id = COALESCE(VALUES(file_id), file_id),
                file_id_saved_at = COALESCE(VALUES(file_id_saved_at), file_id_saved_at),
                id3_tagged = COALESCE(VALUES(id3_tagged), id3_tagged)
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
    
    public static function getFileIdFromDB(string $md5): ?string {
        $pdo = self::connect();
        $stmt = $pdo->prepare("
            SELECT file_id FROM `tracks`
            WHERE md5 = ? AND file_id IS NOT NULL AND id3_tagged = 1
            AND file_id_saved_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            LIMIT 1
        ");
        $stmt->execute([$md5]);
        $row = $stmt->fetch();
        return $row['file_id'] ?? null;
    }
    
    public static function getTopDownloaded(int $limit = 50): array {
        $pdo = self::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM `tracks`
            WHERE downloads > 0
            ORDER BY downloads DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public static function getActiveAd(string $displayIn = null): ?array {
        $pdo = self::connect();
        $sql = "SELECT * FROM `ads` WHERE status = 'active' AND start_at <= NOW() AND end_at > NOW()";
        $params = [];
        if ($displayIn) {
            $sql .= " AND display_in IN (?, 'both')";
            $params[] = $displayIn;
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }
}
