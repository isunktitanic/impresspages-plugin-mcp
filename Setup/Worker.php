<?php

namespace Plugin\Mcp\Setup;

class Worker extends \Ip\SetupWorker
{
    public function activate()
    {
        if (ipDb()->isSQLite()) {
            $id = '`id` INTEGER PRIMARY KEY AUTOINCREMENT';
            $tableEnd = '';
        } else {
            $id = '`id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $tableEnd = ' DEFAULT CHARSET=utf8mb4';
        }

        // Only the SHA-256 hash of a token is stored; the token itself is shown once, when it's created.
        ipDb()->execute('CREATE TABLE IF NOT EXISTS ' . ipTable('mcp_token') . " (
            $id,
            `label` varchar(100) NOT NULL,
            `tokenHash` char(64) NOT NULL,
            `tokenHint` varchar(20) NOT NULL,
            `allowEdit` tinyint(1) NOT NULL DEFAULT 0,
            `createdBy` int(11) DEFAULT NULL,
            `createdAt` int(11) NOT NULL,
            `lastUsedAt` int(11) DEFAULT NULL,
            `revokedAt` int(11) DEFAULT NULL,
            UNIQUE (`tokenHash`)
        )" . $tableEnd);

        ipDb()->execute('CREATE TABLE IF NOT EXISTS ' . ipTable('mcp_log') . " (
            $id,
            `tokenId` int(11) DEFAULT NULL,
            `time` int(11) NOT NULL,
            `ip` varchar(45) NOT NULL,
            `method` varchar(100) NOT NULL,
            `tool` varchar(100) DEFAULT NULL,
            `status` varchar(20) NOT NULL,
            `message` varchar(255) DEFAULT NULL
        )" . $tableEnd);

        // Rate limiting and the admin screen look rows up by token or address and time.
        $indexes = array('tokenTime' => '`tokenId`, `time`', 'ipTime' => '`ip`, `time`');
        foreach ($indexes as $name => $columns) {
            $exists = ipDb()->isSQLite()
                ? ipDb()->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", array('mcpLog_' . $name))
                : ipDb()->fetchValue('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', array(ipDb()->tablePrefix() . 'mcp_log', 'mcpLog_' . $name));
            if (!$exists) {
                ipDb()->execute('CREATE INDEX `mcpLog_' . $name . '` ON ' . ipTable('mcp_log') . ' (' . $columns . ')');
            }
        }
    }

    public function remove()
    {
        ipDb()->execute('DROP TABLE IF EXISTS ' . ipTable('mcp_token'));
        ipDb()->execute('DROP TABLE IF EXISTS ' . ipTable('mcp_log'));
    }
}
