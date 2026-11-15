<?php
/**
 * JSON-RPC 2.0 协议工具（解析、校验、响应/错误封装）
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Protocol;

use TypechoMcpServer\Exception\ParseError;

class JsonRpc
{
    public const VERSION = '2.0';

    /** 标准错误码 */
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * 解析原始请求体
     *
     * @param string $raw
     * @return array{0: bool, 1: array[]} [是否批量, 消息数组]
     * @throws ParseError -32700（JSON 语法错误）/ -32600（空数组/非法结构）
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new ParseError('Parse error: empty request body', self::PARSE_ERROR);
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ParseError('Parse error: ' . json_last_error_msg(), self::PARSE_ERROR);
        }

        if (!is_array($data)) {
            throw new ParseError('Invalid Request: JSON-RPC message must be an object or array', self::INVALID_REQUEST);
        }

        // 批量请求
        if (self::isList($data)) {
            if (empty($data)) {
                throw new ParseError('Invalid Request: empty batch array', self::INVALID_REQUEST);
            }

            return [true, $data];
        }

        return [false, [$data]];
    }

    /**
     * 校验单条 JSON-RPC 消息结构合法性
     *
     * @param mixed $message
     * @return bool
     */
    public static function isValid($message): bool
    {
        if (!is_array($message)) {
            return false;
        }

        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== self::VERSION) {
            return false;
        }

        if (!isset($message['method']) || !is_string($message['method']) || $message['method'] === '') {
            return false;
        }

        if (isset($message['params']) && !is_array($message['params']) && !is_object($message['params'])) {
            return false;
        }

        // id 只允许 string / int / null（通知时省略 id）
        if (array_key_exists('id', $message)) {
            $id = $message['id'];
            if (!is_string($id) && !is_int($id) && !is_null($id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否为通知（无 id 字段）
     */
    public static function isNotification(array $message): bool
    {
        return !array_key_exists('id', $message);
    }

    /**
     * 构造成功响应
     *
     * @param string|int|null $id
     * @param mixed $result
     * @return array
     */
    public static function result($id, $result): array
    {
        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * 构造错误响应
     *
     * @param string|int|null $id
     * @param int $code
     * @param string $message
     * @param mixed $data 附加数据
     * @return array
     */
    public static function error($id, int $code, string $message, $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => $error,
        ];
    }

    /**
     * PHP 数组是否为顺序列表（0..n-1 键）—— 兼容 PHP 7.4 的 array_is_list
     *
     * @param array $array
     * @return bool
     */
    public static function isList(array $array): bool
    {
        $i = 0;
        foreach ($array as $key => $unused) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }
}
