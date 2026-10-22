<?php
/**
 * 节点分类服务
 */

declare(strict_types=1);

class GroupService
{
    /** 自动建表/补列（兼容旧版本升级） */
    public static function ensureSchema(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk     = $driver === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $db->exec("CREATE TABLE IF NOT EXISTS `groups` (
            id {$pk},
            name VARCHAR(50) NOT NULL,
            sort INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT 0
        )");

        $cols = [];
        if ($driver === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(servers)') as $r) {
                $cols[] = $r['name'];
            }
        } else {
            foreach ($db->query('SHOW COLUMNS FROM servers') as $r) {
                $cols[] = $r['Field'];
            }
        }
        if (!in_array('group_id', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN group_id INTEGER DEFAULT 0');
        }
        // groups 自身的 front_hidden 列（独立检查 groups 表结构）
        $groupCols = [];
        if ($driver === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(`groups`)') as $r) {
                $groupCols[] = $r['name'];
            }
        } else {
            foreach ($db->query('SHOW COLUMNS FROM `groups`') as $r) {
                $groupCols[] = $r['Field'];
            }
        }
        if (!in_array('front_hidden', $groupCols, true)) {
            $db->exec('ALTER TABLE `groups` ADD COLUMN front_hidden INTEGER DEFAULT 0');
        }
    }

    /** 全部分类（按排序） */
    public static function all(PDO $db): array
    {
        return $db->query('SELECT * FROM `groups` ORDER BY sort ASC, id ASC')->fetchAll();
    }

    /** 分类映射：id => name */
    public static function map(PDO $db): array
    {
        $map = [];
        foreach (self::all($db) as $g) {
            $map[(int)$g['id']] = $g['name'];
        }

        return $map;
    }
}
