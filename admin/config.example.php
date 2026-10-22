<?php
/**
 * 服务器探针系统 - 全局配置【示例模板】
 *
 * 使用说明：
 *   1. 推荐直接访问 /admin/install.php 安装向导，向导会自动生成 config.php；
 *   2. 或手动复制本文件为 config.php 后按注释修改。
 *
 * PHP >= 8.0
 */

return [
    // 数据库配置：sqlite 零配置开箱即用；如需 MySQL 请将 type 改为 mysql 并填写连接信息
    'db' => [
        'type'        => 'sqlite',   // sqlite | mysql
        'sqlite_path' => __DIR__ . '/data/probe.db',
        'mysql'       => [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'dbname'   => 'catmonitor',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
    ],

    // Session 名称
    'session_name' => 'probe_admin_sid',

    // 日志保留天数（可在后台“系统设置”中修改）
    'log_retention_days' => 7,
];
