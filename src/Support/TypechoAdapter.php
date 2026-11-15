<?php
/**
 * Typecho 1.3.0 适配层：Widget 实例化 / 身份注入 / 基础设施统一封装
 *
 * 复用写入逻辑的标准姿势（与官方 XmlRpc 同款）：
 *   $widget = PostEdit::alloc(null, $input, function ($widget) {
 *       $widget->writePost();
 *   });
 *
 * alloc 的 request 沙箱把 $input 注入为 widget 的 request 参数；
 * widget 内部的 redirect/Notice 被 Response 沙箱拦截（抛 Terminal 后由
 * Widget::widget 捕获），不会中断当前 MCP 请求。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Support;

use Typecho\Db;
use Typecho\Widget;
use Widget\Contents\Page\Edit as PageEdit;
use Widget\Contents\Post\Edit as PostEdit;
use Widget\Metas\Category\Edit as CategoryEdit;
use Widget\Metas\Tag\Edit as TagEdit;
use Widget\Options;
use Widget\Upload;
use Widget\User;

class TypechoAdapter
{
    /** @var int|null 已注入的执行身份 */
    private static $loggedUid;

    /**
     * 以配置的执行身份临时登录（User widget 是单例，同一请求内幂等）
     *
     * @param int $uid
     * @return bool
     */
    public static function loginAs(int $uid): bool
    {
        if (self::$loggedUid === $uid) {
            return true;
        }

        $ok = User::alloc()->simpleLogin($uid); // 临时登录：不写 Cookie、不改密码
        if ($ok) {
            self::$loggedUid = $uid;
        }

        return $ok;
    }

    /** 数据库连接 */
    public static function db(): Db
    {
        return Db::get();
    }

    /** 站点 Options */
    public static function options(): Options
    {
        return Options::alloc();
    }

    /**
     * 在 request 沙箱中执行文章写入回调
     *
     * 注意：request 含 cid 时必须先调 prepare()（prepareEdit 加载已有行至 widget），
     * 否则 writePost 的 save/publish 分支会误走 insert 新建而非更新。
     *
     * @param array $input 注入 widget request 的参数
     * @param callable $callback function (PostEdit $widget)
     * @return PostEdit
     */
    public static function withPostEdit(array $input, callable $callback): PostEdit
    {
        $wrapped = function (PostEdit $widget) use ($input, $callback) {
            if (!empty($input['cid']) && !is_array($input['cid'])) {
                $widget->prepare();
            }
            $callback($widget);
        };

        /** @var PostEdit $widget */
        $widget = PostEdit::alloc(null, $input, $wrapped);

        return $widget;
    }

    /**
     * 在 request 沙箱中执行页面写入回调（含 cid 时先 prepare 加载已有行）
     *
     * @param array $input
     * @param callable $callback function (PageEdit $widget)
     * @return PageEdit
     */
    public static function withPageEdit(array $input, callable $callback): PageEdit
    {
        $wrapped = function (PageEdit $widget) use ($input, $callback) {
            if (!empty($input['cid']) && !is_array($input['cid'])) {
                $widget->prepare();
            }
            $callback($widget);
        };

        /** @var PageEdit $widget */
        $widget = PageEdit::alloc(null, $input, $wrapped);

        return $widget;
    }

    /**
     * 在 request 沙箱中执行分类写入回调
     *
     * @param array $input
     * @param callable $callback function (CategoryEdit $widget)
     * @return CategoryEdit
     */
    public static function withCategoryEdit(array $input, callable $callback): CategoryEdit
    {
        /** @var CategoryEdit $widget */
        $widget = CategoryEdit::alloc(null, $input, $callback);

        return $widget;
    }

    /**
     * 在 request 沙箱中执行标签写入回调
     *
     * @param array $input
     * @param callable $callback function (TagEdit $widget)
     * @return TagEdit
     */
    public static function withTagEdit(array $input, callable $callback): TagEdit
    {
        /** @var TagEdit $widget */
        $widget = TagEdit::alloc(null, $input, $callback);

        return $widget;
    }

    /**
     * 在 request 沙箱中执行附件上传回调（落库复用 Upload::insert 原生逻辑）
     *
     * @param callable $callback function (Upload $widget)
     * @return Upload
     */
    public static function withUpload(callable $callback): Upload
    {
        /** @var Upload $widget */
        $widget = Upload::alloc(null, [], $callback);

        return $widget;
    }

    /**
     * 后台编辑链接
     *
     * @param int $cid
     * @param string $type post|page
     * @return string
     */
    public static function adminEditUrl(int $cid, string $type = 'post'): string
    {
        $script = $type === 'page' ? 'write-page.php' : 'write-post.php';

        return rtrim(self::options()->adminUrl, '/') . '/' . $script . '?cid=' . $cid;
    }

    /**
     * 前台链接（草稿无前台链接时返回空）
     *
     * @param int $cid
     * @return string
     */
    public static function permalink(int $cid): string
    {
        try {
            $content = Widget::widget('Widget\Base\Contents@' . $cid . '@mcp');
            $row = self::db()->fetchRow(
                self::db()->select('cid', 'slug', 'created', 'authorId', 'type', 'status')
                    ->from('table.contents')->where('cid = ?', $cid)->limit(1)
            );
            if (!empty($row)) {
                $content->push($row);
                $url = $content->permalink;
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $e) {
            // permalink 反解析失败（如草稿）静默返回空
        }

        return '';
    }

    /**
     * ISO 8601 时间 → Typecho created 约定时间戳
     *
     * 与 XmlRpc/后台 getCreated 保持同一换算：
     *   created = 绝对时间戳 - 站点时区偏移 + 服务器时区偏移
     *
     * @param string $iso 如 2026-11-14T09:00:00+08:00
     * @return int|null 无法解析返回 null
     */
    public static function parseTimeToCreated(string $iso): ?int
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return null;
        }

        $options = self::options();

        return $ts - (int) $options->timezone + (int) $options->serverTimezone;
    }

    /**
     * Typecho created 时间戳 → ISO 8601（带站点时区偏移）
     *
     * Typecho 约定：created 是服务器本地挂钟时间的 timestamp，
     * 站点时区显示的绝对时刻 = created + siteTz - serverTz（秒）。
     */
    public static function createdToIso(int $created): string
    {
        $options = self::options();
        $offset = (int) $options->timezone;
        $sign = $offset >= 0 ? '+' : '-';
        $abs = abs($offset);
        $tz = sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));

        return gmdate('Y-m-d\TH:i:s', $created + $offset - (int) $options->serverTimezone) . $tz;
    }
}
