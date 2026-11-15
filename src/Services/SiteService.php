<?php
/**
 * 站点信息服务：帮助 AI 理解站点上下文与安全开关状态
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;

class SiteService
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 站点信息与统计（含插件安全开关状态，让 AI 知晓能否发布/删除）
     */
    public function getSiteInfo(): array
    {
        $options = TypechoAdapter::options();
        $db = TypechoAdapter::db();

        $count = static function (string $sqlType) use ($db): int {
            return (int) $db->fetchObject(
                $db->select(['COUNT(*)' => 'num'])->from('table.contents')->where('type = ?', $sqlType)
            )->num;
        };

        $categoryCount = (int) $db->fetchObject(
            $db->select(['COUNT(*)' => 'num'])->from('table.metas')->where('type = ?', 'category')
        )->num;

        $tagCount = (int) $db->fetchObject(
            $db->select(['COUNT(*)' => 'num'])->from('table.metas')->where('type = ?', 'tag')
        )->num;

        // 时区描述
        $offset = (int) $options->timezone;
        $tz = sprintf('%s%02d:%02d', $offset >= 0 ? '+' : '-', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));

        return [
            'siteName' => (string) $options->title,
            'description' => (string) $options->description,
            'siteUrl' => rtrim((string) $options->siteUrl, '/'),
            'theme' => (string) $options->theme,
            'timezone' => 'UTC' . $tz,
            'statistics' => [
                'publishedPosts' => $count('post'),
                'draftPosts' => $count('post_draft'),
                'publishedPages' => $count('page'),
                'draftPages' => $count('page_draft'),
                'categories' => $categoryCount,
                'tags' => $tagCount,
            ],
            'permissions' => [
                'canPublish' => $this->config->allowPublish(),
                'canDelete' => $this->config->allowDelete(),
                'autoCreateCategory' => $this->config->autoCreateCategory(),
                'publishHint' => $this->config->allowPublish()
                    ? '站点允许 AI 直接发布内容'
                    : '站点禁止 AI 直接发布：所有内容须以草稿保存，人工到后台发布',
            ],
        ];
    }
}
