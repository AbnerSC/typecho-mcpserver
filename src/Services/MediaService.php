<?php
/**
 * 附件（媒体库）领域服务：仅上传，不提供删除
 *
 * 复用 Typecho 官方 Widget\Upload::uploadHandle（与后台上传、XmlRpc
 * newMediaObject 同款姿势）：天然获得扩展名白名单（allowedAttachmentTypes）
 * 校验，并兼容第三方存储插件（uploadHandle 钩子，如云存储）。
 * 落库复用 Widget\Upload 沙箱 insert，authorId 归属执行身份。
 *
 * 安全策略：MCP 仅暴露 upload_attachment，附件删除请到 Typecho 后台媒体管理。
 *
 * @package TypechoMcpServer
 */

namespace TypechoMcpServer\Services;

use TypechoMcpServer\Exception\ServiceException;
use TypechoMcpServer\Support\Config;
use TypechoMcpServer\Support\TypechoAdapter;
use Widget\Upload;

class MediaService
{
    /** @var Config */
    private $config;

    /** Typecho 判定图片附件的扩展名集合（Base\Contents::___attachment 同款） */
    public const IMAGE_TYPES = ['jpg', 'jpeg', 'gif', 'png', 'tiff', 'bmp', 'webp', 'avif'];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 上传附件（内容经 Base64 编码传入）
     *
     * @param array $args filename* / content_base64* / title / description / parentId
     * @return array
     * @throws ServiceException
     */
    public function uploadAttachment(array $args): array
    {
        $filename = trim((string) ($args['filename'] ?? ''));
        $base64 = (string) ($args['content_base64'] ?? '');

        if ($filename === '') {
            throw new ServiceException('缺少有效参数：filename（含扩展名的文件名）');
        }

        $bytes = $this->decodeBase64($base64);
        if ($bytes === '') {
            throw new ServiceException('content_base64 不是有效的 Base64 内容（解码后为空）');
        }

        // 关联内容：显式传入时不做静默忽略，方便 AI 自行纠正
        $parentId = (int) ($args['parentId'] ?? 0);
        if ($parentId > 0) {
            $db = TypechoAdapter::db();
            $exists = $db->fetchRow(
                $db->select('cid')->from('table.contents')
                    ->where('cid = ? AND (type = ? OR type = ? OR type = ? OR type = ?)', $parentId, 'post', 'post_draft', 'page', 'page_draft')
                    ->limit(1)
            );

            if (empty($exists)) {
                throw new ServiceException("parentId 关联的内容不存在：cid={$parentId}");
            }
        }

        // 官方上传处理：扩展名白名单 + 第三方存储插件钩子
        $file = ['name' => $filename, 'bytes' => $bytes, 'size' => strlen($bytes)];
        $result = Upload::uploadHandle($file);

        if ($result === false) {
            throw new ServiceException($this->failureReason($filename));
        }

        // 描述合入附件元信息（与后台「编辑附件」同款存储位置：attachment json）
        $description = trim((string) ($args['description'] ?? ''));
        if ($description !== '') {
            $result['description'] = $description;
        }

        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            $title = $result['name'];
        }

        // 落库结构（Widget\Upload::upload / XmlRpc mwNewMediaObject 同款）
        $struct = [
            'title'        => $title,
            'slug'         => $result['name'],
            'type'         => 'attachment',
            'status'       => 'publish',
            'text'         => json_encode($result),
            'allowComment' => 1,
            'allowPing'    => 0,
            'allowFeed'    => 1,
        ];

        if ($parentId > 0) {
            $struct['parent'] = $parentId;
        }

        [$cid, $attachment, $permalink] = $this->insertAttachment($struct, $result);

        if ($cid <= 0) {
            throw new ServiceException('附件保存失败，请检查执行身份配置或稍后重试。');
        }

        return [
            'cid' => $cid,
            'title' => $attachment['name'] ?? $title,
            'filename' => $result['name'],
            'url' => $attachment['url'] ?? '',
            'permalink' => $permalink,
            'path' => $result['path'],
            'size' => (int) $result['size'],
            'mime' => $result['mime'],
            'isImage' => in_array($result['type'], self::IMAGE_TYPES, true),
            'parentId' => $parentId,
            'message' => '附件已上传。MCP 不提供附件删除，如需删除请到 Typecho 后台「管理 → 附件」。',
        ];
    }

    // ------------------------------------------------------------------
    // 内部方法
    // ------------------------------------------------------------------

    /**
     * Base64 解码（容忍 data URL 前缀与空白字符，严格校验）
     *
     * @param string $base64
     * @return string 解码后的二进制内容（失败返回空串）
     */
    private function decodeBase64(string $base64): string
    {
        $base64 = trim($base64);
        if ($base64 === '') {
            return '';
        }

        // 容忍 data:...;base64, 前缀
        if (preg_match('/^data:[^;,]*(;charset=[^;,]*)?;base64,/i', $base64)) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $base64), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * 上传失败原因细化（扩展名白名单 / 存储失败）
     *
     * @param string $filename
     * @return string
     */
    private function failureReason(string $filename): string
    {
        $name = str_replace(['"', '<', '>'], '', $filename);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === '') {
            return '文件名缺少扩展名，无法识别文件类型。请传入完整文件名，如 photo.png';
        }

        try {
            $allowed = TypechoAdapter::options()->allowedAttachmentTypes;
            $allowed = is_array($allowed)
                ? $allowed
                : array_values(array_filter(array_map('trim', explode(',', (string) $allowed))));
        } catch (\Throwable $e) {
            $allowed = [];
        }

        if (!empty($allowed) && !in_array($ext, $allowed, true)) {
            return "扩展名 .{$ext} 不在站点允许上传的类型中（当前允许：" . implode(' / ', $allowed) . '）';
        }

        return '附件保存失败：上传目录不可写或存储插件返回失败，请检查 usr/uploads 目录权限';
    }

    /**
     * 沙箱插入附件（复刻 Widget\Upload::upload 的完整时序含插件钩子）
     *
     * @param array $struct contents 行结构
     * @param array $result uploadHandle 返回的附件元信息（beforeUpload 钩子需要）
     * @return array [cid, attachment(元信息数组), permalink]
     */
    private function insertAttachment(array $struct, array $result): array
    {
        $cid = 0;
        $attachment = [];
        $permalink = '';

        TypechoAdapter::withUpload(function (Upload $widget) use ($struct, $result, &$cid, &$attachment, &$permalink) {
            $widget->pluginHandle()->call('beforeUpload', $result);

            $cid = $widget->insert($struct);

            if ($cid > 0) {
                TypechoAdapter::db()->fetchRow(
                    $widget->select()
                        ->where('table.contents.cid = ?', $cid)
                        ->where('table.contents.type = ?', 'attachment'),
                    [$widget, 'push']
                );

                $widget->pluginHandle()->call('upload', $widget);

                $attachmentConfig = $widget->attachment; // url / isImage 由 Typecho 原生生成
                if ($attachmentConfig !== null) {
                    $attachment = $attachmentConfig->toArray();
                }

                try {
                    $permalink = (string) $widget->permalink;
                } catch (\Throwable $e) {
                    $permalink = ''; // 附件路由反解析失败时回退为空
                }
            }
        });

        return [$cid, $attachment, $permalink];
    }
}
