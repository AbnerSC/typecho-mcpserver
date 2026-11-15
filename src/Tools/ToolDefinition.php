<?php
/**
 * 工具定义值对象：name / description / inputSchema / handler
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Tools;

class ToolDefinition
{
    /** @var string */
    private $name;

    /** @var string */
    private $description;

    /** @var array JSON Schema（object 类型） */
    private $inputSchema;

    /** @var callable handler(array $arguments): mixed */
    private $handler;

    public function __construct(string $name, string $description, array $inputSchema, callable $handler)
    {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
        $this->handler = $handler;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    public function handler(): callable
    {
        return $this->handler;
    }

    /**
     * required 参数名列表
     *
     * @return string[]
     */
    public function requiredParams(): array
    {
        return $this->inputSchema['required'] ?? [];
    }
}
