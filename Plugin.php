<?php
/**
 * 友情链接 Light 版 – JSON 存储、存活检查、卡片级缓存 (PHP 8.2+)
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
    private const CACHE_DIR = __DIR__ . '/cache/';
    private const DATA_DIR  = __DIR__ . '/data/';
    private static ?array $linksCache      = null;
    private static ?array $frontMap        = null;
    private static ?array $fullLinksIndex  = null;
    /* ───────── 激活 / 禁用 ───────── */
    public static function activate(): string
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
        self::rebuildFrontMap();
        return _t('卡片级缓存 + 自定义容器 + JSON 存储');
    }
    public static function deactivate(): void
    {
        $options = self::getPluginOptions();
        if (isset($options->dropTableOnDeactivate) && $options->dropTableOnDeactivate == 1) {
            @unlink(self::DATA_DIR . 'links.json');
            @unlink(self::DATA_DIR . 'categories.json');
            @unlink(self::DATA_DIR . 'front_map.json');
            self::clearAllCardCaches();
        }
        Helper::removeAction('FriendLinksLight-update');
        Helper::removePanel(3, 'FriendLinksLight/panel.php');
    }
    /* ───────── 配置面板 ───────── */
    public static function config(Form $form): void
    {
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'timeout', null, '10', _t('请求超时（秒）'), _t('存活检测的超时时间')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'defaultIcon', null, '/favicon.png', _t('默认图标 URL')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'containerTemplate', null,
            '<div class="{container_class}">{cards}</div>',
            _t('容器模板'),
            _t('外部容器 HTML。占位符：<code>{cards}</code> – 卡片列表，<code>{container_class}</code> – 容器 class（由参数传入，默认 friendlinks-container）')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'template', null,
            '<div class="friendlink-card">'
            . '<div class="result-header"><div class="favicon"><img src="{icon}" alt="favicon"></div><div><div class="title">{title}</div><div class="url-display"><a href="{url}" target="_blank">{url}</a></div></div></div>'
            . '<div class="description"><div class="label">描述</div>{description}</div>'
            . '<div class="badge-group"><span class="badge badge-category">{category}</span><span class="badge badge-update">{last_update}</span><span class="badge badge-status">{alive}</span></div>'
            . '</div>',
            _t('卡片模板'), _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}、{category}')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'customCss', null, '.friendlink-card{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:20px;border:1px solid #e2e8f0;max-width:520px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}.result-header{display:flex;align-items:center;gap:16px;margin-bottom:16px}.favicon{width:48px;height:48px;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;overflow:hidden;flex-shrink:0}.favicon img{max-width:100%;max-height:100%;object-fit:contain}.title{font-size:20px;font-weight:600;color:#0f172a;word-break:break-word}.url-display{font-size:14px;color:#64748b;margin-top:4px;word-break:break-all}.url-display a{color:#2563eb;text-decoration:none;transition:color 0.2s ease}.url-display a:hover{color:#1d4ed8;text-decoration:underline}.description{margin-top:16px;padding-top:16px;border-top:1px dashed #cbd5e1;color:#334155;line-height:1.5;word-break:break-word}.description .label{font-size:13px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px}.badge-group{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:16px;padding-top:16px;border-top:1px dashed #cbd5e1}.badge{display:inline-flex;align-items:center;gap:5px;font-family:"SF Mono","Fira Code","JetBrains Mono","Consolas",monospace;font-size:13px;font-weight:500;padding:6px 12px;border-radius:20px;line-height:1;white-space:nowrap;letter-spacing:0.3px;transition:transform 0.15s ease,box-shadow 0.15s ease;cursor:default}.badge:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,0.08)}.badge-category{background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe}.badge-category::before{content:"📁";font-size:11px;line-height:1}.badge-update{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd}.badge-update::before{content:"📅";font-size:11px;line-height:1}.badge-status{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}.badge-status::before{content:"✅";font-size:11px;line-height:1}.error .badge-status{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.error .badge-status::before{content:"❌"}.warning .badge-status{background:#fffbeb;color:#b45309;border:1px solid #fed7aa}.warning .badge-status::before{content:"⚠️"}.badge .badge-icon{font-size:12px;line-height:1;flex-shrink:0}@media (max-width:400px){.badge-group{gap:8px}.badge{font-size:12px;padding:5px 10px;border-radius:16px}', _t('自定义 CSS')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
            'sortOrder',
            ['manual' => '手动排序', 'created_desc' => '添加时间（新→旧）', 'created_asc' => '添加时间（旧→新）',
             'title_asc' => '标题 A→Z', 'title_desc' => '标题 Z→A', 'random' => '随机'],
            'manual', _t('前台排序方式')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'skipDeadLinks', ['0' => '不跳过', '1' => '跳过'], '0',
            _t('跳过异常网站'), _t('前台默认是否隐藏存活异常的链接（[friendlinks dead] 不受影响）')
        ));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text('secretKey', null, '', _t('Cron 密钥')));
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'dropTableOnDeactivate', ['0' => '不删除', '1' => '<span style="color:red">删除</span>'], '0',
            _t('禁用时删除数据文件')
        ));
    }
    public static function personalConfig(Form $form): void {}
    /* ───────── 短代码解析 ───────── */
    public static function parseShortcode($content, $widget, $lastResult): string
    {
        $content = empty($lastResult) ? $content : $lastResult;
        if (str_contains($content, '[friendlinks')) {
            $pattern = '/\[friendlinks\s*(.*?)\]/i';
            $content = preg_replace_callback($pattern, function ($m) {
                $attrs = self::parseShortcodeAttrs(trim($m[1]));
                return self::renderLinks(
                    containerClass: $attrs['container_class'] ?? 'friendlinks-container',
                    cardClass: $attrs['card_class'] ?? '',
                    categoryId: isset($attrs['category_id']) ? (int) $attrs['category_id'] : null,
                    uncategorizedMode: self::resolveUncategorizedMode($attrs['include_uncategorized'] ?? '1'),
                    deadOnly: $attrs['dead'] ?? false
                );
            }, $content);
        }
        return $content;
    }
    private static function parseShortcodeAttrs(string $str): array
    {
        $attrs = [];
        if (preg_match_all('/([a-z_]+)="([^"]*)"/i', $str, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) $attrs[$pair[1]] = $pair[2];
        }
        if (preg_match('/\bdead\b/', $str)) $attrs['dead'] = true;
        return $attrs;
    }
    private static function resolveUncategorizedMode(string $raw): int
    {
        return match (strtolower($raw)) {
            '0', 'false' => 0,
            '2'          => 2,
            default      => 1,
        };
    }
    /* ───────── 前台模板函数 ───────── */
    public static function output(
        string $containerClass = 'friendlinks-container',
        string $cardClass = '',
        ?int $categoryId = null,
        int $uncategorizedMode = 1
    ): void {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, false);
    }
    public static function outputDead(
        string $containerClass = 'friendlinks-container',
        string $cardClass = '',
        ?int $categoryId = null,
        int $uncategorizedMode = 1
    ): void {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, true);
    }
    /* ───────── 核心渲染（卡片缓存 + 自定义容器） ───────── */
    public static function renderLinks(
        string $containerClass = 'friendlinks-container',
        string $cardClass = '',
        ?int $categoryId = null,
        int $uncategorizedMode = 1,
        bool $deadOnly = false
    ): string {
        $options = self::getPluginOptions();
        $template           = $options->template ?: '<div class="friendlink-card">...</div>';
        $customCss          = $options->customCss ?? '';
        $sortOrder          = $options->sortOrder ?? 'manual';
        $defaultIcon        = $options->defaultIcon ?? '';
        $globalSkipDead     = ($options->skipDeadLinks ?? '0') == '1';
        $containerTemplate  = $options->containerTemplate ?? '<div class="{container_class}">{cards}</div>';
        if (!str_contains($containerTemplate, '{cards}')) {
            $containerTemplate = '<div class="{container_class}">{cards}</div>';
        }
        // 加载映射
        $map = self::loadFrontMap();
        $linksMap = $map['links'] ?? [];
        if (empty($linksMap)) {
            return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';
        }
        // 过滤
        $filteredIds = [];
        foreach ($linksMap as $id => $meta) {
            if ($categoryId !== null && ($meta['category_id'] ?? null) != $categoryId) continue;
            if ($categoryId === null) {
                if ($uncategorizedMode === 0 && empty($meta['category_id'])) continue;
                if ($uncategorizedMode === 2 && !empty($meta['category_id'])) continue;
            }
            if ($deadOnly) {
                if (!isset($meta['alive']) || $meta['alive'] != 0) continue;
            } elseif ($globalSkipDead && ($meta['alive'] ?? null) === 0) {
                continue;
            }
            $filteredIds[] = $id;
        }
        if (empty($filteredIds)) {
            return '<p class="friendlinks-empty">' . _t('该条件下暂无链接') . '</p>';
        }
        // 排序
        $metaList = array_intersect_key($linksMap, array_flip($filteredIds));
        match ($sortOrder) {
            'created_desc' => uasort($metaList, fn($a, $b) => $b['created'] <=> $a['created']),
            'created_asc'  => uasort($metaList, fn($a, $b) => $a['created'] <=> $b['created']),
            'title_asc'    => uasort($metaList, fn($a, $b) => strcasecmp($a['title'], $b['title'])),
            'title_desc'   => uasort($metaList, fn($a, $b) => strcasecmp($b['title'], $a['title'])),
            default        => uasort($metaList, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: ($a['id'] ?? 0) <=> ($b['id'] ?? 0)),
        };
        $orderedIds = array_keys($metaList);
        // 构建卡片 HTML
        $cardsHtml = '';
        $templateHash = md5($template);
        $catNames = array_column(self::getCategories(), 'name', 'id');
        foreach ($orderedIds as $linkId) {
            $cacheFile = self::CACHE_DIR . "card_{$linkId}_{$templateHash}.html";
            if (!file_exists($cacheFile)) {
                $link = self::getFullLinkById($linkId);
                if ($link && $link['status'] == 1) {
                    $card = self::generateCardCache($linkId, $link, $template, $defaultIcon, $catNames);
                } else {
                    continue;
                }
            } else {
                $card = file_get_contents($cacheFile);
            }
            if ($cardClass !== '') {
                $card = str_replace('friendlink-card', 'friendlink-card ' . htmlspecialchars($cardClass), $card);
            }
            $cardsHtml .= $card;
        }
        // 替换容器模板
        $containerHtml = str_replace(
            ['{cards}', '{container_class}'],
            [$cardsHtml, htmlspecialchars($containerClass)],
            $containerTemplate
        );
        $output = '<style>' . $customCss . '</style>' . $containerHtml;
        // 随机排序 JS 打乱
        if ($sortOrder === 'random') {
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
        return $output;
    }

    /* ====================== 分类 CRUD ====================== */
    public static function getCategories(): array
    {
        $cats = self::loadCategories();
        usort($cats, fn($a, $b) => $a['sort'] <=> $b['sort'] ?: $a['id'] <=> $b['id']);
        return $cats;
    }

    public static function addCategory(string $name, int $sort = 0): bool
    {
        $cats = self::loadCategories();
        $id = 1;
        foreach ($cats as $c) if ($c['id'] >= $id) $id = $c['id'] + 1;
        if ($sort <= 0) {
            $max = 0;
            foreach ($cats as $c) if ($c['sort'] > $max) $max = $c['sort'];
            $sort = $max + 1;
        }
        $cats[] = ['id' => $id, 'name' => $name, 'sort' => $sort, 'created' => time()];
        self::saveCategories($cats);
        // 新增分类不影响现有卡片缓存
        return true;
    }

    public static function updateCategory(int $id, string $name, ?int $sort = null): bool
    {
        $oldCat = self::getCategory($id);
        if (!$oldCat) return false;

        $cats = self::loadCategories();
        foreach ($cats as $k => $c) {
            if ($c['id'] == $id) {
                $cats[$k]['name'] = $name;
                if ($sort !== null) $cats[$k]['sort'] = $sort;
                break;
            }
        }
        self::saveCategories($cats);

        // 如果分类名称改变，则重建该分类下所有链接的卡片缓存
        if ($oldCat['name'] !== $name) {
            self::regenerateCardsByCategory($id);
        }
        // 更新映射（排序可能变化）
        self::rebuildFrontMap();
        return true;
    }

    public static function deleteCategory(int $id): bool
    {
        // 先获取受影响链接的 ID 列表（用于重建卡片缓存）
        $affectedLinks = self::getLinkIdsByCategory($id);

        $cats = array_values(array_filter(self::loadCategories(), fn($c) => $c['id'] != $id));
        self::saveCategories($cats);

        // 将链接分类置空（未分类）
        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if (($l['category_id'] ?? null) == $id) $links[$k]['category_id'] = null;
        }
        self::saveLinks($links);

        // 重建受影响链接的卡片缓存（因为分类名变为“未分类”）
        self::regenerateCardsByIds($affectedLinks);
        self::rebuildFrontMap();
        return true;
    }

    public static function getCategoryLinkCounts(): array
    {
        $links = self::loadLinks();
        $cats  = self::getCategories();
        $counts = ['uncategorized' => 0];
        foreach ($cats as $c) $counts[$c['id']] = 0;
        foreach ($links as $l) {
            if (empty($l['category_id'])) $counts['uncategorized']++;
            elseif (isset($counts[$l['category_id']])) $counts[$l['category_id']]++;
        }
        return $counts;
    }

    /* ====================== 链接 CRUD ====================== */
    public static function getMaxSort(): int
    {
        $links = self::loadLinks();
        $max = 0;
        foreach ($links as $l) if ($l['sort'] > $max) $max = $l['sort'];
        return $max;
    }

    public static function getLinksCount(bool $includeHidden = true, string $categoryFilter = 'all'): int
    {
        return count(self::filterLinks($includeHidden, $categoryFilter));
    }

    public static function getLinksPaginated(bool $includeHidden = true, string $orderBy = 'sort', string $categoryFilter = 'all', int $limit = 10, int $offset = 0): array
    {
        $links = self::filterLinks($includeHidden, $categoryFilter);
        self::sortLinksArray($links, $orderBy);
        return array_slice($links, $offset, $limit);
    }

    public static function getAllLinks(bool $includeHidden = true, string $orderBy = 'sort', string $categoryFilter = 'all'): array
    {
        $links = self::filterLinks($includeHidden, $categoryFilter);
        self::sortLinksArray($links, $orderBy);
        return $links;
    }

    public static function getLink(int $id): ?array
    {
        return self::getFullLinkById($id);
    }

    public static function addLink(array $data): bool
    {
        $links = self::loadLinks();
        $id = 1;
        foreach ($links as $l) if ($l['id'] >= $id) $id = $l['id'] + 1;

        $sort = (int) ($data['sort'] ?? 0);
        if ($sort <= 0) $sort = self::getMaxSort() + 1;

        $link = [
            'id' => $id, 'url' => $data['url'] ?? '', 'title' => $data['title'] ?: parse_url($data['url'], PHP_URL_HOST) ?: 'Untitled',
            'description' => $data['description'] ?? '', 'icon' => $data['icon'] ?? '',
            'status' => (int) ($data['status'] ?? 1), 'sort' => $sort,
            'category_id' => isset($data['category_id']) && $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            'last_update' => time(), 'created' => time(), 'alive' => null, 'alive_checked' => 0
        ];
        $links[] = $link;
        self::saveLinks($links);

        // 生成卡片缓存
        if ($link['status'] == 1) {
            $template = self::getPluginOptions()->template ?: '<div class="friendlink-card">...</div>';
            $defaultIcon = self::getPluginOptions()->defaultIcon ?? '';
            $catNames = array_column(self::getCategories(), 'name', 'id');
            self::generateCardCache($id, $link, $template, $defaultIcon, $catNames);
        }
        self::rebuildFrontMap();
        return true;
    }

    public static function updateLink(int $id, array $data): bool
    {
        $links = self::loadLinks();
        $updated = false;
        foreach ($links as $k => $l) {
            if ($l['id'] == $id) {
                $links[$k]['url']         = $data['url'] ?? $l['url'];
                $links[$k]['title']       = $data['title'] ?: (parse_url($data['url'] ?? '', PHP_URL_HOST) ?: $l['title']);
                $links[$k]['description'] = $data['description'] ?? '';
                $links[$k]['icon']        = $data['icon'] ?? '';
                $links[$k]['status']      = (int) ($data['status'] ?? $l['status']);
                $links[$k]['sort']        = (int) ($data['sort'] ?? $l['sort']);
                $links[$k]['category_id'] = isset($data['category_id']) && $data['category_id'] !== '' ? (int) $data['category_id'] : null;
                $links[$k]['last_update'] = time();
                $updated = true;
                break;
            }
        }
        if (!$updated) return false;
        self::saveLinks($links);

        // 重建卡片缓存（可能内容改变或分类变更）
        self::deleteCardCache($id);
        $newLink = self::getFullLinkById($id);
        if ($newLink && $newLink['status'] == 1) {
            $template = self::getPluginOptions()->template ?: '<div class="friendlink-card">...</div>';
            $defaultIcon = self::getPluginOptions()->defaultIcon ?? '';
            $catNames = array_column(self::getCategories(), 'name', 'id');
            self::generateCardCache($id, $newLink, $template, $defaultIcon, $catNames);
        }
        self::rebuildFrontMap();
        return true;
    }

    public static function deleteLink(int $id): bool
    {
        $links = array_values(array_filter(self::loadLinks(), fn($l) => $l['id'] != $id));
        self::saveLinks($links);
        self::deleteCardCache($id);
        self::rebuildFrontMap();
        return true;
    }

    /* ====================== 存活检测 ====================== */
    public static function checkLinkStatus(int $linkId): bool
    {
        $links = self::loadLinks();
        $found = false;
        foreach ($links as $k => $l) {
            if ($l['id'] == $linkId) {
                $alive = self::checkAlive($l['url']);
                $links[$k]['alive'] = $alive ? 1 : 0;
                $links[$k]['alive_checked'] = time();
                $found = true;
                break;
            }
        }
        if (!$found) return false;
        self::saveLinks($links);

        // 更新卡片缓存与映射
        self::deleteCardCache($linkId);
        $link = self::getFullLinkById($linkId);
        if ($link && $link['status'] == 1) {
            $template = self::getPluginOptions()->template ?: '<div class="friendlink-card">...</div>';
            $defaultIcon = self::getPluginOptions()->defaultIcon ?? '';
            $catNames = array_column(self::getCategories(), 'name', 'id');
            self::generateCardCache($linkId, $link, $template, $defaultIcon, $catNames);
        }
        self::rebuildFrontMap();
        return true;
    }

    public static function checkAllLinksStatus(): int
    {
        set_time_limit(0);
        $links = self::loadLinks();
        if (empty($links)) return 0;

        $urls = array_column($links, 'url');
        $results = self::batchCheckAlive($urls);
        $updated = 0;
        $changedIds = [];
        foreach ($results as $k => $alive) {
            $newAlive = $alive ? 1 : 0;
            if (($links[$k]['alive'] ?? null) !== $newAlive) {
                $changedIds[] = $links[$k]['id'];
            }
            $links[$k]['alive'] = $newAlive;
            $links[$k]['alive_checked'] = time();
            $updated++;
        }
        self::saveLinks($links);

        // 只更新状态变化的卡片缓存
        $template = self::getPluginOptions()->template ?: '<div class="friendlink-card">...</div>';
        $defaultIcon = self::getPluginOptions()->defaultIcon ?? '';
        $catNames = array_column(self::getCategories(), 'name', 'id');
        foreach ($changedIds as $id) {
            self::deleteCardCache($id);
            $link = self::getFullLinkById($id);
            if ($link && $link['status'] == 1) {
                self::generateCardCache($id, $link, $template, $defaultIcon, $catNames);
            }
        }
        self::rebuildFrontMap();
        return $updated;
    }

    public static function deleteDeadLinks(): int
    {
        $links = self::loadLinks();
        $before = count($links);
        $deadIds = [];
        $links = array_values(array_filter($links, function($l) use (&$deadIds) {
            if (($l['alive'] ?? 1) == 0) { $deadIds[] = $l['id']; return false; }
            return true;
        }));
        self::saveLinks($links);
        foreach ($deadIds as $id) self::deleteCardCache($id);
        self::rebuildFrontMap();
        return $before - count($links);
    }

    public static function compactSorts(): void
    {
        $links = self::loadLinks();
        usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        $i = 1;
        foreach ($links as $k => $l) $links[$k]['sort'] = $i++;
        self::saveLinks($links);
        self::rebuildFrontMap();
    }

    /* ====================== 缓存管理 ====================== */
    public static function refreshCache(): void
    {
        self::$linksCache = null;
        self::$fullLinksIndex = null;
        self::clearAllCardCaches();
        self::rebuildFrontMap();
    }

    public static function getCacheInfo(): array
    {
        $linksFile = self::DATA_DIR . 'links.json';
        $catsFile  = self::DATA_DIR . 'categories.json';
        $exists = file_exists($linksFile) && file_exists($catsFile);
        $info = ['exists' => $exists, 'size' => 0, 'modified' => 0, 'ttl' => 0];
        if ($exists) {
            $info['size'] = filesize($linksFile) + filesize($catsFile);
            $info['modified'] = max(filemtime($linksFile), filemtime($catsFile));
        }
        return $info;
    }

    /* ====================== 内部工具（映射与卡片缓存） ====================== */
    private static function getPluginOptions(): object
    {
        return Helper::options()->plugin('FriendLinksLight');
    }

    private static function initDataFiles(): void
    {
        if (!file_exists(self::DATA_DIR . 'links.json')) {
            file_put_contents(self::DATA_DIR . 'links.json', '[]', LOCK_EX);
        }
        if (!file_exists(self::DATA_DIR . 'categories.json')) {
            file_put_contents(self::DATA_DIR . 'categories.json', '[]', LOCK_EX);
        }
        if (!file_exists(self::DATA_DIR . 'front_map.json')) {
            file_put_contents(self::DATA_DIR . 'front_map.json', json_encode(['links' => [], 'generated' => 0]), LOCK_EX);
        }
    }

    // ---------- 链接完整数据加载 ----------
    private static function loadLinks(): array
    {
        $file = self::DATA_DIR . 'links.json';
        if (!file_exists($file)) return [];
        $content = file_get_contents($file);
        $links = json_decode($content, true);
        return is_array($links) ? $links : [];
    }

    private static function getFullLinksIndex(): array
    {
        if (self::$fullLinksIndex === null) {
            $links = self::loadLinks();
            $index = [];
            foreach ($links as $l) $index[$l['id']] = $l;
            self::$fullLinksIndex = $index;
        }
        return self::$fullLinksIndex;
    }

    private static function getFullLinkById(int $id): ?array
    {
        $index = self::getFullLinksIndex();
        return $index[$id] ?? null;
    }

    private static function saveLinks(array $links): void
    {
        $file = self::DATA_DIR . 'links.json';
        $json = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $file);
        self::$linksCache = null;
        self::$fullLinksIndex = null;
        // 注意：不在此处自动重建映射或清除卡片缓存，由调用方负责
    }

    // ---------- 分类 ----------
    private static function loadCategories(): array
    {
        $file = self::DATA_DIR . 'categories.json';
        if (!file_exists($file)) return [];
        $cats = json_decode(file_get_contents($file), true);
        return is_array($cats) ? $cats : [];
    }

    private static function saveCategories(array $cats): void
    {
        $file = self::DATA_DIR . 'categories.json';
        $json = json_encode($cats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $file);
        self::$linksCache = null; // 可能分类名称改变，前台缓存失效
    }

    // ---------- 前台映射 ----------
    private static function loadFrontMap(): array
    {
        if (self::$frontMap === null) {
            $file = self::DATA_DIR . 'front_map.json';
            if (!file_exists($file)) return ['links' => [], 'generated' => 0];
            $data = json_decode(file_get_contents($file), true);
            self::$frontMap = is_array($data) ? $data : ['links' => [], 'generated' => 0];
        }
        return self::$frontMap;
    }

    private static function saveFrontMap(array $map): void
    {
        $file = self::DATA_DIR . 'front_map.json';
        $map['generated'] = time();
        file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        self::$frontMap = $map;
    }

    private static function rebuildFrontMap(): void
    {
        $links = self::getFrontLinks(); // status=1 的链接
        $map = ['links' => []];
        foreach ($links as $l) {
            $map['links'][$l['id']] = [
                'category_id' => $l['category_id'] ?? null,
                'alive'       => $l['alive'] ?? null,
                'sort'        => $l['sort'] ?? 0,
                'created'     => $l['created'] ?? 0,
                'title'       => $l['title'] ?? ''
            ];
        }
        self::saveFrontMap($map);
    }

    private static function getFrontLinks(): array
    {
        if (self::$linksCache !== null) return self::$linksCache;
        $links = self::loadLinks();
        self::$linksCache = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        return self::$linksCache;
    }

    // ---------- 卡片缓存相关 ----------
    private static function generateCardCache(int $linkId, array $link, string $template, string $defaultIcon, array $catNames): string
    {
        $icon = $link['icon'] ?: $defaultIcon;
        $lastUpdate = $link['last_update'] ? date('Y-m-d', $link['last_update']) : '';
        $aliveText = match ($link['alive'] ?? null) {
            1       => '正常',
            0       => '异常',
            default => '未知',
        };
        $categoryName = (!empty($link['category_id']) && isset($catNames[$link['category_id']]))
            ? $catNames[$link['category_id']]
            : '未分类';

        $card = str_replace(
            ['{url}', '{title}', '{description}', '{icon}', '{last_update}', '{alive}', '{category}'],
            [
                htmlspecialchars($link['url']),
                htmlspecialchars($link['title']),
                htmlspecialchars($link['description'] ?? ''),
                htmlspecialchars($icon),
                htmlspecialchars($lastUpdate),
                htmlspecialchars($aliveText),
                htmlspecialchars($categoryName)
            ],
            $template
        );

        $templateHash = md5($template);
        $cacheFile = self::CACHE_DIR . "card_{$linkId}_{$templateHash}.html";
        file_put_contents($cacheFile, $card, LOCK_EX);
        return $card;
    }

    private static function deleteCardCache(int $linkId): void
    {
        foreach (glob(self::CACHE_DIR . "card_{$linkId}_*.html") as $file) @unlink($file);
    }

    private static function clearAllCardCaches(): void
    {
        foreach (glob(self::CACHE_DIR . "card_*.html") as $file) @unlink($file);
    }

    // 根据分类 ID 重建该分类下所有链接的卡片缓存
    private static function regenerateCardsByCategory(int $categoryId): void
    {
        $map = self::loadFrontMap();
        $ids = [];
        foreach (($map['links'] ?? []) as $id => $meta) {
            if (($meta['category_id'] ?? null) == $categoryId) $ids[] = $id;
        }
        self::regenerateCardsByIds($ids);
    }

    private static function regenerateCardsByIds(array $ids): void
    {
        if (empty($ids)) return;
        $template = self::getPluginOptions()->template ?: '<div class="friendlink-card">...</div>';
        $defaultIcon = self::getPluginOptions()->defaultIcon ?? '';
        $catNames = array_column(self::getCategories(), 'name', 'id');
        foreach ($ids as $id) {
            self::deleteCardCache($id);
            $link = self::getFullLinkById($id);
            if ($link && $link['status'] == 1) {
                self::generateCardCache($id, $link, $template, $defaultIcon, $catNames);
            }
        }
    }

    // 工具：获取某分类下的所有链接 ID（使用映射）
    private static function getLinkIdsByCategory(int $categoryId): array
    {
        $map = self::loadFrontMap();
        $ids = [];
        foreach (($map['links'] ?? []) as $id => $meta) {
            if (($meta['category_id'] ?? null) == $categoryId) $ids[] = $id;
        }
        return $ids;
    }

    private static function filterLinks(bool $includeHidden, string $categoryFilter): array
    {
        $links = self::loadLinks();
        if (!$includeHidden) $links = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        $links = match ($categoryFilter) {
            'uncategorized' => array_values(array_filter($links, fn($l) => empty($l['category_id']))),
            'dead' => array_values(array_filter($links, fn($l) => isset($l['alive']) && $l['alive'] == 0)),
            'all' => $links,
            default => array_values(array_filter($links, fn($l) => ($l['category_id'] ?? null) == (int) $categoryFilter)),
        };
        return $links;
    }

    private static function sortLinksArray(array &$links, string $orderBy): void
    {
        match ($orderBy) {
            'created_desc' => usort($links, fn($a, $b) => $b['created'] <=> $a['created']),
            'created_asc'  => usort($links, fn($a, $b) => $a['created'] <=> $b['created']),
            'title_asc'    => usort($links, fn($a, $b) => strcasecmp($a['title'], $b['title'])),
            'title_desc'   => usort($links, fn($a, $b) => strcasecmp($b['title'], $a['title'])),
            'random'       => shuffle($links),
            default        => usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']),
        };
    }

    // ---------- 存活检测 ----------
    private static function checkAlive(string $url): bool
    {
        $timeout = (int) (self::getPluginOptions()->timeout ?? 10);
        $url = rtrim($url, '/');
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            $url = 'https://' . $url;
        }
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
        return ($code >= 200 && $code < 400);
    }

    private static function batchCheckAlive(array $urls): array
    {
        $timeout = (int) (self::getPluginOptions()->timeout ?? 10);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $k => $url) {
            $url = rtrim($url, '/');
            if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
                $url = 'https://' . $url;
            }
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
        }
        return $results;
    }
}