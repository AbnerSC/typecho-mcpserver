<?php
/**
 * TypechoMcpServer src/ 目录自动加载引导
 *
 * 插件零 Composer 依赖：src/ 下的类通过 spl_autoload_register 按命名空间
 * TypechoMcpServer\ 映射到 src/ 目录加载（PSR-4 风格）。
 *
 * @package TypechoMcpServer
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (!defined('TYPECHO_MCP_SERVER_AUTOLOADED')) {
    define('TYPECHO_MCP_SERVER_AUTOLOADED', true);

    spl_autoload_register(function (string $class): void {
        $prefix = 'TypechoMcpServer\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}
