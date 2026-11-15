<?php
/**
 * 调用审计日志：自建表（环形保留最近 500 条），兼容 MySQL / SQLite / PostgreSQL
 *
 * 表结构见设计方案第 7 节；表名经 Typecho Db 前缀机制（typecho_）管理。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Support;

use Typecho\Db;

class Logger
{
    /** 环形保留条数 */
    public const KEEP_LIMIT = 500;

    /** 日志表名（不含前缀，查询时用 table. 前缀语法） */
    public const TABLE = 'table.mcpserver_logs';

    /**
     * 建表（激活时调用；幂等）
     *
     * @throws \Typecho\Db\Exception
     */
    public static function ensureTable(): void
    {
        $db = Db::get();
        $adapter = $db->getAdapterName();
        $prefix = $db->getPrefix();
        $table = $prefix . 'mcpserver_logs';

        $isMysql = in_array($adapter, ['Mysql', 'Mysqli', 'Pdo_Mysql'], true);
        $isPgsql = in_array($adapter, ['Pgsql', 'Pdo_Pgsql'], true);
        // 其余（SQLite / Pdo_SQLite）走 SQLite 方言

        if ($isMysql) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$table}` ("
                . "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, "
                . "`time` INT UNSIGNED NOT NULL DEFAULT 0, "
                . "`ip` VARCHAR(64) NOT NULL DEFAULT '', "
                . "`tool` VARCHAR(64) NOT NULL DEFAULT '', "
                . "`args` VARCHAR(255) NOT NULL DEFAULT '', "
                . "`ok` TINYINT(1) NOT NULL DEFAULT 0, "
                . "`error` VARCHAR(255) NOT NULL DEFAULT '', "
                . "`costMs` SMALLINT UNSIGNED NOT NULL DEFAULT 0, "
                . "PRIMARY KEY (`id`), INDEX `time` (`time`) "
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        } elseif ($isPgsql) {
            $sql = "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                . "\"id\" SERIAL PRIMARY KEY, "
                . "\"time\" INTEGER NOT NULL DEFAULT 0, "
                . "\"ip\" VARCHAR(64) NOT NULL DEFAULT '', "
                . "\"tool\" VARCHAR(64) NOT NULL DEFAULT '', "
                . "\"args\" VARCHAR(255) NOT NULL DEFAULT '', "
                . "\"ok\" SMALLINT NOT NULL DEFAULT 0, "
                . "\"error\" VARCHAR(255) NOT NULL DEFAULT '', "
                . "\"costMs\" INTEGER NOT NULL DEFAULT 0)";
            $db->query($sql);
            $index = "CREATE INDEX IF NOT EXISTS idx_{$prefix}mcp_logs_time ON \"{$table}\" (\"time\")";
            $db->query($index);

            return;
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                . "\"id\" INTEGER PRIMARY KEY AUTOINCREMENT, "
                . "\"time\" INTEGER NOT NULL DEFAULT 0, "
                . "\"ip\" VARCHAR(64) NOT NULL DEFAULT '', "
                . "\"tool\" VARCHAR(64) NOT NULL DEFAULT '', "
                . "\"args\" VARCHAR(255) NOT NULL DEFAULT '', "
                . "\"ok\" SMALLINT NOT NULL DEFAULT 0, "
                . "\"error\" VARCHAR(255) NOT NULL DEFAULT '', "
                . "\"costMs\" INTEGER NOT NULL DEFAULT 0)";
            $db->query($sql);
            $index = "CREATE INDEX IF NOT EXISTS idx_mcp_logs_time ON \"{$table}\" (\"time\")";
            $db->query($index);

            return;
        }

        $db->query($sql, Db::WRITE);
    }

    /**
     * 写入一条审计日志并环形裁剪
     *
     * @throws \Typecho\Db\Exception
     */
    public static function write(string $ip, string $tool, string $args, int $ok, string $error, int $costMs): void
    {
        $db = Db::get();

        $db->query(
            $db->insert(self::TABLE)->rows([
                'time'   => time(),
                'ip'     => mb_substr($ip, 0, 64),
                'tool'   => mb_substr($tool, 0, 64),
                'args'   => mb_substr($args, 0, 255),
                'ok'     => $ok ? 1 : 0,
                'error'  => mb_substr($error, 0, 255),
                'costMs' => min($costMs, 65535),
            ]),
            Db::WRITE
        );

        self::prune();
    }

    /**
     * 环形裁剪：仅保留最近 KEEP_LIMIT 条
     *
     * @throws \Typecho\Db\Exception
     */
    public static function prune(): void
    {
        $db = Db::get();

        $count = (int) $db->fetchObject(
            $db->select(['COUNT(*)' => 'num'])->from(self::TABLE)
        )->num;

        if ($count <= self::KEEP_LIMIT) {
            return;
        }

        // 找到截断点的 id，删除更旧记录
        $border = $db->fetchObject(
            $db->select('id')->from(self::TABLE)->order('id', Db::SORT_DESC)
                ->offset(self::KEEP_LIMIT - 1)->limit(1)
        );

        if ($border) {
            $db->query(
                $db->delete(self::TABLE)->where('id < ?', $border->id),
                Db::WRITE
            );
        }
    }

    /**
     * 读取最近日志（后台面板用）
     *
     * @param int $limit
     * @return array
     * @throws \Typecho\Db\Exception
     */
    public static function recent(int $limit = 100): array
    {
        return Db::get()->fetchAll(
            Db::get()->select()->from(self::TABLE)
                ->order('id', Db::SORT_DESC)->limit($limit)
        );
    }
}
