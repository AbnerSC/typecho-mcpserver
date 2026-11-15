<?php
/**
 * MCP HTTP 端点（无状态 Streamable HTTP / JSON 模式）
 *
 * 路由注册：Helper::addRoute('mcp_server', '/mcp', 'TypechoPlugin\TypechoMcpServer\Action', 'action')
 *
 * HTTP 语义（设计方案 5.1.1）：
 *  - POST /mcp  Accept: application/json，Body 为单个 JSON-RPC 2.0 或批量数组
 *  - GET /mcp   405（无状态模式不提供 SSE 流）
 *  - DELETE /mcp 405（无会话可终结）
 *  - Content-Type 非 application/json → 415
 *  - Token 缺失/错误 → 401
 *  - JSON 解析失败 / 非法 JSON-RPC → 400（-32700 / -32600）
 *  - 通知类消息 → 202 Accepted（无 body）
 *
 * @package TypechoMcpServer
 */

namespace TypechoPlugin\TypechoMcpServer;

use TypechoMcpServer\Auth\TokenAuth;
use TypechoMcpServer\Protocol\JsonRpc;
use TypechoMcpServer\Protocol\McpKernel;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\Logger;
use Typecho\Request;
use Typecho\Response;
use Typecho\Widget;
use Widget\ActionInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/autoloader.php';

class Action extends Widget implements ActionInterface
{
    /** 审计日志开关（运行期由 Config 决定） */
    private $config;

    /**
     * 构造后由路由分发调用 execute()，此处不做权限拦截（MCP 走 Token 认证）
     */
    public function execute()
    {
    }

    /**
     * MCP 端点入口
     */
    public function action()
    {
        $this->config = Config::load();
        $this->sendCorsHeaders();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // CORS 预检直接放行
        if ($method === 'OPTIONS') {
            Response::getInstance()->setStatus(204);
            exit;
        }

        if ($method !== 'POST') {
            $this->respondError(405, ['Allow' => 'POST, OPTIONS']);
            return;
        }

        // Content-Type 校验（application/json，可带 charset 参数）
        $contentType = strtolower(trim(explode(';', (string) Request::getInstance()->getContentType() ?? '')[0]));
        if ($contentType !== 'application/json') {
            $this->respondError(415);
            return;
        }

        // Token 认证（Bearer 头 / URL 参数双通道）
        $auth = new TokenAuth($this->config);
        if (!$auth->verify(Request::getInstance())) {
            Response::getInstance()->setHeader('WWW-Authenticate', 'Bearer');
            $this->respondError(401);
            return;
        }

        // JSON-RPC 解析
        $raw = file_get_contents('php://input');
        try {
            [$isBatch, $messages] = JsonRpc::parse($raw);
        } catch (\TypechoMcpServer\Exception\ParseError $e) {
            $this->respondJson(JsonRpc::error(null, $e->getRpcCode(), $e->getMessage()), 400);
            return;
        }

        // 分发处理
        $kernel = new McpKernel($this->config, Request::getInstance()->getIp());
        $results = [];
        $hasNotificationOnly = true;

        foreach ($messages as $message) {
            if (!JsonRpc::isValid($message)) {
                if ($isBatch) {
                    $results[] = JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Invalid Request');
                } else {
                    $this->respondJson(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Invalid Request'), 400);
                    return;
                }
                $hasNotificationOnly = false;
                continue;
            }

            $response = $kernel->handle($message);
            if ($response !== null) {
                $results[] = $response;
                $hasNotificationOnly = false;
            }
        }

        // 全部为通知 → 202 Accepted（无 body）
        if (empty($results) && $hasNotificationOnly) {
            Response::getInstance()->setStatus(202);
            exit;
        }

        $body = $isBatch ? $results : ($results[0] ?? null);
        if ($body === null) {
            Response::getInstance()->setStatus(202);
            exit;
        }

        $this->respondJson($body, 200);
    }

    /**
     * 输出 JSON-RPC JSON 响应
     *
     * @param mixed $body 已编码为数组的 JSON-RPC 响应
     * @param int $status HTTP 状态码
     */
    private function respondJson($body, int $status)
    {
        $response = Response::getInstance();
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /**
     * 输出无 body 的错误响应（401/405/415）
     */
    private function respondError(int $status, array $headers = [])
    {
        $response = Response::getInstance();
        $response->setStatus($status);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        $response->setHeader('Content-Type', 'application/json');
        echo json_encode([
            'jsonrpc' => JsonRpc::VERSION,
            'error' => ['code' => 0, 'message' => self::HTTP_MESSAGES[$status] ?? 'Error'],
            'id' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * CORS 响应头（默认同域不输出跨域头；配置指定 Origin 时放开）
     */
    private function sendCorsHeaders()
    {
        // 同域场景浏览器本身不触发 CORS；仅当未来扩展跨域客户端时在此输出
        // 预留：Access-Control-Allow-Origin 按配置输出
    }

    /** HTTP 状态消息表 */
    private const HTTP_MESSAGES = [
        401 => 'Unauthorized: missing or invalid MCP token',
        405 => 'Method Not Allowed: only POST is supported (stateless MCP endpoint)',
        415 => 'Unsupported Media Type: Content-Type must be application/json',
    ];
}
