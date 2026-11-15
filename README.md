# typecho mcpserver

> 将Typecho接口通过MCP Server 暴露出来，用于提供给`AI Agent`使用

> 版本兼容性：***Typecho 1.3.0***

## Typecho 官方资料
1. Typecho 源码仓库 `https://github.com/typecho/typecho.git`
2. Typecho 插件开发说明文档 `https://docs.typecho.org/develop/plugin/`

## GitHub
- 仓库 `https://github.com/AbnerSC/typecho-mcpserver.git`
- [![GitHubStars](https://img.shields.io/github/stars/AbnerSC/typecho-mcpserver?style=flat&label=Stars)](https://github.com/AbnerSC/typecho-mcpserver/stargazers)

## 部署安装

> 环境要求：Typecho 1.3.0+（不兼容 1.2.x），PHP >= 7.4（推荐 8.x），零 Composer 依赖

1. **下载部署包**：前往 [Releases](https://github.com/AbnerSC/typecho-mcpserver/releases) 页面，下载 `typecho-mcpserver-vX.Y.Z.zip`（发布时由 CI 自动打包，Gitea 与 GitHub 仓库的 Release 附件一致）
2. **上传插件**：解压得到 `TypechoMcpServer/` 目录，放入博客根目录的 `usr/plugins/` 下：

   ```
   usr/plugins/
   └── TypechoMcpServer/
       ├── Plugin.php        # 插件入口（激活、配置面板）
       ├── Action.php        # MCP 端点（默认 /mcp 路由）
       ├── autoloader.php    # src/ 自动加载
       └── src/              # 协议内核与业务服务
   ```

3. **启用插件**：后台「控制台 → 插件」中启用 **Typecho MCP Server**
4. **接入客户端**：插件设置页已给出端点 URL、Token 与各客户端配置示例（Claude Desktop / Cursor / Cline / Qoder CN / WorkBuddy），复制粘贴到对应客户端的 MCP 设置即可
5. **升级**：后台禁用插件 → 用新版 `TypechoMcpServer/` 覆盖旧目录 → 重新启用（Token 与配置保留，无需重新接入客户端）

## 接口清单
- 撰写文章（新文章默认保存草稿，不发布文章）
- 创建页面
- 编辑文章
- 管理分类
- 管理标签