<?php
/**
 * MCP 内核：JSON-RPC 方法分发（initialize / tools/list / tools/call / ping）
 *
 * 无状态 Streamable HTTP 模式：不签发 Mcp-Session-Id，每次请求独立处理。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Protocol;

use TypechoMcpServer\Exception\ParseError;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\Logger;
use TypechoMcpServer\Support\TypechoAdapter;
use TypechoMcpServer\Tools\ToolRegistry;

class McpKernel
{
    /** 支持的 MCP 协议版本（最后一个为服务器最新版本） */
    public const SUPPORTED_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];
    public const LATEST_VERSION = '2025-06-18';

    /** @var Config */
    private $config;

    /** @var ToolRegistry */
    private $registry;

    /** @var string 客户端 IP（审计用） */
    private $clientIp;

    public function __construct(Config $config, string $clientIp = '')
    {
        $this->config = $config;
        $this->clientIp = $clientIp;
    }

    /**
     * 处理单条 JSON-RPC 消息
     *
     * @param array $message 已通过 JsonRpc::isValid 校验的消息
     * @return array|null JSON-RPC 响应；通知（无 id）返回 null
     */
    public function handle(array $message): ?array
    {
        $id = array_key_exists('id', $message) ? $message['id'] : null;
        $method = $message['method'];
        $params = isset($message['params']) && is_array($message['params'])
            ? $message['params'] : [];

        try {
            switch ($method) {
                case 'initialize':
                    return JsonRpc::result($id, $this->initialize($params));

                case 'notifications/initialized':
                case 'notifications/cancelled':
                case 'notifications/roots/list_changed':
                    // 通知：无响应（Action 层统一回 202）
                    return null;

                case 'tools/list':
                    return JsonRpc::result($id, $this->toolsList($params));

                case 'tools/call':
                    return JsonRpc::result($id, $this->toolsCall($params));

                case 'ping':
                    return JsonRpc::result($id, new \stdClass());

                default:
                    if (strpos($method, 'notifications/') === 0) {
                        return null; // 未知通知静默忽略
                    }

                    return JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$method}");
            }
        } catch (\Throwable $e) {
            // 协议层异常统一转为 JSON-RPC internal error（业务错误在 toolsCall 内处理为 isError）
            return JsonRpc::error(
                $id,
                JsonRpc::INTERNAL_ERROR,
                'Internal error: ' . $e->getMessage()
            );
        }
    }

    /**
     * initialize：协议版本协商 + 能力声明
     */
    private function initialize(array $params): array
    {
        $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
            ? $params['protocolVersion'] : '';

        $negotiated = in_array($requested, self::SUPPORTED_VERSIONS, true)
            ? $requested : self::LATEST_VERSION;

        return [
            'protocolVersion' => $negotiated,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'typecho-mcpserver',
                'version' => '1.0.0',
            ],
            // 无状态模式提示（非规范字段，客户端可忽略）
            'instructions' => 'Typecho 博客内容管理。新文章默认保存为草稿；发布/删除操作是否可用取决于站点安全开关。',
        ];
    }

    /**
     * tools/list：全部工具描述（首版单页返回）
     */
    private function toolsList(array $params): array
    {
        $tools = [];
        foreach ($this->registry()->all() as $definition) {
            $tools[] = [
                'name' => $definition->name(),
                'description' => $definition->description(),
                'inputSchema' => $definition->inputSchema(),
            ];
        }

        return [
            'tools' => $tools,
            'nextCursor' => null, // 单页返回，无后续分页
        ];
    }

    /**
     * tools/call：执行工具
     *
     * 业务异常 → isError: true + 错误文本（符合 MCP 规范对工具执行错误的约定，
     * 不使用 JSON-RPC error，让客户端模型可自行纠正重试）。
     */
    private function toolsCall(array $params): array
    {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        $definition = $this->registry()->get($name);
        if ($definition === null) {
            return $this->toolError("Unknown tool: {$name}");
        }

        // required 参数检查（协议层参数错误）
        foreach ($definition->requiredParams() as $key) {
            if (!array_key_exists($key, $arguments)) {
                return $this->toolError("Missing required parameter: {$key}");
            }
        }

        // 以配置的执行身份临时登录（供 Service 层复用 Typecho Edit Widget）
        TypechoAdapter::loginAs($this->config->bindUid());

        $start = microtime(true);
        $ok = true;
        $error = '';

        try {
            $result = $definition->handler()($arguments);
            $structured = is_array($result) ? $result : ['result' => $result];
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
            return $this->toolError($error);
        } finally {
            $costMs = (int) round((microtime(true) - $start) * 1000);
            $this->log($name, $arguments, $ok, $error, $costMs);
        }

        // 统一内容形态：content[].text 为 JSON 字符串 + structuredContent（5.1.4）
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'structuredContent' => $structured,
            'isError' => false,
        ];
    }

    /**
     * 构造工具执行错误结果（isError: true）
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $message],
            ],
            'isError' => true,
        ];
    }

    /**
     * 审计日志（开关控制；写日志失败不影响主流程）
     */
    private function log(string $tool, array $args, bool $ok, string $error, int $costMs)
    {
        if (!$this->config->logEnabled()) {
            return;
        }

        try {
            $summary = json_encode($args, JSON_UNESCAPED_UNICODE);
            if (mb_strlen($summary) > 200) {
                $summary = mb_substr($summary, 0, 200) . '…';
            }

            Logger::write($this->clientIp, $tool, $summary, $ok ? 1 : 0, $error, $costMs);
        } catch (\Throwable $e) {
            // 审计日志失败静默忽略
        }
    }

    /**
     * 惰性构建工具注册表
     */
    private function registry(): ToolRegistry
    {
        if ($this->registry === null) {
            $this->registry = new ToolRegistry($this->config);
        }

        return $this->registry;
    }
}
