<?php
/**
 * 工具注册表：17 个 MCP Tools 的元数据（名称/描述/inputSchema/handler）集中注册与查找
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Tools;

use TypechoMcpServer\Services\CategoryService;
use TypechoMcpServer\Services\MediaService;
use TypechoMcpServer\Services\PageService;
use TypechoMcpServer\Services\PostService;
use TypechoMcpServer\Services\SiteService;
use TypechoMcpServer\Services\TagService;
use TypechoMcpServer\Support\Config;

class ToolRegistry
{
    /** @var ToolDefinition[] 按名称索引 */
    private $tools = [];

    public function __construct(Config $config)
    {
        $posts = new PostService($config);
        $pages = new PageService($config);
        $categories = new CategoryService($config);
        $tags = new TagService($config);
        $site = new SiteService($config);
        $media = new MediaService($config);

        $this->registerReadTools($posts, $categories, $tags, $site);
        $this->registerWriteTools($posts, $pages, $categories, $tags);
        $this->registerMediaTools($media);
    }

    /** @return ToolDefinition[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    // ------------------------------------------------------------------
    // 读工具
    // ------------------------------------------------------------------

    private function registerReadTools(PostService $posts, CategoryService $categories, TagService $tags, SiteService $site): void
    {
        $this->add(new ToolDefinition(
            'list_posts',
            '分页查询文章列表（支持状态过滤与关键词搜索）。用于获取 cid 以便后续编辑/删除，或浏览站点内容。也可用 type=page 查询独立页面。',
            [
                'type' => 'object',
                'properties' => [
                    'page'     => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => '页码，从 1 开始'],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10, 'description' => '每页数量，最大 50'],
                    'status'   => ['type' => 'string', 'enum' => ['all', 'draft', 'publish', 'hidden', 'private'], 'default' => 'all', 'description' => '状态过滤'],
                    'keyword'  => ['type' => 'string', 'description' => '标题/正文关键词（模糊匹配）'],
                    'type'     => ['type' => 'string', 'enum' => ['post', 'page'], 'default' => 'post', 'description' => '内容类型：文章或页面'],
                ],
            ],
            function (array $args) use ($posts) {
                return $posts->listPosts(
                    (int) ($args['page'] ?? 1),
                    (int) ($args['pageSize'] ?? 10),
                    (string) ($args['status'] ?? 'all'),
                    (string) ($args['keyword'] ?? ''),
                    (string) ($args['type'] ?? 'post')
                );
            }
        ));

        $this->add(new ToolDefinition(
            'get_post',
            '读取文章/页面详情：完整 Markdown 正文、状态、分类、标签。编辑前建议先调用本工具确认内容。',
            [
                'type' => 'object',
                'properties' => [
                    'cid' => ['type' => 'integer', 'description' => '内容 ID（来自 list_posts）'],
                ],
                'required' => ['cid'],
            ],
            function (array $args) use ($posts) {
                return $posts->getPost((int) $args['cid']);
            }
        ));

        $this->add(new ToolDefinition(
            'list_categories',
            '查询全部分类（mid、名称、slug、父级、文章计数）。创建文章前可用于确认分类名称。',
            ['type' => 'object', 'properties' => []],
            function () use ($categories) {
                return $categories->listCategories();
            }
        ));

        $this->add(new ToolDefinition(
            'list_tags',
            '查询全部标签（mid、名称、文章计数）。',
            ['type' => 'object', 'properties' => []],
            function () use ($tags) {
                return $tags->listTags();
            }
        ));

        $this->add(new ToolDefinition(
            'get_site_info',
            '获取站点基本信息：站名、描述、URL、时区、文章/页面/分类/标签统计，以及当前插件安全开关状态（能否直接发布/删除）。',
            ['type' => 'object', 'properties' => []],
            function () use ($site) {
                return $site->getSiteInfo();
            }
        ));
    }

    // ------------------------------------------------------------------
    // 写工具
    // ------------------------------------------------------------------

    private function registerWriteTools(PostService $posts, PageService $pages, CategoryService $categories, TagService $tags): void
    {
        $postWriteSchema = [
            'type' => 'object',
            'properties' => [
                'title'        => ['type' => 'string', 'description' => '文章标题'],
                'content'      => ['type' => 'string', 'description' => 'Markdown 正文'],
                'slug'         => ['type' => 'string', 'description' => '自定义缩略名(URL)，留空自动生成；冲突时自动追加 -1 后缀'],
                'status'       => ['type' => 'string', 'enum' => ['draft', 'publish', 'hidden', 'password', 'private'], 'default' => 'draft', 'description' => '默认 draft；非 draft 需站点开启 allowPublish'],
                'password'     => ['type' => 'string', 'description' => 'status=password 时的访问密码（必填）'],
                'category'     => ['type' => 'string', 'description' => '分类名称（单个）；不存在时按站点配置自动创建或报错'],
                'tags'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '标签名称列表，不存在的标签自动创建'],
                'excerpt'      => ['type' => 'string', 'description' => '摘要，留空自动截取（以 <!--more--> 分隔实现）'],
                'created'      => ['type' => 'string', 'description' => '发布时间 ISO 8601，如 2026-11-14T09:00:00+08:00'],
                'allowComment' => ['type' => 'boolean', 'default' => true, 'description' => '是否允许评论'],
            ],
        ];

        $this->add(new ToolDefinition(
            'create_post',
            '创建文章，默认保存为草稿（安全策略：站点未开启直接发布时禁止非草稿状态）。分类/标签按名称传入，自动解析与创建。',
            $postWriteSchema + ['required' => ['title', 'content']],
            function (array $args) use ($posts) {
                return $posts->createPost($args);
            }
        ));

        $updateSchema = $postWriteSchema;
        $updateSchema['properties']['cid'] = ['type' => 'integer', 'description' => '文章 ID（编辑目标）'];

        $this->add(new ToolDefinition(
            'update_post',
            '编辑文章：传入 cid 与需要变更的字段（未传字段保持不变）。状态变更受 allowPublish 约束。建议先 get_post 确认后再修改。',
            $updateSchema + ['required' => ['cid']],
            function (array $args) use ($posts) {
                return $posts->updatePost($args);
            }
        ));

        $this->add(new ToolDefinition(
            'delete_post',
            '删除文章（含其分类/标签关联与评论）。默认禁用，需站点开启 allowDelete。删除不可恢复，请先 get_post 确认目标。',
            [
                'type' => 'object',
                'properties' => [
                    'cid' => ['type' => 'integer', 'description' => '文章 ID'],
                ],
                'required' => ['cid'],
            ],
            function (array $args) use ($posts) {
                return $posts->deletePost($args);
            }
        ));

        $pageSchema = [
            'type' => 'object',
            'properties' => [
                'title'        => ['type' => 'string', 'description' => '页面标题'],
                'content'      => ['type' => 'string', 'description' => 'Markdown 正文'],
                'slug'         => ['type' => 'string', 'description' => '自定义缩略名(URL)'],
                'status'       => ['type' => 'string', 'enum' => ['draft', 'publish', 'hidden'], 'default' => 'draft', 'description' => '默认 draft；非 draft 需站点开启 allowPublish'],
                'template'     => ['type' => 'string', 'description' => '自定义模板文件名（如 page-links.php），留空用默认模板'],
                'order'        => ['type' => 'integer', 'description' => '页面排序'],
                'created'      => ['type' => 'string', 'description' => '发布时间 ISO 8601'],
                'allowComment' => ['type' => 'boolean', 'default' => false, 'description' => '是否允许评论'],
            ],
        ];

        $this->add(new ToolDefinition(
            'create_page',
            '创建独立页面（关于/友链等），默认保存为草稿。',
            $pageSchema + ['required' => ['title', 'content']],
            function (array $args) use ($pages) {
                return $pages->createPage($args);
            }
        ));

        $updatePageSchema = $pageSchema;
        $updatePageSchema['properties']['cid'] = ['type' => 'integer', 'description' => '页面 ID'];

        $this->add(new ToolDefinition(
            'update_page',
            '编辑独立页面：传入 cid 与需要变更的字段。可用 list_posts(type=page) 查询页面 cid。',
            $updatePageSchema + ['required' => ['cid']],
            function (array $args) use ($pages) {
                return $pages->updatePage($args);
            }
        ));

        $this->add(new ToolDefinition(
            'create_category',
            '新建分类（支持父级与描述）。名称在同父级下必须唯一。',
            [
                'type' => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'description' => '分类名称'],
                    'slug'        => ['type' => 'string', 'description' => '缩略名，留空按名称生成'],
                    'parentId'    => ['type' => 'integer', 'description' => '父分类 mid，默认 0（顶级）'],
                    'description' => ['type' => 'string', 'description' => '分类描述'],
                ],
                'required' => ['name'],
            ],
            function (array $args) use ($categories) {
                return $categories->createCategory($args);
            }
        ));

        $this->add(new ToolDefinition(
            'update_category',
            '编辑分类：传入 mid 与需要变更的字段。',
            [
                'type' => 'object',
                'properties' => [
                    'mid'         => ['type' => 'integer', 'description' => '分类 ID'],
                    'name'        => ['type' => 'string', 'description' => '新名称'],
                    'slug'        => ['type' => 'string', 'description' => '新缩略名'],
                    'parentId'    => ['type' => 'integer', 'description' => '新父分类 mid'],
                    'description' => ['type' => 'string', 'description' => '新描述'],
                ],
                'required' => ['mid'],
            ],
            function (array $args) use ($categories) {
                return $categories->updateCategory($args);
            }
        ));

        $this->add(new ToolDefinition(
            'delete_category',
            '删除分类（其下文章解除关联、子分类上移至其父级）。默认禁用，需站点开启 allowDelete。',
            [
                'type' => 'object',
                'properties' => [
                    'mid' => ['type' => 'integer', 'description' => '分类 ID'],
                ],
                'required' => ['mid'],
            ],
            function (array $args) use ($categories) {
                return $categories->deleteCategory($args);
            }
        ));

        $this->add(new ToolDefinition(
            'create_tag',
            '新建标签。名称必须唯一。',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '标签名称'],
                    'slug' => ['type' => 'string', 'description' => '缩略名，留空按名称生成'],
                ],
                'required' => ['name'],
            ],
            function (array $args) use ($tags) {
                return $tags->createTag($args);
            }
        ));

        $this->add(new ToolDefinition(
            'update_tag',
            '编辑标签：传入 mid 与需要变更的字段。',
            [
                'type' => 'object',
                'properties' => [
                    'mid'  => ['type' => 'integer', 'description' => '标签 ID'],
                    'name' => ['type' => 'string', 'description' => '新名称'],
                    'slug' => ['type' => 'string', 'description' => '新缩略名'],
                ],
                'required' => ['mid'],
            ],
            function (array $args) use ($tags) {
                return $tags->updateTag($args);
            }
        ));

        $this->add(new ToolDefinition(
            'delete_tag',
            '删除标签（文章关联同步解除）。默认禁用，需站点开启 allowDelete。',
            [
                'type' => 'object',
                'properties' => [
                    'mid' => ['type' => 'integer', 'description' => '标签 ID'],
                ],
                'required' => ['mid'],
            ],
            function (array $args) use ($tags) {
                return $tags->deleteTag($args);
            }
        ));
    }

    // ------------------------------------------------------------------
    // 媒体工具（附件仅上传，不提供删除）
    // ------------------------------------------------------------------
    private function registerMediaTools(MediaService $media): void
    {
        $this->add(new ToolDefinition(
            'upload_attachment',
            '上传附件（图片/文档等）到站点媒体库，返回附件 cid 与访问 URL。扩展名须在站点允许上传的类型内。安全策略：MCP 仅支持上传附件，不提供附件删除（删除请到后台媒体管理）。',
            [
                'type' => 'object',
                'properties' => [
                    'filename'       => ['type' => 'string', 'description' => '文件名（含扩展名），如 photo.png、report.pdf'],
                    'content_base64' => ['type' => 'string', 'description' => '文件二进制内容的 Base64 编码（可含 data:...;base64, 前缀）'],
                    'title'          => ['type' => 'string', 'description' => '附件标题，留空使用文件名'],
                    'description'    => ['type' => 'string', 'description' => '附件描述'],
                    'parentId'       => ['type' => 'integer', 'description' => '可选，关联内容 ID（文章/页面 cid，来自 list_posts）'],
                ],
                'required' => ['filename', 'content_base64'],
            ],
            function (array $args) use ($media) {
                return $media->uploadAttachment($args);
            }
        ));
    }

    /**
     * 注册工具（重名抛异常，防止注册表被意外污染）
     */
    private function add(ToolDefinition $definition): void
    {
        if (isset($this->tools[$definition->name()])) {
            throw new \LogicException('Duplicate tool name: ' . $definition->name());
        }

        $this->tools[$definition->name()] = $definition;
    }
}
