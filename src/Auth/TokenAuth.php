<?php
/**
 * Bearer Token / URL Token 双通道认证
 *
 * - Authorization: Bearer <token>（推荐）
 * - GET 参数 ?token=<token>（配置项可关闭，防 Token 进入访问日志）
 * - hash_equals 防时序攻击
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Auth;

use Typecho\Request;
use TypechoMcpServer\Support\Config;

class TokenAuth
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 校验请求携带的 Token
     */
    public function verify(Request $request): bool
    {
        $expected = $this->config->token();

        // 插件未配置 Token（未激活/异常状态）一律拒绝
        if ($expected === '') {
            return false;
        }

        // 通道 1：Authorization: Bearer <token>
        $header = (string) $request->getHeader('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return hash_equals($expected, trim($matches[1]));
        }

        // 通道 2：URL ?token=<token>（可配置关闭）
        if ($this->config->urlTokenEnabled()) {
            $queryToken = $request->get('token', '');
            if (is_string($queryToken) && $queryToken !== '') {
                return hash_equals($expected, $queryToken);
            }
        }

        return false;
    }
}
