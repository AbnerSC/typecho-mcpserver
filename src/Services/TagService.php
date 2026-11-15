<?php
/**
 * 标签领域服务：查询 / 新建 / 编辑 / 删除
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Exception\ServiceException;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;
use Typecho\Db;

class TagService
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 标签列表（mid、名称、计数）
     */
    public function listTags(): array
    {
        $db = TypechoAdapter::db();
        $rows = $db->fetchAll(
            $db->select('mid', 'name', 'slug', 'count')
                ->from('table.metas')
                ->where('type = ?', 'tag')
                ->order('table.metas.count', Db::SORT_DESC)
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'mid' => (int) $row['mid'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'postCount' => (int) $row['count'],
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * 新建标签
     *
     * @throws ServiceException
     */
    public function createTag(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw new ServiceException('缺少有效参数：name');
        }

        $input = [
            'name' => $name,
            'slug' => (string) ($args['slug'] ?? ''),
            'do' => 'insert',
        ];

        $widget = TypechoAdapter::withTagEdit($input, function ($widget) {
            $widget->insertTag();
        });

        $mid = (int) ($widget->mid ?? 0);
        if ($mid <= 0) {
            throw new ServiceException('标签创建失败：名称可能已存在，或无法转换为缩略名。');
        }

        return [
            'mid' => $mid,
            'name' => $name,
            'message' => "标签「{$name}」已创建",
        ];
    }

    /**
     * 编辑标签
     *
     * @throws ServiceException
     */
    public function updateTag(array $args): array
    {
        $mid = (int) ($args['mid'] ?? 0);
        if ($mid <= 0) {
            throw new ServiceException('缺少有效参数：mid');
        }

        $current = $this->findByMid($mid);
        if ($current === null) {
            throw new ServiceException("标签不存在：mid={$mid}");
        }

        $input = [
            'mid' => $mid,
            'name' => isset($args['name']) && $args['name'] !== '' ? (string) $args['name'] : $current['name'],
            'slug' => isset($args['slug']) && $args['slug'] !== '' ? (string) $args['slug'] : $current['slug'],
            'do' => 'update',
        ];

        TypechoAdapter::withTagEdit($input, function ($widget) {
            $widget->updateTag();
        });

        $updated = $this->findByMid($mid);

        return [
            'mid' => $mid,
            'name' => $updated['name'] ?? $input['name'],
            'message' => "标签「{$input['name']}」已更新",
        ];
    }

    /**
     * 删除标签（受 allowDelete 约束）
     *
     * @throws ServiceException
     */
    public function deleteTag(array $args): array
    {
        if (!$this->config->allowDelete()) {
            throw new ServiceException('出于安全策略，删除操作未开启（allowDelete=false）。请在插件配置中开启后再试，或到 Typecho 后台人工删除。');
        }

        $mid = (int) ($args['mid'] ?? 0);
        if ($mid <= 0) {
            throw new ServiceException('缺少有效参数：mid');
        }

        $current = $this->findByMid($mid);
        if ($current === null) {
            throw new ServiceException("标签不存在：mid={$mid}");
        }

        TypechoAdapter::withTagEdit(['mid' => [$mid]], function ($widget) {
            $widget->deleteTag();
        });

        return [
            'mid' => $mid,
            'name' => $current['name'],
            'deleted' => $this->findByMid($mid) === null,
            'message' => "标签「{$current['name']}」已删除（文章关联同步解除）",
        ];
    }

    /**
     * 按 mid 查标签（不存在返回 null）
     */
    private function findByMid(int $mid): ?array
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchRow(
            $db->select('mid', 'name', 'slug')
                ->from('table.metas')
                ->where('mid = ? AND type = ?', $mid, 'tag')
                ->limit(1)
        );

        return $row ?: null;
    }
}
