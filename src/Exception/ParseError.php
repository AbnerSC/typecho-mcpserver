<?php
/**
 * JSON-RPC 解析异常（携带 JSON-RPC 错误码）
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Exception;

class ParseError extends \Exception
{
    /** @var int JSON-RPC 错误码（-32700 / -32600） */
    private $rpcCode;

    public function __construct(string $message, int $rpcCode)
    {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
    }

    public function getRpcCode(): int
    {
        return $this->rpcCode;
    }
}
