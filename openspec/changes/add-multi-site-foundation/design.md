# Design: Add Multi-Site Foundation

## Data Model

新增两张表：

- `sites`: 站点主表，保存站点名称、slug、默认站点标记、主域名
- `site_domains`: 站点域名表，支持一个站点绑定多个域名

第一阶段给以下表补 `site_id`：

- `site_settings`
- `categories`
- `authors`
- `articles`
- `tasks`
- `keyword_libraries`
- `title_libraries`
- `image_libraries`
- `knowledge_bases`

保留全局共享：

- `ai_models`
- `prompts`

## Runtime Resolution

新增 `includes/site_context.php`：

- 解析当前请求域名
- 根据 `site_domains` 找站点
- 找不到时回退到默认站点
- 提供当前站点基础 URL
- 提供按站点读取和写入 `site_settings` 的统一方法

## Migration Strategy

不重写现有建表流程，而是在现有 schema 初始化后补一层兼容迁移：

1. 确保 `sites` / `site_domains` 存在
2. 建立默认站点
3. 给老表补 `site_id`
4. 将历史数据全部回填到默认站点
5. 把全局唯一约束改成站点内唯一

这样新库和老库都能平滑进入多站模型。

## Read Path Changes

第一阶段先改这些高价值读取路径：

- 站点设置读取
- 首页文章列表
- 分类页
- 归档页
- 文章详情页关联数据
- 主题预览里的公开内容入口

## Follow-Up Phases

第二阶段：

- 后台站点管理页
- 站点切换器
- 站点级文章/分类/作者/任务列表过滤

第三阶段：

- 多站任务发布链路
- 多站素材库运营体验
- 站点级 sitemap/canonical/robots 输出
