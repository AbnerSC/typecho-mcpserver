<?php
/**
 * 分类领域服务：查询 / 新建 / 编辑 / 删除
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Exception\ServiceException;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;
use Typecho\Db;

class CategoryService
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 分类列表（mid、名称、slug、父级、计数）
     */
    public function listCategories(): array
    {
        $db = TypechoAdapter::db();
        $rows = $db->fetchAll(
            $db->select('mid', 'name', 'slug', 'description', 'parent', 'count', 'order')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('table.metas.order', Db::SORT_ASC)
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['mid']] = $row['name'];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'mid' => (int) $row['mid'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'],
                'parentMid' => (int) $row['parent'],
                'parentName' => $names[(int) $row['parent']] ?? '',
                'postCount' => (int) $row['count'],
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * 新建分类
     *
     * @throws ServiceException
     */
    public function createCategory(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw new ServiceException('缺少有效参数：name');
        }

        $input = [
            'name' => $name,
            'slug' => (string) ($args['slug'] ?? ''),
            'description' => (string) ($args['description'] ?? ''),
            'parent' => (int) ($args['parentId'] ?? 0),
            'do' => 'insert',
        ];

        $widget = TypechoAdapter::withCategoryEdit($input, function ($widget) {
            $widget->insertCategory();
        });

        $mid = (int) ($widget->mid ?? 0);
        if ($mid <= 0) {
            throw new ServiceException('分类创建失败：名称或缩略名可能已存在，或名称无法转换为缩略名。');
        }

        return [
            'mid' => $mid,
            'name' => $name,
            'message' => "分类「{$name}」已创建",
        ];
    }

    /**
     * 编辑分类
     *
     * @throws ServiceException
     */
    public function updateCategory(array $args): array
    {
        $mid = (int) ($args['mid'] ?? 0);
        if ($mid <= 0) {
            throw new ServiceException('缺少有效参数：mid');
        }

        $current = $this->findByMid($mid);
        if ($current === null) {
            throw new ServiceException("分类不存在：mid={$mid}");
        }

        $input = [
            'mid' => $mid,
            'name' => isset($args['name']) && $args['name'] !== '' ? (string) $args['name'] : $current['name'],
            'slug' => isset($args['slug']) && $args['slug'] !== '' ? (string) $args['slug'] : $current['slug'],
            'description' => array_key_exists('description', $args) ? (string) $args['description'] : $current['description'],
            'parent' => isset($args['parentId']) ? (int) $args['parentId'] : (int) $current['parent'],
            'do' => 'update',
        ];

        TypechoAdapter::withCategoryEdit($input, function ($widget) {
            $widget->updateCategory();
        });

        $updated = $this->findByMid($mid);

        return [
            'mid' => $mid,
            'name' => $updated['name'] ?? $input['name'],
            'message' => "分类「{$input['name']}」已更新",
        ];
    }

    /**
     * 删除分类（受 allowDelete 约束；文章关联同步移除，子分类上移至其父级——Typecho 原生行为）
     *
     * @throws ServiceException
     */
    public function deleteCategory(array $args): array
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
            throw new ServiceException("分类不存在：mid={$mid}");
        }

        TypechoAdapter::withCategoryEdit(['mid' => [$mid]], function ($widget) {
            $widget->deleteCategory();
        });

        return [
            'mid' => $mid,
            'name' => $current['name'],
            'deleted' => $this->findByMid($mid) === null,
            'message' => "分类「{$current['name']}」已删除（其下文章解除关联、子分类上移）",
        ];
    }

    /**
     * 按 mid 查分类（不存在返回 null）
     */
    private function findByMid(int $mid): ?array
    {
        $db = TypechoAdapter::db();
        $row = $db->fetchRow(
            $db->select('mid', 'name', 'slug', 'description', 'parent')
                ->from('table.metas')
                ->where('mid = ? AND type = ?', $mid, 'category')
                ->limit(1)
        );

        return $row ?: null;
    }
}
