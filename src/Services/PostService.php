<?php
/**
 * 文章领域服务：查询 / 草稿创建 / 编辑 / 删除
 *
 * 写入复用 Typecho 官方 Widget\Contents\Post\Edit（与 XmlRpc 同款沙箱姿势），
 * 天然获得 slug 唯一性、分类标签关联、缓存刷新等原生逻辑。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Exception\ServiceException;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;
use Typecho\Db;

class PostService
{
    /** @var Config */
    private $config;

    /** 支持的状态值 */
    public const STATUSES = ['draft', 'publish', 'hidden', 'password', 'private'];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /**
     * 分页查询文章（含独立草稿）
     *
     * @param int $page 从 1 开始
     * @param int $pageSize ≤ 50
     * @param string $status all|draft|publish|hidden|private
     * @param string $keyword 标题/正文模糊
     * @param string $type post|page|all
     * @return array
     */
    public function listPosts(int $page = 1, int $pageSize = 10, string $status = 'all', string $keyword = '', string $type = 'post'): array
    {
        $db = TypechoAdapter::db();
        $page = max(1, $page);
        $pageSize = min(50, max(1, $pageSize));

        $baseType = $type === 'page' ? 'page' : 'post';
        $draftType = $baseType . '_draft';

        $select = $db->select('cid', 'title', 'slug', 'status', 'type', 'created', 'authorId')
            ->from('table.contents');

        if ($status === 'draft') {
            $select->where('type = ?', $draftType);
        } elseif ($status === 'all') {
            $select->where('type = ? OR (type = ? AND parent = 0)', $baseType, $draftType);
        } else {
            $select->where('status = ? AND type = ?', $status, $baseType);
        }

        if ($keyword !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $select->where('(title LIKE ? OR text LIKE ?)', $kw, $kw);
        }

        $rows = $db->fetchAll(
            $select->order('table.contents.created', Db::SORT_DESC)
                ->page($page, $pageSize)
        );

        $total = $this->countBy($baseType, $draftType, $status, $keyword);
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = $this->summarize($row);
        }

        // 附加分类/标签名（一次批量查询）
        $this->attachMetas($posts);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'items' => $posts,
        ];
    }

    /**
     * 文章详情
     *
     * @param int $cid
     * @return array
     * @throws ServiceException
     */
    public function getPost(int $cid): array
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'status', 'type', 'created', 'modified', 'authorId', 'text', 'password', 'allowComment', 'allowPing', 'allowFeed', 'parent')
                ->from('table.contents')
                ->where('cid = ? AND (type = ? OR type = ? OR type = ? OR type = ?)', $cid, 'post', 'post_draft', 'page', 'page_draft')
                ->limit(1)
        );

        if (empty($row)) {
            throw new ServiceException("文章不存在：cid={$cid}");
        }

        $author = $db->fetchRow($db->select('screenName', 'name')->from('table.users')->where('uid = ?', $row['authorId']));

        $metas = $db->fetchAll(
            $db->select('table.metas.type', 'table.metas.mid', 'table.metas.name', 'table.metas.slug')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $cid)
        );

        $categories = [];
        $tags = [];
        foreach ($metas as $meta) {
            if ($meta['type'] === 'category') {
                $categories[] = $meta['name'];
            } elseif ($meta['type'] === 'tag') {
                $tags[] = $meta['name'];
            }
        }

        $text = (string) $row['text'];
        if (strpos($text, '<!--markdown-->') === 0) {
            $text = substr($text, strlen('<!--markdown-->'));
        }

        return [
            'cid' => (int) $row['cid'],
            'type' => strpos($row['type'], 'page') === 0 ? 'page' : 'post',
            'title' => $row['title'],
            'slug' => $row['slug'],
            'status' => $this->normalizeStatus($row['type'], $row['status'], (string) $row['password']),
            'created' => TypechoAdapter::createdToIso((int) $row['created']),
            'modified' => TypechoAdapter::createdToIso((int) $row['modified']),
            'author' => ($author['screenName'] ?? '') !== '' ? $author['screenName'] : ($author['name'] ?? ''),
            'categories' => $categories,
            'tags' => $tags,
            'content' => $text,
            'allowComment' => (bool) $row['allowComment'],
            'isPasswordProtected' => $row['password'] !== '' && $row['password'] !== null,
            'adminEditUrl' => TypechoAdapter::adminEditUrl((int) $row['cid'], strpos($row['type'], 'page') === 0 ? 'page' : 'post'),
        ];
    }

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    /**
     * 创建文章（默认草稿）
     *
     * @throws ServiceException
     */
    public function createPost(array $args): array
    {
        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : '未命名文档';
        $content = (string) ($args['content'] ?? '');
        $status = $args['status'] ?? 'draft';

        if (!in_array($status, self::STATUSES, true)) {
            throw new ServiceException('无效的 status，可选：' . implode('/', self::STATUSES));
        }

        if ($status !== 'draft' && !$this->config->allowPublish()) {
            throw new ServiceException('出于安全策略，站点未开启直接发布（allowPublish=false）。请以草稿（status=draft）保存，到 Typecho 后台人工发布。');
        }

        $input = $this->buildWriteInput($title, $content, $status, $args);

        $widget = TypechoAdapter::withPostEdit($input, function ($widget) {
            $widget->writePost();
        });

        $cid = (int) ($widget->cid ?? 0);
        if ($cid <= 0) {
            throw new ServiceException('文章保存失败，请检查执行身份配置或稍后重试。');
        }

        return $this->writeResult($cid, $status);
    }

    /**
     * 编辑文章（仅传变更项；status 变更受 allowPublish 约束）
     *
     * @throws ServiceException
     */
    public function updatePost(array $args): array
    {
        $cid = (int) ($args['cid'] ?? 0);
        if ($cid <= 0) {
            throw new ServiceException('缺少有效参数：cid');
        }

        $db = TypechoAdapter::db();
        $current = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'text', 'status', 'type', 'password', 'created', 'allowComment', 'allowPing', 'allowFeed')
                ->from('table.contents')
                ->where('cid = ? AND (type = ? OR type = ?)', $cid, 'post', 'post_draft')
                ->limit(1)
        );

        if (empty($current)) {
            throw new ServiceException("文章不存在：cid={$cid}");
        }

        $isDraftRow = $current['type'] === 'post_draft';

        // 目标状态：未传则保持现状
        $currentStatus = $this->normalizeStatus($current['type'], $current['status'], (string) $current['password']);
        $targetStatus = isset($args['status']) ? (string) $args['status'] : $currentStatus;

        if (!in_array($targetStatus, self::STATUSES, true)) {
            throw new ServiceException('无效的 status，可选：' . implode('/', self::STATUSES));
        }

        // 防误发布：草稿转正、或从非公开转为公开，均需 allowPublish
        $becomingPublic = ($isDraftRow && $targetStatus !== 'draft')
            || (!$isDraftRow && $currentStatus !== 'publish' && $targetStatus === 'publish');
        if ($becomingPublic && !$this->config->allowPublish()) {
            throw new ServiceException('出于安全策略，站点未开启直接发布（allowPublish=false）。请保持草稿状态，到 Typecho 后台人工发布。');
        }

        // 回填策略：未传字段沿用当前值，避免 writePost 用默认值覆盖
        $text = isset($args['content']) ? (string) $args['content'] : (string) $current['text'];
        if (strpos($text, '<!--markdown-->') === 0) {
            $text = substr($text, strlen('<!--markdown-->'));
        }
        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : (string) $current['title'];

        // 时间：未传沿用原时间
        $args += ['_keepCreated' => (int) $current['created']];

        $input = $this->buildWriteInput($title, $text, $targetStatus, $args, $current);
        $input['cid'] = $cid;

        // do 决策：目标 draft 走 save（已发布文章产生 revision 草稿，Typecho 原生行为）；目标非 draft 走 publish
        $input['do'] = $targetStatus === 'draft' ? 'save' : 'publish';

        $widget = TypechoAdapter::withPostEdit($input, function ($widget) {
            $widget->writePost();
        });

        $resultCid = (int) ($widget->cid ?? 0);
        if ($resultCid <= 0) {
            throw new ServiceException('文章更新失败，请检查执行身份配置或稍后重试。');
        }

        // do=save 且原文为已发布文章时，widget->cid 指向 revision 的父文章；查库确认最终状态
        return $this->writeResult($resultCid, $targetStatus);
    }

    /**
     * 删除文章（受 allowDelete 约束，默认禁用）
     *
     * @throws ServiceException
     */
    public function deletePost(array $args): array
    {
        if (!$this->config->allowDelete()) {
            throw new ServiceException('出于安全策略，删除操作未开启（allowDelete=false）。请在插件配置中开启后再试，或到 Typecho 后台人工删除。');
        }

        $cid = (int) ($args['cid'] ?? 0);
        if ($cid <= 0) {
            throw new ServiceException('缺少有效参数：cid');
        }

        $db = TypechoAdapter::db();
        $exists = $db->fetchRow(
            $db->select('cid', 'title')->from('table.contents')
                ->where('cid = ? AND (type = ? OR type = ?)', $cid, 'post', 'post_draft')->limit(1)
        );

        if (empty($exists)) {
            throw new ServiceException("文章不存在：cid={$cid}");
        }

        TypechoAdapter::withPostEdit(['cid' => [$cid]], function ($widget) {
            $widget->deletePost();
        });

        $left = $db->fetchRow($db->select('cid')->from('table.contents')->where('cid = ?', $cid)->limit(1));

        return [
            'cid' => $cid,
            'title' => $exists['title'],
            'deleted' => empty($left),
            'message' => empty($left) ? '文章已删除（含其分类/标签关联、评论与草稿）' : '删除未生效，请到后台确认',
        ];
    }

    // ------------------------------------------------------------------
    // 内部方法
    // ------------------------------------------------------------------

    /**
     * 构造 writePost 的 request 沙箱输入
     *
     * @param string $title
     * @param string $content 正文（Markdown，无前缀）
     * @param string $status 目标状态
     * @param array $args 原始工具参数
     * @param array|null $current 编辑时的当前记录
     * @return array
     */
    private function buildWriteInput(string $title, string $content, string $status, array $args, ?array $current = null): array
    {
        $input = [
            'title' => $title,
            'text' => $content,
            'markdown' => 1, // AI 输出 Markdown，与站点 options->markdown 配合
            'do' => $status === 'draft' ? 'save' : 'publish',
            'allowComment' => isset($args['allowComment'])
                ? ($args['allowComment'] ? 1 : 0) : ($current ? (int) $current['allowComment'] : 1),
            'allowPing' => $current ? (int) $current['allowPing'] : 1,
            'allowFeed' => $current ? (int) $current['allowFeed'] : 1,
        ];

        // slug（未传则由 Typecho 自动生成；编辑未传时不传，沿用由 insert 逻辑处理）
        if (isset($args['slug']) && $args['slug'] !== '') {
            $input['slug'] = (string) $args['slug'];
        } elseif ($current && $current['slug'] !== '') {
            $input['slug'] = (string) $current['slug'];
        }

        // 摘要：Typecho 无独立摘要字段，用 <!--more--> 分隔线实现
        if (isset($args['excerpt']) && $args['excerpt'] !== '' && strpos($content, '<!--more-->') === false) {
            $input['text'] = $args['excerpt'] . "\n\n<!--more-->\n\n" . $content;
        }

        // 状态 → visibility 映射（do=publish 时生效）
        if ($status !== 'draft') {
            if ($status === 'password') {
                $password = isset($args['password']) && $args['password'] !== '' ? (string) $args['password'] : '';
                if ($password === '') {
                    throw new ServiceException('status=password 需要同时提供 password 参数');
                }
                $input['visibility'] = 'password';
                $input['password'] = $password;
            } else {
                // publish / hidden / private
                $input['visibility'] = $status;
                if ($status !== 'publish') {
                    $input['password'] = '';
                }
            }
        }

        // 发布时间（ISO 8601 → Typecho created 约定）
        if (isset($args['created']) && is_string($args['created']) && $args['created'] !== '') {
            $created = TypechoAdapter::parseTimeToCreated($args['created']);
            if ($created === null) {
                throw new ServiceException('created 无法解析，请使用 ISO 8601 格式，如 2026-11-14T09:00:00+08:00');
            }
            $input['created'] = $created;
        } elseif (isset($args['_keepCreated'])) {
            $input['created'] = (int) $args['_keepCreated'];
        }

        // 分类（按名称解析 mid）
        if (array_key_exists('category', $args)) {
            $category = trim((string) ($args['category'] ?? ''));
            if ($category === '') {
                $input['category'] = [];
            } else {
                $input['category'] = [$this->resolveCategory($category)];
            }
        } elseif (!$current) {
            // 新建时未传分类 → 默认分类（writePost 原生兜底，不传 key 即用 defaultCategory）
        }

        // 标签（名称数组 → 逗号串，setTags 原生支持自动创建不存在的标签）
        if (array_key_exists('tags', $args)) {
            $tags = $args['tags'];
            if (is_array($tags)) {
                $input['tags'] = implode(',', array_map('strval', $tags));
            } elseif (is_string($tags)) {
                $input['tags'] = $tags;
            } else {
                $input['tags'] = '';
            }
        }

        return $input;
    }

    /**
     * 分类名称 → mid（不存在按配置自动创建或报错）
     *
     * @throws ServiceException
     */
    private function resolveCategory(string $name): int
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchObject(
            $db->select('mid')->from('table.metas')
                ->where('type = ? AND name = ?', 'category', $name)->limit(1)
        );

        if ($row) {
            return (int) $row->mid;
        }

        if (!$this->config->autoCreateCategory()) {
            throw new ServiceException("分类「{$name}」不存在（站点已关闭自动创建分类），请先创建分类或传入已有分类名");
        }

        return (new CategoryService($this->config))->createCategory(['name' => $name])['mid'];
    }

    /**
     * 写操作成功结果
     */
    private function writeResult(int $cid, string $intendedStatus): array
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'status', 'type', 'password')->from('table.contents')->where('cid = ?', $cid)->limit(1)
        );

        $finalStatus = empty($row)
            ? $intendedStatus
            : $this->normalizeStatus($row['type'], $row['status'], (string) $row['password']);

        return [
            'cid' => $cid,
            'title' => $row['title'] ?? '',
            'status' => $finalStatus,
            'adminEditUrl' => TypechoAdapter::adminEditUrl($cid),
            'message' => $finalStatus === 'draft'
                ? '已保存为草稿，请到后台确认发布'
                : ('已保存，当前状态：' . $finalStatus),
        ];
    }

    /**
     * Typecho 行 → 归一化状态（post_draft → draft；带密码的 publish → password）
     */
    private function normalizeStatus(string $type, string $status, string $password): string
    {
        if (preg_match('/_draft$/', $type)) {
            return 'draft';
        }

        if ($password !== '' && $status === 'publish') {
            return 'password';
        }

        return $status;
    }

    /**
     * 列表行摘要
     */
    private function summarize(array $row): array
    {
        return [
            'cid' => (int) $row['cid'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'status' => $this->normalizeStatus($row['type'], $row['status'], ''),
            'created' => TypechoAdapter::createdToIso((int) $row['created']),
            'categories' => [],
            'tags' => [],
        ];
    }

    /**
     * 批量附加分类/标签名（覆盖 items[].categories / tags）
     */
    private function attachMetas(array &$posts): void
    {
        if (empty($posts)) {
            return;
        }

        $cids = array_column($posts, 'cid');
        $db = TypechoAdapter::db();

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $metas = $db->fetchAll(
            $db->select('table.relationships.cid', 'table.metas.type', 'table.metas.name')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where("table.relationships.cid IN ({$placeholders})", ...$cids)
        );

        $map = [];
        foreach ($metas as $meta) {
            $map[(int) $meta['cid']][$meta['type']][] = $meta['name'];
        }

        foreach ($posts as &$post) {
            $cid = $post['cid'];
            $post['categories'] = $map[$cid]['category'] ?? [];
            $post['tags'] = $map[$cid]['tag'] ?? [];
        }
    }

    /**
     * 计数（与 listPosts 同条件）
     */
    private function countBy(string $baseType, string $draftType, string $status, string $keyword): int
    {
        $db = TypechoAdapter::db();

        $select = $db->select(['COUNT(*)' => 'num'])->from('table.contents');

        if ($status === 'draft') {
            $select->where('type = ?', $draftType);
        } elseif ($status === 'all') {
            $select->where('type = ? OR (type = ? AND parent = 0)', $baseType, $draftType);
        } else {
            $select->where('status = ? AND type = ?', $status, $baseType);
        }

        if ($keyword !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $select->where('(title LIKE ? OR text LIKE ?)', $kw, $kw);
        }

        return (int) $db->fetchObject($select)->num;
    }
}
