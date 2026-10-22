<?php
/**
 * 数据库连接（PDO，支持 sqlite / mysql）
 */

final class Db
{
    public static function connect(array $cfg): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (($cfg['type'] ?? 'sqlite') === 'mysql') {
            $m   = $cfg['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $m['host'],
                $m['port'],
                $m['dbname'],
                $m['charset'] ?? 'utf8mb4'
            );
            return new PDO($dsn, $m['username'], $m['password'], $options);
        }

        // sqlite
        $path = $cfg['sqlite_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /**
     * 判断是否已安装（核心表是否存在）
     */
    public static function isInstalled(PDO $db): bool
    {
        try {
            $db->query('SELECT COUNT(*) FROM servers');
            $db->query('SELECT COUNT(*) FROM admins');

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
