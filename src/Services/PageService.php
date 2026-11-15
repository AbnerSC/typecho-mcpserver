<?php
/**
 * 页面领域服务：创建 / 编辑（页面即 type=page 的 content）
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Exception\ServiceException;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;

class PageService
{
    /** 页面支持的状态（无 password/private） */
    public const STATUSES = ['draft', 'publish', 'hidden'];

    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 创建页面（默认草稿）
     *
     * @throws ServiceException
     */
    public function createPage(array $args): array
    {
        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : '未命名页面';
        $content = (string) ($args['content'] ?? '');
        $status = $args['status'] ?? 'draft';

        if (!in_array($status, self::STATUSES, true)) {
            throw new ServiceException('无效的 status，页面可选：' . implode('/', self::STATUSES));
        }

        if ($status !== 'draft' && !$this->config->allowPublish()) {
            throw new ServiceException('出于安全策略，站点未开启直接发布（allowPublish=false）。请以草稿（status=draft）保存，到 Typecho 后台人工发布。');
        }

        $input = $this->buildInput($title, $content, $status, $args, null);

        $widget = TypechoAdapter::withPageEdit($input, function ($widget) {
            $widget->writePage();
        });

        $cid = (int) ($widget->cid ?? 0);
        if ($cid <= 0) {
            throw new ServiceException('页面保存失败，请检查执行身份配置（需编辑及以上权限）或稍后重试。');
        }

        return $this->writeResult($cid);
    }

    /**
     * 编辑页面（仅传变更项）
     *
     * @throws ServiceException
     */
    public function updatePage(array $args): array
    {
        $cid = (int) ($args['cid'] ?? 0);
        if ($cid <= 0) {
            throw new ServiceException('缺少有效参数：cid');
        }

        $db = TypechoAdapter::db();
        $current = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'text', 'status', 'type', 'created', 'template', 'order', 'allowComment', 'allowPing', 'allowFeed')
                ->from('table.contents')
                ->where('cid = ? AND (type = ? OR type = ?)', $cid, 'page', 'page_draft')
                ->limit(1)
        );

        if (empty($current)) {
            throw new ServiceException("页面不存在：cid={$cid}");
        }

        $isDraftRow = $current['type'] === 'page_draft';
        $currentStatus = $isDraftRow ? 'draft' : (string) $current['status'];
        $targetStatus = isset($args['status']) ? (string) $args['status'] : $currentStatus;

        if (!in_array($targetStatus, self::STATUSES, true)) {
            throw new ServiceException('无效的 status，页面可选：' . implode('/', self::STATUSES));
        }

        $becomingPublic = ($isDraftRow && $targetStatus !== 'draft')
            || (!$isDraftRow && $currentStatus === 'hidden' && $targetStatus === 'publish');
        if ($becomingPublic && !$this->config->allowPublish()) {
            throw new ServiceException('出于安全策略，站点未开启直接发布（allowPublish=false）。请保持草稿/隐藏状态，到 Typecho 后台人工发布。');
        }

        $text = isset($args['content']) ? (string) $args['content'] : (string) $current['text'];
        if (strpos($text, '<!--markdown-->') === 0) {
            $text = substr($text, strlen('<!--markdown-->'));
        }
        $title = isset($args['title']) && $args['title'] !== '' ? (string) $args['title'] : (string) $current['title'];

        $args += ['_keepCreated' => (int) $current['created']];

        $input = $this->buildInput($title, $text, $targetStatus, $args, $current);
        $input['cid'] = $cid;
        $input['do'] = $targetStatus === 'draft' ? 'save' : 'publish';

        $widget = TypechoAdapter::withPageEdit($input, function ($widget) {
            $widget->writePage();
        });

        $resultCid = (int) ($widget->cid ?? 0);
        if ($resultCid <= 0) {
            throw new ServiceException('页面更新失败，请稍后重试。');
        }

        return $this->writeResult($resultCid);
    }

    // ------------------------------------------------------------------

    /**
     * 构造 writePage 的 request 沙箱输入
     */
    private function buildInput(string $title, string $content, string $status, array $args, ?array $current): array
    {
        $input = [
            'title' => $title,
            'text' => $content,
            'markdown' => 1,
            'do' => $status === 'draft' ? 'save' : 'publish',
            'visibility' => $status === 'hidden' ? 'hidden' : 'publish',
            'allowComment' => isset($args['allowComment'])
                ? ($args['allowComment'] ? 1 : 0) : ($current ? (int) $current['allowComment'] : 0),
            'allowPing' => $current ? (int) $current['allowPing'] : 0,
            'allowFeed' => $current ? (int) $current['allowFeed'] : 0,
        ];

        if (isset($args['slug']) && $args['slug'] !== '') {
            $input['slug'] = (string) $args['slug'];
        } elseif ($current && $current['slug'] !== '') {
            $input['slug'] = (string) $current['slug'];
        }

        if (isset($args['template']) && $args['template'] !== '') {
            $input['template'] = (string) $args['template'];
        } elseif ($current && $current['template'] !== '') {
            $input['template'] = (string) $current['template'];
        }

        if (isset($args['order'])) {
            $input['order'] = (int) $args['order'];
        } elseif ($current) {
            $input['order'] = (int) $current['order'];
        }

        if (isset($args['created']) && is_string($args['created']) && $args['created'] !== '') {
            $created = TypechoAdapter::parseTimeToCreated($args['created']);
            if ($created === null) {
                throw new ServiceException('created 无法解析，请使用 ISO 8601 格式，如 2026-11-14T09:00:00+08:00');
            }
            $input['created'] = $created;
        } elseif (isset($args['_keepCreated'])) {
            $input['created'] = (int) $args['_keepCreated'];
        }

        return $input;
    }

    /**
     * 写操作成功结果
     */
    private function writeResult(int $cid): array
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'status', 'type')->from('table.contents')->where('cid = ?', $cid)->limit(1)
        );

        $isDraft = isset($row['type']) && preg_match('/_draft$/', $row['type']);

        return [
            'cid' => $cid,
            'title' => $row['title'] ?? '',
            'status' => $isDraft ? 'draft' : ($row['status'] ?? ''),
            'adminEditUrl' => TypechoAdapter::adminEditUrl($cid, 'page'),
            'message' => $isDraft ? '页面已保存为草稿，请到后台确认发布' : '页面已保存',
        ];
    }
}
