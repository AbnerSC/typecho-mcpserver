<?php
/**
 * Typecho MCP Server —— 将 Typecho 内容管理能力暴露为 MCP (Model Context Protocol) 服务。
 *
 * 环境要求：Typecho 1.3.0（不兼容 1.2.x 及以下），PHP >= 7.4（推荐 8.x）
 *
 * @package TypechoMcpServer
 * @author AbnerSC
 * @version 1.0.2
 * @link https://github.com/AbnerSC/typecho-mcpserver.git
 * @dependence Typecho 1.3.0
 */

namespace TypechoPlugin\TypechoMcpServer;

use Typecho\Common;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\Logger;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/autoloader.php';

class Plugin implements PluginInterface
{
    /** 插件显示名称 */
    public const DISPLAY_NAME = 'Typecho MCP Server';

    /** MCP 端点路由名称 */
    public const ROUTE_NAME = 'mcp_server';

    /**
     * 默认端点路径
     */
    public static function defaultRoutePath(): string
    {
        return '/mcp';
    }

    /**
     * 激活插件：环境检查、生成 Token、建审计日志表、注册路由
     *
     * @throws \Typecho\Plugin\Exception
     * @throws \Typecho\Db\Exception
     */
    public static function activate()
    {
        // 版本锁定：仅支持 Typecho 1.3.0 及以上（不兼容 1.2.x，Widget 命名空间差异过大）
        if (version_compare(Common::VERSION, '1.3.0', '<')) {
            throw new \Typecho\Plugin\Exception(
                _t('本插件仅支持 Typecho 1.3.0 及以上版本，当前版本为 %s，请先升级 Typecho', Common::VERSION)
            );
        }

        if (!function_exists('random_bytes')) {
            throw new \Typecho\Plugin\Exception(_t('当前 PHP 环境缺少 random_bytes 函数，无法安全生成 Token'));
        }

        $config = Config::load();

        // 首次激活时生成 48 位随机 Token
        if (!$config->token()) {
            Helper::configPlugin('TypechoMcpServer', [
                'token' => bin2hex(random_bytes(24)),
            ]);
        }

        // 审计日志表（环形保留最近 500 条）
        Logger::ensureTable();

        // 注册 MCP 端点路由（可配置路径，规避与既有路由冲突）
        Helper::addRoute(
            self::ROUTE_NAME,
            $config->routePath(),
            'TypechoPlugin\\TypechoMcpServer\\Action',
            'action'
        );

        return _t('%s 已激活。请在插件配置页查看 MCP 接入信息（端点 URL 与 Token）。', self::DISPLAY_NAME);
    }

    /**
     * 禁用插件：移除路由（配置与日志数据保留，便于再次启用）
     */
    public static function deactivate()
    {
        Helper::removeRoute(self::ROUTE_NAME);
    }

    /**
     * 插件配置面板：使用说明信息块 + 配置表单
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form)
    {
        $config = Config::load();
        $options = Options::alloc();

        // -------- 使用说明信息块（5.5.1：接入信息 / 客户端示例 / 操作步骤 / 安全提示）--------
        $endpoint = rtrim($options->siteUrl, '/') . $config->routePath();
        $token = $config->token() ?: '(尚未生成，重新激活插件即可)';

        $clientJson = json_encode([
            'mcpServers' => [
                'typecho' => [
                    'url' => $endpoint,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $workBuddyJson = json_encode([
            'mcpServers' => [
                'typecho' => [
                    'type' => 'http',
                    'url' => $endpoint . '?token=' . $token,
                    'description' => 'Typecho 博客内容管理',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $publishState = $config->allowPublish()
            ? '已开启：AI 可以直接发布文章/页面'
            : '已关闭（默认）：AI 创建的文章均为草稿，需到后台人工发布';
        $deleteState = $config->allowDelete()
            ? '已开启：AI 可以删除文章/分类/标签（请谨慎）'
            : '已关闭（默认）：AI 无法执行删除操作';

        $guide = <<<HTML
<div class="typecho-mcp-guide" style="background:#f8f9fa;border:1px solid #e2e6ea;border-radius:6px;padding:16px 20px;margin:0 0 16px;line-height:1.9;font-size:13px;">
  <h3 style="margin:0 0 10px;font-size:15px;">MCP 接入信息</h3>
  <p style="margin:4px 0;">端点 URL：<code id="mcp-endpoint" style="background:#fff;padding:2px 6px;border-radius:3px;">{$endpoint}</code></p>
  <p style="margin:4px 0;">Token：<code id="mcp-token" style="background:#fff;padding:2px 6px;border-radius:3px;user-select:all;">{$token}</code></p>
  <h3 style="margin:14px 0 10px;font-size:15px;">客户端配置示例（Claude Desktop / Cursor / Cline / Qoder CN）</h3>
  <pre id="mcp-client-json" style="background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:10px;overflow:auto;user-select:all;">{$clientJson}</pre>
  <h3 style="margin:14px 0 10px;font-size:15px;">WorkBuddy（或不支持自定义 Header 的客户端，Token 经 URL 传递）</h3>
  <pre id="mcp-workbuddy-json" style="background:#fff;border:1px solid #e2e6ea;border-radius:4px;padding:10px;overflow:auto;user-select:all;">{$workBuddyJson}</pre>
  <h3 style="margin:14px 0 10px;font-size:15px;">操作步骤</h3>
  <p style="margin:4px 0;">① 复制上方配置片段 → ② 粘贴到 AI 客户端的 MCP 设置并启用 → ③ 在对话中让 AI 查询 / 撰写 / 管理博客内容。</p>
  <h3 style="margin:14px 0 10px;font-size:15px;">安全提示</h3>
  <p style="margin:4px 0;">· Token 须严格保密，泄漏后请立即在下方重置；建议站点启用 HTTPS，避免 Token 明文传输。</p>
  <p style="margin:4px 0;">· 发布开关当前{$publishState}。</p>
  <p style="margin:4px 0;">· 删除开关当前{$deleteState}。</p>
  <p style="margin:4px 0;">· 附件上传：AI 可上传附件到媒体库（返回访问 URL）；MCP 不提供附件删除，删除请到后台「管理 → 附件」。</p>
</div>
HTML;

        $form->addItem(
            (new \Typecho\Widget\Helper\Layout('div'))->html($guide)
        );

        // -------- 配置表单 --------
        $token = new Text(
            'token',
            null,
            '',
            _t('API Token'),
            _t('48 位随机凭据，用于 MCP 客户端认证。如需重置，清空后保存将自动生成新 Token（旧 Token 立即失效）。')
        );
        $token->input->setAttribute('style', 'width:100%;user-select:all;');
        $form->addInput($token->addRule('xssCheck', _t('请不要在 Token 中使用特殊字符')));

        $users = self::userOptions();
        $bindUid = new Select(
            'bindUid',
            $users,
            '1',
            _t('执行身份'),
            _t('所有 MCP 写操作将以该用户的身份落库（文章作者归属、权限判定）。默认站长。')
        );
        $form->addInput($bindUid);

        $allowPublish = new Checkbox(
            'allowPublish',
            ['on' => _t('允许 AI 直接发布（非草稿）内容')],
            '',
            _t('高危操作：直接发布'),
            _t('默认关闭。关闭时 AI 创建/更新的内容一律为草稿，需人工到后台发布。')
        );
        $form->addInput($allowPublish);

        $allowDelete = new Checkbox(
            'allowDelete',
            ['on' => _t('允许 AI 执行删除操作')],
            '',
            _t('高危操作：删除'),
            _t('默认关闭。关闭时 delete_post / delete_category / delete_tag 均不可用。')
        );
        $form->addInput($allowDelete);

        $autoCreateCategory = new Checkbox(
            'autoCreateCategory',
            ['on' => _t('写文章时分类不存在则自动创建')],
            ['on'],
            _t('自动创建分类'),
            _t('默认开启。关闭时传入不存在的分类名将报错。')
        );
        $form->addInput($autoCreateCategory);

        $urlTokenEnabled = new Checkbox(
            'urlTokenEnabled',
            ['on' => _t('允许通过 URL 参数传递 Token（?token=）')],
            ['on'],
            _t('URL Token 通道'),
            _t('默认开启，供无法自定义请求头的客户端使用。注意 Token 可能进入服务器访问日志，介意可关闭。')
        );
        $form->addInput($urlTokenEnabled);

        $routePath = new Text(
            'routePath',
            null,
            self::defaultRoutePath(),
            _t('端点路径'),
            _t('仅允许字母、数字、连字符与斜杠，默认 /mcp。修改后需重新激活插件生效。')
        );
        $routePath->input->setAttribute('style', 'width:240px;');
        $form->addInput($routePath->addRule('xssCheck', _t('请不要在路径中使用特殊字符')));

        $logEnabled = new Checkbox(
            'logEnabled',
            ['on' => _t('记录审计日志（保留最近 500 条）')],
            ['on'],
            _t('审计日志'),
            _t('记录每次 tools/call 的时间、IP、工具、参数摘要、结果与耗时，便于溯源误操作。')
        );
        $form->addInput($logEnabled);
    }

    /**
     * 个人用户的配置面板（不提供）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 接管配置保存：Token 空则重置、路径规范化、路由联动重注册
     *
     * @param array $settings 表单提交值
     * @param bool $isInit 是否为初始化（激活时）
     * @return bool 返回 true 表示由插件自行处理保存
     */
    public static function configHandle(array $settings, bool $isInit): bool
    {
        $old = Config::load();

        // Token 清空 → 重新生成（旧 Token 立即失效）
        if (empty($settings['token']) || !is_string($settings['token'])) {
            $settings['token'] = bin2hex(random_bytes(24));
        }

        // 端点路径规范化：仅允许 / 开头、字母数字连字符斜杠
        $routePath = isset($settings['routePath']) && is_string($settings['routePath'])
            ? $settings['routePath'] : '';
        $routePath = '/' . trim(preg_replace('/[^\w\/-]/', '', $routePath), '/');
        if ($routePath === '/' || $routePath === '') {
            $routePath = self::defaultRoutePath();
        }
        $settings['routePath'] = $routePath;

        Helper::configPlugin('TypechoMcpServer', $settings);

        // 端点路径变更且插件处于启用状态时，同步重注册路由
        if (!$isInit && $routePath !== $old->routePath()) {
            Helper::removeRoute(self::ROUTE_NAME);
            Helper::addRoute(
                self::ROUTE_NAME,
                $routePath,
                'TypechoPlugin\\TypechoMcpServer\\Action',
                'action'
            );
        }

        return true;
    }

    /**
     * 后台用户下拉选项（uid => 名称[组]）
     */
    private static function userOptions(): array
    {
        $options = [];
        try {
            $db = \Typecho\Db::get();
            $users = $db->fetchAll($db->select('uid', 'name', 'group')
                ->from('table.users')->order('table.users.uid', \Typecho\Db::SORT_ASC));
            foreach ($users as $user) {
                $options[(string) $user['uid']] = $user['name'] . '（' . $user['group'] . '）';
            }
        } catch (\Exception $e) {
            // 环境异常时回退为默认站长
        }

        if (empty($options)) {
            $options['1'] = _t('站长（uid=1）');
        }

        return $options;
    }
}
