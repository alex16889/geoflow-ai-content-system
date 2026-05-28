<?php
/**
 * One-click SEO material wizard helpers.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function seo_material_wizard_column_exists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_hash($db) . ':' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    return $cache[$key] = function_exists('db_column_exists') && db_column_exists($db, $table, $column);
}

function seo_material_wizard_clean_text(string $value, int $maxLength = 500): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    $value = strip_tags($value);
    if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return trim($value);
}

function seo_material_wizard_split_keywords(string $value): array {
    $parts = preg_split('/[\r\n,，、;；]+/u', $value) ?: [];
    $keywords = [];
    foreach ($parts as $part) {
        $keyword = seo_material_wizard_clean_text((string) $part, 80);
        if ($keyword !== '') {
            $keywords[material_keyword_dedupe_key($keyword)] = $keyword;
        }
    }
    return array_values($keywords);
}

function seo_material_wizard_unique_lines(array $values, int $limit, int $maxLength = 120): array {
    $result = [];
    foreach ($values as $value) {
        $line = seo_material_wizard_clean_text((string) $value, $maxLength);
        if ($line === '') {
            continue;
        }
        $key = material_keyword_dedupe_key($line);
        if (isset($result[$key])) {
            continue;
        }
        $result[$key] = $line;
        if (count($result) >= $limit) {
            break;
        }
    }
    return array_values($result);
}

function seo_material_wizard_slug(string $value, string $fallback = 'seo-material'): string {
    $source = seo_material_wizard_clean_text($value, 120);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        if ($converted !== false && trim($converted) !== '') {
            $source = $converted;
        }
    }

    $slug = strtolower($source);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = $fallback . '-' . substr(md5($value), 0, 8);
    }
    return substr($slug, 0, 80);
}

function seo_material_wizard_fetch_active_ai_model(PDO $db): ?array {
    if (!geoflow_db_table_exists($db, 'ai_models')) {
        return null;
    }

    $stmt = $db->query("
        SELECT *
        FROM ai_models
        WHERE status = 'active'
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ORDER BY failover_priority ASC, id ASC
        LIMIT 1
    ");
    $model = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    return $model ?: null;
}

function seo_material_wizard_collect_context(PDO $db, int $siteId): array {
    $site = function_exists('geoflow_current_site') ? geoflow_current_site() : [];
    $siteName = seo_material_wizard_clean_text((string) ($site['name'] ?? ''), 120);
    if ($siteName === '') {
        $siteName = seo_material_wizard_clean_text((string) get_setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'GEOflow'), 120);
    }

    $siteTitle = seo_material_wizard_clean_text((string) ($site['site_title'] ?? ''), 160);
    if ($siteTitle === '') {
        $siteTitle = seo_material_wizard_clean_text((string) get_setting('site_title', $siteName), 160);
    }

    $siteDescription = seo_material_wizard_clean_text((string) ($site['description'] ?? ''), 500);
    if ($siteDescription === '') {
        $siteDescription = seo_material_wizard_clean_text((string) get_setting('site_description', get_setting('site_desc', '')), 500);
    }

    $siteKeywords = seo_material_wizard_split_keywords((string) get_setting('site_keywords', ''));
    $primaryDomain = seo_material_wizard_clean_text((string) ($site['primary_domain'] ?? ''), 180);

    $keywordRows = [];
    if (geoflow_db_table_exists($db, 'keywords') && geoflow_db_table_exists($db, 'keyword_libraries')) {
        $scope = geoflow_site_scope_condition('keyword_libraries', 'kl');
        $searchVolumeSelect = seo_material_wizard_column_exists($db, 'keywords', 'search_volume')
            ? 'k.search_volume'
            : 'NULL AS search_volume';
        $sourceSelect = seo_material_wizard_column_exists($db, 'keywords', 'source')
            ? 'k.source'
            : "'' AS source";
        $orderBy = seo_material_wizard_column_exists($db, 'keywords', 'search_volume')
            ? 'ORDER BY COALESCE(k.search_volume, 0) DESC, k.id ASC'
            : 'ORDER BY k.id ASC';
        $sql = "
            SELECT k.keyword, {$searchVolumeSelect}, {$sourceSelect}
            FROM keywords k
            INNER JOIN keyword_libraries kl ON kl.id = k.library_id
            " . ($scope !== '' ? 'WHERE ' . $scope : '') . "
            {$orderBy}
            LIMIT 100
        ";
        $stmt = $db->query($sql);
        $keywordRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $keywords = [];
    foreach ($keywordRows as $row) {
        $keyword = seo_material_wizard_clean_text((string) ($row['keyword'] ?? ''), 100);
        if ($keyword !== '') {
            $keywords[material_keyword_dedupe_key($keyword)] = $keyword;
        }
    }
    foreach ($siteKeywords as $keyword) {
        $keywords[material_keyword_dedupe_key($keyword)] = $keyword;
    }

    $categories = [];
    if (geoflow_db_table_exists($db, 'categories')) {
        $scope = geoflow_site_scope_condition('categories');
        $stmt = $db->query("SELECT name FROM categories WHERE 1=1" . ($scope !== '' ? ' AND ' . $scope : '') . " ORDER BY id ASC LIMIT 30");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []) as $category) {
            $category = seo_material_wizard_clean_text((string) $category, 80);
            if ($category !== '') {
                $categories[] = $category;
            }
        }
    }

    $articles = [];
    if (geoflow_db_table_exists($db, 'articles') && seo_material_wizard_column_exists($db, 'articles', 'title')) {
        $where = ['1=1'];
        if (seo_material_wizard_column_exists($db, 'articles', 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }
        if (seo_material_wizard_column_exists($db, 'articles', 'status')) {
            $where[] = "status IN ('published', 'draft', 'pending')";
        }
        $scope = geoflow_site_scope_condition('articles');
        if ($scope !== '') {
            $where[] = $scope;
        }
        $stmt = $db->query("SELECT title FROM articles WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 20");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : []) as $title) {
            $title = seo_material_wizard_clean_text((string) $title, 120);
            if ($title !== '') {
                $articles[] = $title;
            }
        }
    }

    if (empty($keywords)) {
        foreach (array_merge([$siteName, $siteTitle], $categories, ['新手指南', '入口导航', '常见问题']) as $seed) {
            $seed = seo_material_wizard_clean_text((string) $seed, 80);
            if ($seed !== '') {
                $keywords[material_keyword_dedupe_key($seed)] = $seed;
            }
        }
    }

    return [
        'site_id' => $siteId,
        'site_name' => $siteName !== '' ? $siteName : 'GEOflow Site',
        'site_title' => $siteTitle !== '' ? $siteTitle : $siteName,
        'site_description' => $siteDescription,
        'site_keywords' => array_values($siteKeywords),
        'primary_domain' => $primaryDomain,
        'keywords' => array_slice(array_values($keywords), 0, 60),
        'keyword_rows' => $keywordRows,
        'categories' => array_values(array_unique($categories)),
        'articles' => array_values(array_unique($articles)),
    ];
}

function seo_material_wizard_default_titles(array $context, int $count): array {
    $siteName = $context['site_name'];
    $keywords = !empty($context['keywords']) ? $context['keywords'] : [$siteName];
    $templates = [
        '{keyword}是什么？新手先看这篇完整说明',
        '{keyword}入口与常见问题整理',
        '{keyword}打不开怎么办？常见原因和处理思路',
        '{keyword}安全访问注意事项：新手避坑指南',
        '{keyword}怎么用？流程、风险和替代选择',
        '{keyword}下载前需要确认的 5 个细节',
        '{site}：{keyword}相关资源导航',
        '{keyword}使用指南：从入口到常见问题',
        '{keyword}靠谱吗？判断方法和注意事项',
        '{keyword}最新整理：入口、功能和问题排查',
    ];

    $titles = [];
    foreach ($keywords as $keyword) {
        foreach ($templates as $template) {
            $titles[] = strtr($template, [
                '{keyword}' => $keyword,
                '{site}' => $siteName,
            ]);
            if (count($titles) >= $count) {
                return seo_material_wizard_unique_lines($titles, $count, 160);
            }
        }
    }

    return seo_material_wizard_unique_lines($titles, $count, 160);
}

function seo_material_wizard_default_image_prompts(array $context, int $count): array {
    $siteName = $context['site_name'];
    $keywords = array_slice($context['keywords'] ?? [], 0, 8);
    $keywordText = implode('、', $keywords);
    if ($keywordText === '') {
        $keywordText = $siteName;
    }

    $items = [
        [
            'title' => $siteName . ' 入口导航信息图',
            'alt' => $siteName . ' 入口导航与新手访问说明',
            'caption' => '用一张图说明站点入口、适合人群和访问注意事项。',
            'prompt' => '生成一张清晰的信息图，主题是' . $siteName . '入口导航，包含新手访问路径、注意事项、FAQ 三块内容，中文排版，干净可信。',
        ],
        [
            'title' => $siteName . ' 新手流程卡片',
            'alt' => $siteName . ' 新手使用流程图',
            'caption' => '按步骤解释用户从搜索到阅读内容的完整路径。',
            'prompt' => '生成一张横向流程图，展示搜索关键词、打开导航、阅读说明、核对风险、收藏更新五个步骤。',
        ],
        [
            'title' => $siteName . ' 常见问题摘要图',
            'alt' => $siteName . ' 常见问题与答案摘要',
            'caption' => '适合放在 FAQ 文章或专题页中的问题摘要图。',
            'prompt' => '生成一张 FAQ 摘要卡，围绕' . $keywordText . '，用 4 个问题卡片解释入口、下载、安全和打不开的处理方式。',
        ],
        [
            'title' => $siteName . ' 安全提醒图',
            'alt' => $siteName . ' 安全访问和风险提醒',
            'caption' => '提醒用户不要轻信假冒入口、弹窗广告和不明下载。',
            'prompt' => '生成一张安全提醒海报，风格克制专业，突出核对域名、不下载未知安装包、不相信弹窗广告。',
        ],
        [
            'title' => $siteName . ' 关键词主题合集',
            'alt' => $siteName . ' 核心关键词主题合集',
            'caption' => '把核心关键词分组，方便文章内配图和专题页使用。',
            'prompt' => '生成一张关键词主题地图，核心词包括：' . $keywordText . '，以主题气泡方式展示，中文。',
        ],
        [
            'title' => $siteName . ' 内容更新路线图',
            'alt' => $siteName . ' 内容更新与专题规划路线图',
            'caption' => '展示本站后续内容更新方向，适合首页或说明页。',
            'prompt' => '生成一张内容路线图，包含新手指南、入口说明、下载问题、安全提醒、常见问题、更新日志六个板块。',
        ],
    ];

    return array_slice($items, 0, max(1, $count));
}

function seo_material_wizard_default_knowledge(array $context): string {
    $siteName = $context['site_name'];
    $siteTitle = $context['site_title'];
    $description = $context['site_description'] !== '' ? $context['site_description'] : '站点用于整理面向用户的导航、说明、FAQ 和更新内容。';
    $keywords = array_slice($context['keywords'] ?? [], 0, 30);
    $categories = array_slice($context['categories'] ?? [], 0, 12);
    $articles = array_slice($context['articles'] ?? [], 0, 8);

    $keywordLines = !empty($keywords) ? '- ' . implode("\n- ", $keywords) : '- 入口导航';
    $categoryLines = !empty($categories) ? '- ' . implode("\n- ", $categories) : '- 新手指南' . "\n- 常见问题" . "\n- 安全提醒";
    $articleLines = !empty($articles) ? '- ' . implode("\n- ", $articles) : '- 当前暂无文章，先从入口说明、FAQ、安全提醒三类内容开始。';

    return <<<MARKDOWN
# {$siteName} SEO / GEO 运营知识库

## 站点定位

- 站点名称：{$siteName}
- 站点标题：{$siteTitle}
- 站点说明：{$description}
- 内容目标：让不懂 SEO 的运营者也能围绕用户真实搜索需求，持续产出清晰、安全、可审核的长尾内容。

## 核心关键词

{$keywordLines}

## 推荐分类结构

{$categoryLines}

## 已有内容参考

{$articleLines}

## 内容生成规则

- 每篇文章先回答用户最直接的问题，再补充背景、步骤、风险提醒和 FAQ。
- 标题优先覆盖“是什么、入口、下载、打不开、安全吗、怎么用、常见问题”等真实搜索意图。
- 不使用“官方、唯一、保证、稳赚、无风险”等未经验证或高风险承诺。
- 对入口、下载、账号、安全相关内容必须保留人工审核，不自动发布低质量内容。
- 每篇文章建议 800 字以上；如果信息不足，先做 FAQ/说明页，不要硬凑重复段落。

## 文章结构模板

1. 一句话回答：说明用户搜索这个词通常想解决什么问题。
2. 快速说明：列出入口、功能、适合人群和前置条件。
3. 操作步骤：用 3-6 个步骤写清楚怎么查找、怎么核对、怎么处理异常。
4. 风险提醒：提醒用户核对域名、避免假冒入口、不要下载不明文件。
5. FAQ：至少 3 个常见问题，使用简短直接的答案。
6. 内链建议：链接到同分类文章、站点首页、专题页和常见问题页。

## 图片素材规则

- 图片 alt 必须包含页面主题词和图片用途，例如“{$siteName} 新手访问流程图”。
- 配图优先做信息图、流程图、FAQ 摘要图和安全提醒图，不使用无意义装饰图。
- 图片文件名尽量使用英文短横线 slug，保持可读、可缓存。

## AI 可引用性 / GEO 规则

- 每篇文章保留定义句、步骤清单、FAQ、更新时间和明确的适用边界。
- 重要结论用短句表达，方便 AI 摘要和引用。
- 避免整篇文章只有营销话术；必须有事实、流程、限制条件和用户可执行动作。

MARKDOWN;
}

function seo_material_wizard_build_ai_prompt(array $context, int $titleCount, int $imageCount): string {
    $payload = [
        'site_name' => $context['site_name'],
        'site_title' => $context['site_title'],
        'site_description' => $context['site_description'],
        'primary_domain' => $context['primary_domain'],
        'keywords' => array_slice($context['keywords'] ?? [], 0, 30),
        'categories' => array_slice($context['categories'] ?? [], 0, 12),
        'articles' => array_slice($context['articles'] ?? [], 0, 8),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return <<<PROMPT
你是一个严谨的中文 SEO/GEO 内容策略助手。请根据站点上下文生成一个给新手运营者直接使用的 SEO 素材包。

站点上下文：
{$json}

请只输出严格 JSON，不要 Markdown 代码块，不要解释。JSON 结构必须是：
{
  "titles": ["标题1"],
  "image_prompts": [
    {"title": "图片素材名称", "alt": "图片alt", "caption": "图片说明", "prompt": "给图片模型的中文生成提示词"}
  ],
  "knowledge_markdown": "# 知识库标题\\n..."
}

要求：
1. titles 生成 {$titleCount} 个中文 SEO 标题，面向真实搜索需求，避免标题党。
2. image_prompts 生成 {$imageCount} 个信息图/流程图/FAQ 图素材提示词，必须有 alt 和 caption。
3. knowledge_markdown 写成可直接用于 AI 内容生成的知识库，包含站点定位、关键词、分类、文章模板、图片规则、内链规则、风险边界、FAQ 写法。
4. 不要使用未经证实的“官方、唯一、保证、稳赚、无风险”等承诺。
5. 输出必须可被 json_decode 直接解析。
PROMPT;
}

function seo_material_wizard_decode_ai_json(string $raw): ?array {
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
    $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $json = substr($raw, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function seo_material_wizard_normalize_image_prompts(array $items, int $limit): array {
    $result = [];
    foreach ($items as $item) {
        if (is_string($item)) {
            $item = ['title' => $item, 'alt' => $item, 'caption' => $item, 'prompt' => $item];
        }
        if (!is_array($item)) {
            continue;
        }
        $title = seo_material_wizard_clean_text((string) ($item['title'] ?? ''), 120);
        $alt = seo_material_wizard_clean_text((string) ($item['alt'] ?? $title), 160);
        $caption = seo_material_wizard_clean_text((string) ($item['caption'] ?? $alt), 220);
        $prompt = trim((string) ($item['prompt'] ?? $caption));
        if ($title === '') {
            continue;
        }
        $key = material_keyword_dedupe_key($title);
        if (isset($result[$key])) {
            continue;
        }
        $result[$key] = [
            'title' => $title,
            'alt' => $alt !== '' ? $alt : $title,
            'caption' => $caption !== '' ? $caption : $title,
            'prompt' => $prompt !== '' ? $prompt : $caption,
        ];
        if (count($result) >= $limit) {
            break;
        }
    }
    return array_values($result);
}

function seo_material_wizard_merge_plan(array $context, array $candidate, int $titleCount, int $imageCount, string $source, string $message): array {
    $fallbackTitles = seo_material_wizard_default_titles($context, $titleCount);
    $fallbackImages = seo_material_wizard_default_image_prompts($context, $imageCount);
    $fallbackKnowledge = seo_material_wizard_default_knowledge($context);

    $titles = seo_material_wizard_unique_lines(array_merge(
        is_array($candidate['titles'] ?? null) ? $candidate['titles'] : [],
        $fallbackTitles
    ), $titleCount, 160);

    $images = seo_material_wizard_normalize_image_prompts(
        array_merge(is_array($candidate['image_prompts'] ?? null) ? $candidate['image_prompts'] : [], $fallbackImages),
        $imageCount
    );

    $knowledge = trim((string) ($candidate['knowledge_markdown'] ?? ''));
    if (mb_strlen($knowledge, 'UTF-8') < 500) {
        $knowledge = $fallbackKnowledge;
    }

    return [
        'source' => $source,
        'message' => $message,
        'titles' => $titles,
        'image_prompts' => $images,
        'knowledge_markdown' => $knowledge,
    ];
}

function seo_material_wizard_generate_plan(PDO $db, array $context, array $options): array {
    $titleCount = max(6, min(50, (int) ($options['title_count'] ?? 24)));
    $imageCount = max(1, min(12, (int) ($options['image_count'] ?? 6)));
    $useAi = !empty($options['use_ai']);

    if (!$useAi) {
        return seo_material_wizard_merge_plan($context, [], $titleCount, $imageCount, 'local', '使用本地站点规则生成，未调用 AI 模型。');
    }

    $model = seo_material_wizard_fetch_active_ai_model($db);
    if (!$model) {
        return seo_material_wizard_merge_plan($context, [], $titleCount, $imageCount, 'local', '未找到可用 AI 模型，已使用本地规则兜底生成。');
    }

    try {
        $model['api_key'] = decrypt_ai_api_key($model['api_key'] ?? '');
        if (trim((string) $model['api_key']) === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }
        $engine = new AIEngine($db);
        $raw = $engine->callAI($model, seo_material_wizard_build_ai_prompt($context, $titleCount, $imageCount));
        $decoded = seo_material_wizard_decode_ai_json($raw);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI 返回内容不是可解析 JSON');
        }
        return seo_material_wizard_merge_plan($context, $decoded, $titleCount, $imageCount, 'ai', '已调用站内 AI 模型生成并做安全兜底。');
    } catch (Throwable $e) {
        return seo_material_wizard_merge_plan($context, [], $titleCount, $imageCount, 'local', 'AI 调用失败，已使用本地规则兜底生成：' . $e->getMessage());
    }
}

function seo_material_wizard_xml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function seo_material_wizard_svg(array $image, array $context, int $index): string {
    $siteName = seo_material_wizard_xml($context['site_name']);
    $title = seo_material_wizard_xml($image['title']);
    $caption = seo_material_wizard_xml($image['caption']);
    $keywords = seo_material_wizard_xml(implode('  /  ', array_slice($context['keywords'] ?? [], 0, 4)));
    $colors = [
        ['#0f172a', '#38bdf8', '#ecfeff'],
        ['#12372a', '#34d399', '#f0fdf4'],
        ['#3b1d0f', '#fb923c', '#fff7ed'],
        ['#1f2937', '#818cf8', '#eef2ff'],
        ['#083344', '#22d3ee', '#ecfeff'],
        ['#312e81', '#a78bfa', '#faf5ff'],
    ];
    $palette = $colors[($index - 1) % count($colors)];

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="{$title}">
  <rect width="1200" height="630" fill="{$palette[2]}"/>
  <circle cx="1040" cy="80" r="180" fill="{$palette[1]}" opacity="0.18"/>
  <circle cx="120" cy="560" r="220" fill="{$palette[1]}" opacity="0.14"/>
  <rect x="78" y="72" width="1044" height="486" rx="42" fill="#ffffff" opacity="0.94"/>
  <rect x="118" y="116" width="180" height="38" rx="19" fill="{$palette[0]}"/>
  <text x="145" y="141" fill="#ffffff" font-family="Arial, sans-serif" font-size="18" font-weight="700">SEO MATERIAL</text>
  <text x="118" y="220" fill="{$palette[0]}" font-family="Arial, sans-serif" font-size="54" font-weight="800">{$title}</text>
  <text x="118" y="306" fill="#334155" font-family="Arial, sans-serif" font-size="30" font-weight="600">{$caption}</text>
  <text x="118" y="402" fill="#64748b" font-family="Arial, sans-serif" font-size="24">{$keywords}</text>
  <text x="118" y="500" fill="{$palette[0]}" font-family="Arial, sans-serif" font-size="28" font-weight="700">{$siteName}</text>
  <path d="M875 423h150M875 463h100M875 503h185" stroke="{$palette[1]}" stroke-width="18" stroke-linecap="round"/>
</svg>
SVG;
}

function seo_material_wizard_write_svg(array $image, array $context, int $index, string $relativeDirectory): array {
    $absoluteDirectory = material_library_absolute_path($relativeDirectory);
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('创建图片目录失败');
    }

    $filename = seo_material_wizard_slug($image['title'], 'seo-card') . '-' . $index . '.svg';
    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = material_library_absolute_path($relativePath);
    $svg = seo_material_wizard_svg($image, $context, $index);
    if (file_put_contents($absolutePath, $svg) === false) {
        throw new RuntimeException('写入图片素材失败');
    }
    @chmod($absolutePath, 0644);

    return [
        'filename' => $filename,
        'file_name' => $filename,
        'file_path' => $relativePath,
        'absolute_path' => $absolutePath,
        'file_size' => filesize($absolutePath) ?: strlen($svg),
        'mime_type' => 'image/svg+xml',
        'width' => 1200,
        'height' => 630,
        'seo_filename' => seo_material_wizard_slug($image['alt'], 'seo-image') . '.svg',
    ];
}

function seo_material_wizard_insert_row(PDO $db, string $table, array $values): int {
    $columns = array_keys($values);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($values));
    return db_last_insert_id($db, $table);
}

function seo_material_wizard_create_material_bundle(PDO $db, array $options = []): array {
    $siteId = geoflow_current_site_id();
    if ($siteId <= 0) {
        throw new RuntimeException('当前站点不可用，请先在站点管理中选择站点。');
    }

    $context = seo_material_wizard_collect_context($db, $siteId);
    $plan = seo_material_wizard_generate_plan($db, $context, $options);
    $stamp = date('Ymd-His');
    $siteName = $context['site_name'];
    $createdFiles = [];

    try {
        $db->beginTransaction();

        $titleLibraryValues = [
            'name' => $siteName . ' SEO标题库 ' . $stamp,
        ];
        if (seo_material_wizard_column_exists($db, 'title_libraries', 'site_id')) {
            $titleLibraryValues = ['site_id' => $siteId] + $titleLibraryValues;
        }
        if (seo_material_wizard_column_exists($db, 'title_libraries', 'description')) {
            $titleLibraryValues['description'] = 'SEO素材助手基于当前站点上下文自动生成。';
        }
        if (seo_material_wizard_column_exists($db, 'title_libraries', 'generation_type')) {
            $titleLibraryValues['generation_type'] = $plan['source'] === 'ai' ? 'ai_generated' : 'manual';
        }
        if (seo_material_wizard_column_exists($db, 'title_libraries', 'is_ai_generated')) {
            $titleLibraryValues['is_ai_generated'] = $plan['source'] === 'ai' ? 1 : 0;
        }
        $titleLibraryId = seo_material_wizard_insert_row($db, 'title_libraries', $titleLibraryValues);

        $titleHasKeyword = seo_material_wizard_column_exists($db, 'titles', 'keyword');
        $titleHasAiFlag = seo_material_wizard_column_exists($db, 'titles', 'is_ai_generated');
        foreach ($plan['titles'] as $index => $title) {
            $values = [
                'library_id' => $titleLibraryId,
                'title' => $title,
            ];
            if ($titleHasKeyword) {
                $values['keyword'] = $context['keywords'][$index % max(1, count($context['keywords']))] ?? $siteName;
            }
            if ($titleHasAiFlag) {
                $values['is_ai_generated'] = $plan['source'] === 'ai' ? 1 : 0;
            }
            seo_material_wizard_insert_row($db, 'titles', $values);
        }
        refresh_title_library_count($db, $titleLibraryId);

        $imageLibraryValues = [
            'name' => $siteName . ' SEO图片素材库 ' . $stamp,
        ];
        if (seo_material_wizard_column_exists($db, 'image_libraries', 'site_id')) {
            $imageLibraryValues = ['site_id' => $siteId] + $imageLibraryValues;
        }
        if (seo_material_wizard_column_exists($db, 'image_libraries', 'description')) {
            $imageLibraryValues['description'] = 'SEO素材助手生成的信息图/流程图/FAQ 图基础素材。';
        }
        $imageLibraryId = seo_material_wizard_insert_row($db, 'image_libraries', $imageLibraryValues);

        $relativeDirectory = 'uploads/images/seo-wizard/' . date('Y/m/d') . '/' . $stamp;
        $imageCount = 0;
        foreach ($plan['image_prompts'] as $index => $image) {
            $stored = seo_material_wizard_write_svg($image, $context, $index + 1, $relativeDirectory);
            $createdFiles[] = $stored['file_path'];

            $values = [
                'library_id' => $imageLibraryId,
                'original_name' => $image['title'] . '.svg',
                'file_path' => $stored['file_path'],
                'file_size' => $stored['file_size'],
                'mime_type' => $stored['mime_type'],
            ];
            foreach (['filename', 'file_name', 'width', 'height', 'alt_text', 'caption', 'seo_filename', 'tags'] as $column) {
                if (!seo_material_wizard_column_exists($db, 'images', $column)) {
                    continue;
                }
                if ($column === 'alt_text') {
                    $values[$column] = $image['alt'];
                } elseif ($column === 'caption') {
                    $values[$column] = $image['caption'];
                } elseif ($column === 'tags') {
                    $values[$column] = json_encode(['prompt' => $image['prompt'], 'source' => 'seo_material_wizard'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $values[$column] = $stored[$column] ?? '';
                }
            }
            seo_material_wizard_insert_row($db, 'images', $values);
            $imageCount++;
        }
        refresh_image_library_count($db, $imageLibraryId);

        $knowledgeValues = [
            'name' => $siteName . ' SEO运营知识库 ' . $stamp,
            'content' => $plan['knowledge_markdown'],
        ];
        if (seo_material_wizard_column_exists($db, 'knowledge_bases', 'site_id')) {
            $knowledgeValues = ['site_id' => $siteId] + $knowledgeValues;
        }
        if (seo_material_wizard_column_exists($db, 'knowledge_bases', 'description')) {
            $knowledgeValues['description'] = 'SEO素材助手基于站点上下文生成的内容策略、标题规则、图片规则和风险边界。';
        }
        if (seo_material_wizard_column_exists($db, 'knowledge_bases', 'file_type')) {
            $knowledgeValues['file_type'] = 'markdown';
        }
        if (seo_material_wizard_column_exists($db, 'knowledge_bases', 'word_count')) {
            $knowledgeValues['word_count'] = mb_strlen(strip_tags($plan['knowledge_markdown']), 'UTF-8');
        }
        if (seo_material_wizard_column_exists($db, 'knowledge_bases', 'character_count')) {
            $knowledgeValues['character_count'] = mb_strlen(strip_tags($plan['knowledge_markdown']), 'UTF-8');
        }
        $knowledgeBaseId = seo_material_wizard_insert_row($db, 'knowledge_bases', $knowledgeValues);
        $chunkCount = knowledge_retrieval_sync_chunks($db, $knowledgeBaseId, $plan['knowledge_markdown']);

        $db->commit();

        return [
            'site_name' => $siteName,
            'source' => $plan['source'],
            'message' => $plan['message'],
            'title_library_id' => $titleLibraryId,
            'title_count' => count($plan['titles']),
            'image_library_id' => $imageLibraryId,
            'image_count' => $imageCount,
            'knowledge_base_id' => $knowledgeBaseId,
            'knowledge_chunks' => $chunkCount,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        delete_material_files($createdFiles);
        throw $e;
    }
}
