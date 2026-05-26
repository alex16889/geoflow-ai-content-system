# GEOFlow 更新日志

该文档记录公开仓库可见功能的持续更新。后续每次推送到 GitHub 时，同步更新本文件和英文版 `CHANGELOG_en.md`。

## 2026-05-27

### SEO/GEO ops suite

- 新增动态 `llms.txt` 与 `llms-full.txt`，按当前站点输出 AI crawler 可读站点地图。
- 新增后台 `SEO/GEO 工作台`，覆盖 readiness 审计、搜索表现快照、AI 可见性记录、竞品简报、内链建议、重定向规则、404 日志和图片 SEO 覆盖率。
- 新增 Organization、FAQPage、ItemList 结构化数据 helper，文章页会在检测到清晰 FAQ 问答时输出 FAQPage。
- 新增图片 SEO 元数据列，新上传和 URL 采集图片会自动写入 alt/caption/SEO 文件名元数据。
- 新增 `ROADMAP.md` 与 `docs/seo-geo-audit-checklist.md`，明确已实现能力、后续计划和上线审计边界。

### Open-source downstream release prep

- 补充项目来源与二开说明，明确保留上游 Apache-2.0 授权、`LICENSE`、`NOTICE` 和源码作者声明。
- 新增 `OPEN_SOURCE_RELEASE.md`，记录公开发布边界、下游改动清单和发布前检查流程。
- 移除公开文档和旧初始化链路里的固定后台默认密码说明，改为 `INITIAL_ADMIN_PASSWORD` 或随机初始密码。
- 后台页脚和欢迎页改为“上游来源 + 当前项目仓库 + 二开维护”表述，避免把下游发行版误写成上游作者直接维护。
- 新增 GitHub Actions CI 和开源发布检查脚本，覆盖语法检查、Tailwind 构建、单元护栏测试、依赖审计和敏感信息扫描。

## 2026-04-18

### v1.2

- 新增后台与前台第一阶段中英界面支持：
  - 后台正式管理页支持中英切换
  - 登录页支持独立语言选择
  - 前台公共壳子跟随后台语言显示
- 新增任务 `智能模型切换`：
  - 任务支持 `固定模型` 与 `智能模型切换`
  - 主模型失败时，系统按模型优先级自动尝试下一个可用聊天模型
- 优化模型接入规则：
  - 支持 OpenAI、DeepSeek、MiniMax、智谱 GLM、火山方舟等不同版本化 chat / embedding endpoint
  - 后台模型配置支持基础地址或完整接口
- 优化任务执行体验：
  - `task-execute.php` 改为入队执行，不再同步阻塞页面
  - 直接发布任务的 `published_count` 统计已修正
- 新增前台模板预览与启用能力：
  - 支持独立 `preview/<theme-id>` 动态预览路由
  - 支持主题包 `themes/<theme-id>` 结构
  - 后台网站设置支持模板预览与启用
  - 样板主题 `qiaomu-editorial-20260418` 已进入公开仓库
  - 首页、分类页、归档页卡片摘要会自动清洗 Markdown 符号
- 新增后台首次登录欢迎页：
  - 首次登录后自动弹出欢迎页
  - 欢迎页改为单篇“见面信”结构，默认中文，可切英文
  - footer 新增 `项目说明` 入口，可重新打开欢迎页
  - 新增实现说明文档 `project/ADMIN_WELCOME.md`
- 新增 `geoflow-template` 配套 skill 入口：
  - 用于把参考网址映射为 GEOFlow 兼容主题包
  - 支持输出 `tokens.json`、`mapping.json` 和 preview-first 模板规划
- 升级默认 GEO 提示词：
  - 正文、榜单、关键词、描述提示词更新为长版模板
  - 对齐 GeoFlow 变量规则
- 修复若干后台可用性问题：
  - 数据库时区偏差
  - 文章图片路径缺少前导 `/`
  - 标题 AI 保存时的 PostgreSQL 布尔类型写入错误
  - Provider 默认示例从旧的第三方域名改为更中性的 DeepSeek
