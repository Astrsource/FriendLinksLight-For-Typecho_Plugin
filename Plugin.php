<?php
/**
 * 友情链接 Light 版 – JSON 存储、仅存活检查、数据分离
 *
 * @package FriendLinksLight
 * @author Astrsource
 * @version 1.0.0
 * @link https://astrsource.com
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

class FriendLinksLight_Plugin implements PluginInterface
{
    const CACHE_DIR = __DIR__ . '/cache/';
    const DATA_DIR  = __DIR__ . '/data/';

    private static $linksCache = null;

    /* ====================== 激活 / 禁用 ====================== */
    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('需要 PHP cURL 扩展'));
        }

        if (!is_dir(self::CACHE_DIR)) mkdir(self::CACHE_DIR, 0755, true);
        if (!is_dir(self::DATA_DIR))  mkdir(self::DATA_DIR, 0755, true);

        self::initDataFiles();

        Helper::addPanel(3, 'FriendLinksLight/panel.php', _t('友情链接'), _t('管理友情链接'), 'administrator');
        Helper::addAction('FriendLinksLight-update', 'FriendLinksLight_Action');
        Helper::addRoute('FriendLinksLight_cron', '/FriendLinksLight/cron', 'FriendLinksLight_Action', 'cron');

        \Typecho\Plugin::factory('Widget\Abstract\Contents')->contentEx = ['FriendLinksLight_Plugin', 'parseShortcode'];
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->excerptEx = ['FriendLinksLight_Plugin', 'parseShortcode'];

        return _t('Light 版仅保留存活检测，数据分离为 links.json 与 categories.json');
    }

    public static function deactivate()
    {
        $options = self::getPluginOptions();
        if (isset($options->dropTableOnDeactivate) && $options->dropTableOnDeactivate == 1) {
            @unlink(self::DATA_DIR . 'links.json');
            @unlink(self::DATA_DIR . 'categories.json');
            self::clearRenderedCache();
        }
        Helper::removeAction('FriendLinksLight-update');
        Helper::removePanel(3, 'FriendLinksLight/panel.php');
    }

    /* ====================== 配置面板 ====================== */
    public static function config(Form $form)
    {
        $cacheTime = new \Typecho\Widget\Helper\Form\Element\Text('cacheTime', null, '604800', _t('渲染缓存时间（秒）'), _t('前台页面 HTML 缓存的过期时间，默认 7 天'));
        $form->addInput($cacheTime);

        $timeout = new \Typecho\Widget\Helper\Form\Element\Text('timeout', null, '10', _t('请求超时（秒）'), _t('存活检测的超时时间'));
        $form->addInput($timeout);

        $defaultIcon = new \Typecho\Widget\Helper\Form\Element\Text('defaultIcon', null, '/favicon.png', _t('默认图标 URL'), _t('当链接未提供图标时显示的默认图标'));
        $form->addInput($defaultIcon);

        $template = new \Typecho\Widget\Helper\Form\Element\Textarea('template', null,
            '<div class="friendlink-card">'
            . '<div class="result-header"><div class="favicon"><img src="{icon}" alt="favicon"></div><div><div class="title">{title}</div><div class="url-display"><a href="{url}" target="_blank">{url}</a></div></div></div>'
            . '<div class="description"><div class="label">描述</div>{description}</div>'
            . '<div class="badge-group"><span class="badge badge-category">{category}</span><span class="badge badge-update">{last_update}</span><span class="badge badge-status">{alive}</span></div>'
            . '</div>',
            _t('卡片模板'),
            _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}、{category}')
        );
        $form->addInput($template);

        $customCss = new \Typecho\Widget\Helper\Form\Element\Textarea('customCss', null, '.friendlink-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            max-width: 520px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .favicon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            flex-shrink: 0;
        }

        .favicon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .title {
            font-size: 20px;
            font-weight: 600;
            color: #0f172a;
            word-break: break-word;
        }

        .url-display {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
            word-break: break-all;
        }

        .url-display a {
            color: #2563eb;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .url-display a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .description {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            color: #334155;
            line-height: 1.5;
            word-break: break-word;
        }

        .description .label {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        /* ========== 底部 Badge 区域 ========== */
        .badge-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: "SF Mono", "Fira Code", "JetBrains Mono", "Consolas", monospace;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 20px;
            /* 胶囊圆角 */
            line-height: 1;
            white-space: nowrap;
            letter-spacing: 0.3px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: default;
        }

        .badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* 分类 Badge - 蓝紫调 */
        .badge-category {
            background: #ede9fe;
            /* 淡紫 */
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }

        .badge-category::before {
            content: "📁";
            font-size: 11px;
            line-height: 1;
        }

        /* 最后更新 Badge - 蓝绿调 */
        .badge-update {
            background: #e0f2fe;
            /* 淡天蓝 */
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .badge-update::before {
            content: "📅";
            font-size: 11px;
            line-height: 1;
        }

        /* 网站状态 Badge - 动态色调 */
        .badge-status {
            background: #f0fdf4;
            /* 淡绿（默认正常） */
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge-status::before {
            content: "✅";
            font-size: 11px;
            line-height: 1;
        }

        /* 状态异常变体 */
        .error .badge-status{
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .error .badge-status::before {
            content: "❌";
        }

        /* 状态警告变体 */
        .warning .badge-status{
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fed7aa;
        }
        .warning .badge-status::before {
            content: "⚠️";
        }

        /* Badge 内图标与文字间距微调 */
        .badge .badge-icon {
            font-size: 12px;
            line-height: 1;
            flex-shrink: 0;
        }

        /* 响应式：小屏时badge可换行 */
        @media (max-width: 400px) {
            .badge-group {
                gap: 8px;
            }
            .badge {
                font-size: 12px;
                padding: 5px 10px;
                border-radius: 16px;
            }
        ',
            _t('自定义 CSS'), _t('自定义友情链接卡片的 CSS 样式'));
        $form->addInput($customCss);

        $sortOrder = new \Typecho\Widget\Helper\Form\Element\Select('sortOrder', [
            'manual'       => _t('手动排序'),
            'created_desc' => _t('添加时间（新→旧）'),
            'created_asc'  => _t('添加时间（旧→新）'),
            'title_asc'    => _t('标题 A→Z'),
            'title_desc'   => _t('标题 Z→A'),
            'random'       => _t('随机')
        ], 'manual', _t('前台排序方式'), _t('选择友情链接在前台页面中的显示顺序'));
        $form->addInput($sortOrder);

        $skipDeadLinks = new \Typecho\Widget\Helper\Form\Element\Radio('skipDeadLinks', [
            '0' => _t('不跳过'),
            '1' => _t('跳过')
        ], '0', _t('跳过异常网站'), _t('前台默认是否隐藏存活异常的链接（[friendlinks dead] 不受影响）'));
        $form->addInput($skipDeadLinks);

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text('secretKey', null, '', _t('Cron 密钥'));
        $form->addInput($secretKey);

        $dropTable = new \Typecho\Widget\Helper\Form\Element\Radio('dropTableOnDeactivate', [
            '0' => _t('不删除'),
            '1' => _t('<span style="color:red">删除</span>')
        ], '0', _t('禁用时删除数据文件'), _t('选择“删除”后，禁用插件会永久删除 links.json 和 categories.json'));
        $form->addInput($dropTable);
    }

    public static function personalConfig(Form $form) {}

    /* ====================== 短代码解析（支持任意顺序参数） ====================== */
    public static function parseShortcode($content, $widget, $lastResult)
    {
        $content = empty($lastResult) ? $content : $lastResult;
        if (strpos($content, '[friendlinks') !== false) {
            // 通用匹配：[friendlinks 任意内容]
            $pattern = '/\[friendlinks\s*(.*?)\]/i';
            $content = preg_replace_callback($pattern, function ($m) {
                $raw = trim($m[1]);
                $attrs = self::parseShortcodeAttrs($raw);
                $deadOnly = $attrs['dead'] ?? false;
                $containerClass = $attrs['container_class'] ?? 'friendlinks-container';
                $cardClass = $attrs['card_class'] ?? '';
                $categoryId = isset($attrs['category_id']) ? intval($attrs['category_id']) : null;
                $uncategorizedRaw = $attrs['include_uncategorized'] ?? '1';
                if ($uncategorizedRaw === '0' || $uncategorizedRaw === 'false') {
                    $uncategorizedMode = 0;
                } elseif ($uncategorizedRaw === '2') {
                    $uncategorizedMode = 2;
                } else {
                    $uncategorizedMode = 1;
                }
                return self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, $deadOnly);
            }, $content);
        }
        return $content;
    }

    /** 将短代码内部字符串解析为关联数组（key="value" 以及布尔属性 dead） */
    private static function parseShortcodeAttrs($str)
    {
        $attrs = [];
        if (preg_match_all('/([a-z_]+)="([^"]*)"/i', $str, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $attrs[$pair[1]] = $pair[2];
            }
        }
        // 单独检测 dead（可能带空格或紧跟其他属性）
        if (preg_match('/\bdead\b/', $str)) {
            $attrs['dead'] = true;
        }
        return $attrs;
    }

    /* ====================== 前台输出 ====================== */
    public static function output($containerClass = 'friendlinks-container', $cardClass = '', $categoryId = null, $uncategorizedMode = 1)
    {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, false);
    }

    public static function outputDead($containerClass = 'friendlinks-container', $cardClass = '', $categoryId = null, $uncategorizedMode = 1)
    {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, true);
    }

    /**
     * 核心渲染逻辑
     */
    public static function renderLinks($containerClass = 'friendlinks-container', $cardClass = '', $categoryId = null, $uncategorizedMode = 1, $deadOnly = false)
    {
        $options = self::getPluginOptions();
        $cacheTime = intval($options->cacheTime ?? 604800);
        $template = $options->template ?: '<div class="friendlink-card">...</div>';
        $customCss = $options->customCss ?? '';
        $sortOrder = $options->sortOrder ?? 'manual';
        $defaultIcon = $options->defaultIcon ?? '';
        $globalSkipDead = ($options->skipDeadLinks ?? '0') == '1';

        $currentSort = $sortOrder;
        $useRenderCache = ($categoryId === null) && ($uncategorizedMode === 1) && !$deadOnly;

        // 尝试读取渲染缓存
        if ($useRenderCache) {
            $renderKey = 'friendlinks_rendered_' . md5($containerClass . $cardClass . $template . $customCss . $currentSort . $defaultIcon . ($globalSkipDead ? '1' : '0') . ($deadOnly ? '1' : '0'));
            $renderedCacheFile = self::CACHE_DIR . $renderKey . '.html';
            if (file_exists($renderedCacheFile) && (time() - filemtime($renderedCacheFile)) < $cacheTime) {
                return file_get_contents($renderedCacheFile);
            }
        } else {
            $renderedCacheFile = null; // 防止后续使用未定义变量
        }

        $links = self::getFrontLinks(); // 仅取 status=1 的链接
        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';
        }

        // 分类筛选
        if ($categoryId !== null) {
            $links = array_values(array_filter($links, fn($l) => ($l['category_id'] ?? null) == $categoryId));
        } else {
            if ($uncategorizedMode === 0) {
                $links = array_values(array_filter($links, fn($l) => !empty($l['category_id'])));
            } elseif ($uncategorizedMode === 2) {
                $links = array_values(array_filter($links, fn($l) => empty($l['category_id'])));
            }
        }

        // 存活状态筛选
        if ($deadOnly) {
            $links = array_values(array_filter($links, fn($l) => isset($l['alive']) && $l['alive'] == 0));
        } elseif ($globalSkipDead) {
            $links = array_values(array_filter($links, fn($l) => ($l['alive'] ?? null) !== 0));
        }

        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('该条件下暂无链接') . '</p>';
        }

        // 排序
        if ($useRenderCache && $currentSort === 'random') {
            // 按手动排序输出，之后用 JS 打乱
            usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        } else {
            self::sortLinksArray($links, $currentSort);
        }

        // 分类名称映射
        $categories = self::getCategories();
        $catNames = [];
        foreach ($categories as $c) {
            $catNames[$c['id']] = $c['name'];
        }

        // 构建卡片
        $output = '<style>' . $customCss . '</style><div class="' . htmlspecialchars($containerClass) . '">';
        foreach ($links as $link) {
            $icon = $link['icon'] ?: $defaultIcon;
            $lastUpdate = $link['last_update'] ? date('Y-m-d', $link['last_update']) : '';
            $aliveText = isset($link['alive']) ? ($link['alive'] ? '正常' : '异常') : '未知';
            $catName = (!empty($link['category_id']) && isset($catNames[$link['category_id']])) ? $catNames[$link['category_id']] : '未分类';

            $card = str_replace(
                ['{url}', '{title}', '{description}', '{icon}', '{last_update}', '{alive}', '{category}'],
                [
                    htmlspecialchars($link['url']),
                    htmlspecialchars($link['title']),
                    htmlspecialchars($link['description'] ?? ''),
                    htmlspecialchars($icon),
                    htmlspecialchars($lastUpdate),
                    htmlspecialchars($aliveText),
                    htmlspecialchars($catName)
                ],
                $template
            );
            if ($cardClass) {
                $card = str_replace('friendlink-card', 'friendlink-card ' . htmlspecialchars($cardClass), $card);
            }
            $output .= $card;
        }
        $output .= '</div>';

        // 随机排序的 JS 打乱
        if ($currentSort === 'random') {
            $escapedClass = htmlspecialchars($containerClass);
            $output .= <<<HTML
<script>
(function() {
    var container = document.currentScript.previousElementSibling;
    if (container && container.classList.contains('{$escapedClass}')) {
        var cards = Array.from(container.children);
        for (var i = cards.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            container.appendChild(cards[j]);
        }
    }
})();
</script>
HTML;
        }

        // 写入渲染缓存
        if ($useRenderCache && $renderedCacheFile) {
            file_put_contents($renderedCacheFile, $output, LOCK_EX);
        }

        return $output;
    }

    /* ====================== 分类 CRUD ====================== */
    public static function getCategories()
    {
        $cats = self::loadCategories();
        usort($cats, fn($a, $b) => $a['sort'] <=> $b['sort'] ?: $a['id'] <=> $b['id']);
        return $cats;
    }

    public static function getCategory($id)
    {
        foreach (self::loadCategories() as $c) {
            if ($c['id'] == $id) return $c;
        }
        return null;
    }

    public static function addCategory($name, $sort = 0)
    {
        $cats = self::loadCategories();
        $id = 1;
        foreach ($cats as $c) {
            if ($c['id'] >= $id) $id = $c['id'] + 1;
        }
        if ($sort <= 0) {
            $max = 0;
            foreach ($cats as $c) if ($c['sort'] > $max) $max = $c['sort'];
            $sort = $max + 1;
        }
        $cats[] = [
            'id'      => $id,
            'name'    => $name,
            'sort'    => $sort,
            'created' => time()
        ];
        self::saveCategories($cats);
        return true;
    }

    public static function updateCategory($id, $name, $sort = null)
    {
        $cats = self::loadCategories();
        foreach ($cats as $k => $c) {
            if ($c['id'] == $id) {
                $cats[$k]['name'] = $name;
                if ($sort !== null) $cats[$k]['sort'] = intval($sort);
                break;
            }
        }
        self::saveCategories($cats);
        return true;
    }

    public static function deleteCategory($id)
    {
        $cats = self::loadCategories();
        $newCats = [];
        foreach ($cats as $c) {
            if ($c['id'] != $id) $newCats[] = $c;
        }
        self::saveCategories($newCats);

        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if (($l['category_id'] ?? null) == $id) {
                $links[$k]['category_id'] = null;
            }
        }
        self::saveLinks($links);
        return true;
    }

    public static function getCategoryLinkCounts()
    {
        $links = self::loadLinks();
        $cats = self::getCategories();

        $counts = ['uncategorized' => 0];
        foreach ($cats as $c) $counts[$c['id']] = 0;

        foreach ($links as $l) {
            if (empty($l['category_id'])) {
                $counts['uncategorized']++;
            } elseif (isset($counts[$l['category_id']])) {
                $counts[$l['category_id']]++;
            }
        }
        return $counts;
    }

    /* ====================== 链接 CRUD ====================== */
    public static function getMaxSort()
    {
        $links = self::loadLinks();
        $max = 0;
        foreach ($links as $l) if ($l['sort'] > $max) $max = $l['sort'];
        return $max;
    }

    public static function getLinksCount($includeHidden = true, $categoryFilter = 'all')
    {
        $links = self::filterLinks($includeHidden, $categoryFilter);
        return count($links);
    }

    public static function getLinksPaginated($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all', $limit = 10, $offset = 0)
    {
        $links = self::filterLinks($includeHidden, $categoryFilter);
        self::sortLinksArray($links, $orderBy);
        return array_slice($links, $offset, $limit);
    }

    public static function getAllLinks($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all')
    {
        $links = self::filterLinks($includeHidden, $categoryFilter);
        self::sortLinksArray($links, $orderBy);
        return $links;
    }

    public static function getLink($id)
    {
        foreach (self::loadLinks() as $l) if ($l['id'] == $id) return $l;
        return null;
    }

    public static function addLink($data)
    {
        $links = self::loadLinks();

        $id = 1;
        foreach ($links as $l) if ($l['id'] >= $id) $id = $l['id'] + 1;

        $sort = intval($data['sort'] ?? 0);
        if ($sort <= 0) $sort = self::getMaxSort() + 1;

        $link = [
            'id'            => $id,
            'url'           => $data['url'] ?? '',
            'title'         => $data['title'] ?: parse_url($data['url'], PHP_URL_HOST) ?: 'Untitled',
            'description'   => $data['description'] ?? '',
            'icon'          => $data['icon'] ?? '',
            'status'        => intval($data['status'] ?? 1),
            'sort'          => $sort,
            'category_id'   => isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null,
            'last_update'   => time(),
            'created'       => time(),
            'alive'         => null,
            'alive_checked' => 0
        ];
        $links[] = $link;
        self::saveLinks($links);
        return true;
    }

    public static function updateLink($id, $data)
    {
        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if ($l['id'] == $id) {
                $links[$k]['url']         = $data['url'] ?? $l['url'];
                $links[$k]['title']       = $data['title'] ?: (parse_url($data['url'] ?? '', PHP_URL_HOST) ?: $l['title']);
                $links[$k]['description'] = $data['description'] ?? '';
                $links[$k]['icon']        = $data['icon'] ?? '';
                $links[$k]['status']      = intval($data['status'] ?? $l['status']);
                $links[$k]['sort']        = intval($data['sort'] ?? $l['sort']);
                $links[$k]['category_id'] = isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null;
                $links[$k]['last_update'] = time();
                break;
            }
        }
        self::saveLinks($links);
        return true;
    }

    public static function deleteLink($id)
    {
        $links = self::loadLinks();
        $links = array_values(array_filter($links, fn($l) => $l['id'] != $id));
        self::saveLinks($links);
        return true;
    }

    /* ====================== 存活检测 ====================== */
    public static function checkLinkStatus($linkId)
    {
        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if ($l['id'] == $linkId) {
                $alive = self::checkAlive($l['url']);
                $links[$k]['alive'] = $alive ? 1 : 0;
                $links[$k]['alive_checked'] = time();
                self::saveLinks($links);
                return true;
            }
        }
        return false;
    }

    public static function checkAllLinksStatus()
    {
        set_time_limit(0);
        $links = self::loadLinks();
        if (empty($links)) return 0;

        $urls = [];
        foreach ($links as $k => $l) $urls[$k] = $l['url'];

        $results = self::batchCheckAlive($urls);
        $updated = 0;
        foreach ($results as $k => $alive) {
            $links[$k]['alive'] = $alive ? 1 : 0;
            $links[$k]['alive_checked'] = time();
            $updated++;
        }
        self::saveLinks($links);
        return $updated;
    }

    public static function deleteDeadLinks()
    {
        $links = self::loadLinks();
        $before = count($links);
        $links = array_values(array_filter($links, fn($l) => ($l['alive'] ?? 1) != 0));
        self::saveLinks($links);
        return $before - count($links);
    }

    public static function compactSorts()
    {
        $links = self::loadLinks();
        usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        $i = 1;
        foreach ($links as $k => $l) $links[$k]['sort'] = $i++;
        self::saveLinks($links);
    }

    /* ====================== 缓存管理 ====================== */
    public static function refreshCache()
    {
        self::$linksCache = null;
        self::clearRenderedCache();
    }

    public static function getCacheInfo()
    {
        $linksFile = self::DATA_DIR . 'links.json';
        $catsFile  = self::DATA_DIR . 'categories.json';
        $exists = file_exists($linksFile) && file_exists($catsFile);
        $info = [
            'exists'   => $exists,
            'size'     => 0,
            'modified' => 0,
            'ttl'      => 0
        ];
        if ($exists) {
            $info['size'] = filesize($linksFile) + filesize($catsFile);
            $info['modified'] = max(filemtime($linksFile), filemtime($catsFile));
        }
        return $info;
    }

    /* ====================== 内部工具 ====================== */
    private static function getPluginOptions()
    {
        return Helper::options()->plugin('FriendLinksLight');
    }

    private static function initDataFiles()
    {
        if (!file_exists(self::DATA_DIR . 'links.json')) {
            file_put_contents(self::DATA_DIR . 'links.json', '[]', LOCK_EX);
        }
        if (!file_exists(self::DATA_DIR . 'categories.json')) {
            file_put_contents(self::DATA_DIR . 'categories.json', '[]', LOCK_EX);
        }
    }

    private static function loadLinks()
    {
        $file = self::DATA_DIR . 'links.json';
        if (!file_exists($file)) return [];
        $content = file_get_contents($file);
        $links = json_decode($content, true);
        return is_array($links) ? $links : [];
    }

    private static function saveLinks(array $links)
    {
        $file = self::DATA_DIR . 'links.json';
        $json = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $file);
        self::$linksCache = null;
        self::clearRenderedCache();
    }

    private static function loadCategories()
    {
        $file = self::DATA_DIR . 'categories.json';
        if (!file_exists($file)) return [];
        $content = file_get_contents($file);
        $cats = json_decode($content, true);
        return is_array($cats) ? $cats : [];
    }

    private static function saveCategories(array $cats)
    {
        $file = self::DATA_DIR . 'categories.json';
        $json = json_encode($cats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $file);
        self::$linksCache = null;
        self::clearRenderedCache();
    }

    private static function getFrontLinks()
    {
        if (self::$linksCache !== null) return self::$linksCache;
        $links = self::loadLinks();
        $links = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        self::$linksCache = $links;
        return $links;
    }

    private static function filterLinks($includeHidden, $categoryFilter)
    {
        $links = self::loadLinks();
        if (!$includeHidden) {
            $links = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        }
        if ($categoryFilter === 'uncategorized') {
            $links = array_values(array_filter($links, fn($l) => empty($l['category_id'])));
        } elseif ($categoryFilter === 'dead') {
            $links = array_values(array_filter($links, fn($l) => isset($l['alive']) && $l['alive'] == 0));
        } elseif ($categoryFilter !== 'all') {
            $catId = intval($categoryFilter);
            $links = array_values(array_filter($links, fn($l) => ($l['category_id'] ?? null) == $catId));
        }
        return $links;
    }

    private static function sortLinksArray(&$links, $orderBy)
    {
        switch ($orderBy) {
            case 'created_desc':
                usort($links, fn($a, $b) => $b['created'] <=> $a['created']);
                break;
            case 'created_asc':
                usort($links, fn($a, $b) => $a['created'] <=> $b['created']);
                break;
            case 'title_asc':
                usort($links, fn($a, $b) => strcasecmp($a['title'], $b['title']));
                break;
            case 'title_desc':
                usort($links, fn($a, $b) => strcasecmp($b['title'], $a['title']));
                break;
            case 'random':
                shuffle($links);
                break;
            default: // 手动排序
                usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        }
    }

    private static function clearRenderedCache()
    {
        foreach (glob(self::CACHE_DIR . 'friendlinks_rendered_*.html') as $file) {
            @unlink($file);
        }
    }

    private static function checkAlive($url)
    {
        $timeout = intval(self::getPluginOptions()->timeout ?? 10);
        $url = rtrim($url, '/');
        if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_RETURNTRANSFER => true
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 400);
    }

    private static function batchCheckAlive(array $urls)
    {
        $timeout = intval(self::getPluginOptions()->timeout ?? 10);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $k => $url) {
            $url = rtrim($url, '/');
            if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_RETURNTRANSFER => true
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }

        do {
            curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active);

        $results = [];
        foreach ($handles as $k => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$k] = ($code >= 200 && $code < 400);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }
}