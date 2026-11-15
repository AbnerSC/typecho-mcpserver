<?php
/**
 * 插件配置读取（含高危开关判定）
 *
 * Typecho 插件配置存储于 options 表 plugin:TypechoMcpServer（JSON），
 * checkbox 保存为 ["on"] 数组，此层统一转为布尔语义。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Support;

use Utils\Helper;

class Config
{
    /** @var array 原始配置 */
    private $raw;

    public function __construct(array $raw = [])
    {
        $this->raw = $raw;
    }

    /**
     * 从 Typecho options 读取插件配置
     */
    public static function load(): Config
    {
        try {
            $raw = Helper::options()->plugin('TypechoMcpServer')->toArray();
        } catch (\Throwable $e) {
            $raw = [];
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        return new self($raw);
    }

    /** API Token */
    public function token(): string
    {
        return isset($this->raw['token']) && is_string($this->raw['token']) ? $this->raw['token'] : '';
    }

    /** 执行身份 uid */
    public function bindUid(): int
    {
        $uid = isset($this->raw['bindUid']) ? (int) $this->raw['bindUid'] : 1;

        return $uid > 0 ? $uid : 1;
    }

    /** 是否允许 AI 直接发布非草稿内容（默认关） */
    public function allowPublish(): bool
    {
        return $this->truthy($this->raw['allowPublish'] ?? null);
    }

    /** 是否允许 AI 执行删除操作（默认关） */
    public function allowDelete(): bool
    {
        return $this->truthy($this->raw['allowDelete'] ?? null);
    }

    /** 分类不存在时是否自动创建（默认开） */
    public function autoCreateCategory(): bool
    {
        return $this->truthy($this->raw['autoCreateCategory'] ?? 'on');
    }

    /** 是否允许 URL 参数传递 Token（默认开） */
    public function urlTokenEnabled(): bool
    {
        return $this->truthy($this->raw['urlTokenEnabled'] ?? 'on');
    }

    /** MCP 端点路径 */
    public function routePath(): string
    {
        $path = isset($this->raw['routePath']) && is_string($this->raw['routePath'])
            ? $this->raw['routePath'] : '';

        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/mcp';
        }

        return $path;
    }

    /** 审计日志开关（默认开） */
    public function logEnabled(): bool
    {
        return $this->truthy($this->raw['logEnabled'] ?? 'on');
    }

    /**
     * 宽松布尔判定：兼容 "on" / ["on"] / true / 1
     *
     * @param mixed $value
     */
    private function truthy($value): bool
    {
        if (is_array($value)) {
            return in_array('on', $value, true) || in_array(1, $value, true) || in_array(true, $value, true);
        }

        return $value === 'on' || $value === 1 || $value === true || $value === '1';
    }
}
