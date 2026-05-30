<?php
/**
 * 友情链接 Light 版 – JSON 存储、仅存活检查、数据分离 (PHP 8.2+)
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

    private static ?array $linksCache = null;

    /* ====================== 激活 / 禁用 ====================== */
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

        return _t('Light 版仅保留存活检测，数据分离为 links.json 与 categories.json');
    }

    public static function deactivate(): void
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
    public static function config(Form $form): void
    {
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'cacheTime', null, '604800',
            _t('渲染缓存时间（秒）'),
            _t('前台页面 HTML 缓存的过期时间，默认 7 天')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'timeout', null, '10',
            _t('请求超时（秒）'),
            _t('存活检测的超时时间')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'defaultIcon', null, '/favicon.png',
            _t('默认图标 URL'),
            _t('当链接未提供图标时显示的默认图标')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'template', null,
            '<div class="friendlink-card">'
            . '<div class="result-header"><div class="favicon"><img src="{icon}" alt="favicon"></div><div><div class="title">{title}</div><div class="url-display"><a href="{url}" target="_blank">{url}</a></div></div></div>'
            . '<div class="description"><div class="label">描述</div>{description}</div>'
            . '<div class="badge-group"><span class="badge badge-category">{category}</span><span class="badge badge-update">{last_update}</span><span class="badge badge-status">{alive}</span></div>'
            . '</div>',
            _t('卡片模板'),
            _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}、{category}')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Textarea(
            'customCss', null, '',
            _t('自定义 CSS')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select(
            'sortOrder',
            [
                'manual'       => _t('手动排序'),
                'created_desc' => _t('添加时间（新→旧）'),
                'created_asc'  => _t('添加时间（旧→新）'),
                'title_asc'    => _t('标题 A→Z'),
                'title_desc'   => _t('标题 Z→A'),
                'random'       => _t('随机')
            ],
            'manual',
            _t('前台排序方式'),
            _t('选择友情链接在前台页面中的显示顺序')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'skipDeadLinks',
            ['0' => _t('不跳过'), '1' => _t('跳过')],
            '0',
            _t('跳过异常网站'),
            _t('前台默认是否隐藏存活异常的链接（[friendlinks dead] 不受影响）')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text(
            'secretKey', null, '',
            _t('Cron 密钥')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'dropTableOnDeactivate',
            ['0' => _t('不删除'), '1' => _t('<span style="color:red">删除</span>')],
            '0',
            _t('禁用时删除数据文件'),
            _t('选择“删除”后，禁用插件会永久删除 links.json 和 categories.json')
        ));
    }

    public static function personalConfig(Form $form): void {}

    /* ====================== 短代码解析 ====================== */
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

    /** 将短代码内部字符串解析为关联数组 */
    private static function parseShortcodeAttrs(string $str): array
    {
        $attrs = [];
        if (preg_match_all('/([a-z_]+)="([^"]*)"/i', $str, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $attrs[$pair[1]] = $pair[2];
            }
        }
        // dead 布尔属性
        if (preg_match('/\bdead\b/', $str)) {
            $attrs['dead'] = true;
        }
        return $attrs;
    }

    /** 将 include_uncategorized 字符串解析为模式整数 */
    private static function resolveUncategorizedMode(string $raw): int
    {
        return match (strtolower($raw)) {
            '0', 'false' => 0,
            '2'          => 2,
            default      => 1,
        };
    }

    /* ====================== 前台输出 ====================== */
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

    /**
     * 核心渲染逻辑
     */
    public static function renderLinks(
        string $containerClass = 'friendlinks-container',
        string $cardClass = '',
        ?int $categoryId = null,
        int $uncategorizedMode = 1,
        bool $deadOnly = false
    ): string {
        $options = self::getPluginOptions();
        $cacheTime    = (int) ($options->cacheTime ?? 604800);
        $template     = $options->template ?: '<div class="friendlink-card">...</div>';
        $customCss    = $options->customCss ?? '';
        $sortOrder    = $options->sortOrder ?? 'manual';
        $defaultIcon  = $options->defaultIcon ?? '';
        $globalSkipDead = ($options->skipDeadLinks ?? '0') == '1';

        $currentSort = $sortOrder;
        $useRenderCache = ($categoryId === null) && ($uncategorizedMode === 1) && !$deadOnly;

        // 尝试读取渲染缓存
        $renderedCacheFile = null;
        if ($useRenderCache) {
            $renderKey = 'friendlinks_rendered_' . md5($containerClass . $cardClass . $template . $customCss . $currentSort . $defaultIcon . ($globalSkipDead ? '1' : '0') . ($deadOnly ? '1' : '0'));
            $renderedCacheFile = self::CACHE_DIR . $renderKey . '.html';
            if (file_exists($renderedCacheFile) && (time() - filemtime($renderedCacheFile)) < $cacheTime) {
                return file_get_contents($renderedCacheFile);
            }
        }

        $links = self::getFrontLinks();
        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';
        }

        // 分类筛选
        if ($categoryId !== null) {
            $links = array_values(array_filter($links, fn($l) => ($l['category_id'] ?? null) == $categoryId));
        } else {
            $links = match ($uncategorizedMode) {
                0 => array_values(array_filter($links, fn($l) => !empty($l['category_id']))),
                2 => array_values(array_filter($links, fn($l) => empty($l['category_id']))),
                default => $links,
            };
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
            usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        } else {
            self::sortLinksArray($links, $currentSort);
        }

        // 分类名称映射
        $catNames = array_column(self::getCategories(), 'name', 'id');

        // 构建卡片
        $output = '<style>' . $customCss . '</style><div class="' . htmlspecialchars($containerClass) . '">';
        foreach ($links as $link) {
            $icon       = $link['icon'] ?: $defaultIcon;
            $lastUpdate = $link['last_update'] ? date('Y-m-d', $link['last_update']) : '';
            $aliveText  = match ($link['alive'] ?? null) {
                1       => '正常',
                0       => '异常',
                default => '未知',
            };
            $catName = (!empty($link['category_id']) && isset($catNames[$link['category_id']]))
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
                    htmlspecialchars($catName)
                ],
                $template
            );
            if ($cardClass !== '') {
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
    public static function getCategories(): array
    {
        $cats = self::loadCategories();
        usort($cats, fn($a, $b) => $a['sort'] <=> $b['sort'] ?: $a['id'] <=> $b['id']);
        return $cats;
    }

    public static function getCategory(int $id): ?array
    {
        foreach (self::loadCategories() as $c) {
            if ($c['id'] == $id) return $c;
        }
        return null;
    }

    public static function addCategory(string $name, int $sort = 0): bool
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

    public static function updateCategory(int $id, string $name, ?int $sort = null): bool
    {
        $cats = self::loadCategories();
        foreach ($cats as $k => $c) {
            if ($c['id'] == $id) {
                $cats[$k]['name'] = $name;
                if ($sort !== null) $cats[$k]['sort'] = $sort;
                break;
            }
        }
        self::saveCategories($cats);
        return true;
    }

    public static function deleteCategory(int $id): bool
    {
        $cats = array_values(array_filter(self::loadCategories(), fn($c) => $c['id'] != $id));
        self::saveCategories($cats);

        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if (($l['category_id'] ?? null) == $id) {
                $links[$k]['category_id'] = null;
            }
        }
        self::saveLinks($links);
        return true;
    }

    public static function getCategoryLinkCounts(): array
    {
        $links = self::loadLinks();
        $cats  = self::getCategories();

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
        foreach (self::loadLinks() as $l) if ($l['id'] == $id) return $l;
        return null;
    }

    public static function addLink(array $data): bool
    {
        $links = self::loadLinks();

        $id = 1;
        foreach ($links as $l) if ($l['id'] >= $id) $id = $l['id'] + 1;

        $sort = (int) ($data['sort'] ?? 0);
        if ($sort <= 0) $sort = self::getMaxSort() + 1;

        $links[] = [
            'id'            => $id,
            'url'           => $data['url'] ?? '',
            'title'         => $data['title'] ?: parse_url($data['url'], PHP_URL_HOST) ?: 'Untitled',
            'description'   => $data['description'] ?? '',
            'icon'          => $data['icon'] ?? '',
            'status'        => (int) ($data['status'] ?? 1),
            'sort'          => $sort,
            'category_id'   => isset($data['category_id']) && $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            'last_update'   => time(),
            'created'       => time(),
            'alive'         => null,
            'alive_checked' => 0
        ];
        self::saveLinks($links);
        return true;
    }

    public static function updateLink(int $id, array $data): bool
    {
        $links = self::loadLinks();
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
                break;
            }
        }
        self::saveLinks($links);
        return true;
    }

    public static function deleteLink(int $id): bool
    {
        $links = self::loadLinks();
        $links = array_values(array_filter($links, fn($l) => $l['id'] != $id));
        self::saveLinks($links);
        return true;
    }

    /* ====================== 存活检测 ====================== */
    public static function checkLinkStatus(int $linkId): bool
    {
        $links = self::loadLinks();
        foreach ($links as $k => $l) {
            if ($l['id'] == $linkId) {
                $links[$k]['alive']         = self::checkAlive($l['url']) ? 1 : 0;
                $links[$k]['alive_checked'] = time();
                self::saveLinks($links);
                return true;
            }
        }
        return false;
    }

    public static function checkAllLinksStatus(): int
    {
        set_time_limit(0);
        $links = self::loadLinks();
        if (empty($links)) return 0;

        $urls = array_column($links, 'url');
        $results = self::batchCheckAlive($urls);
        $updated = 0;
        foreach ($results as $k => $alive) {
            $links[$k]['alive']         = $alive ? 1 : 0;
            $links[$k]['alive_checked'] = time();
            $updated++;
        }
        self::saveLinks($links);
        return $updated;
    }

    public static function deleteDeadLinks(): int
    {
        $links = self::loadLinks();
        $before = count($links);
        $links = array_values(array_filter($links, fn($l) => ($l['alive'] ?? 1) != 0));
        self::saveLinks($links);
        return $before - count($links);
    }

    public static function compactSorts(): void
    {
        $links = self::loadLinks();
        usort($links, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
        $i = 1;
        foreach ($links as $k => $l) $links[$k]['sort'] = $i++;
        self::saveLinks($links);
    }

    /* ====================== 缓存管理 ====================== */
    public static function refreshCache(): void
    {
        self::$linksCache = null;
        self::clearRenderedCache();
    }

    public static function getCacheInfo(): array
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
            $info['size']     = filesize($linksFile) + filesize($catsFile);
            $info['modified'] = max(filemtime($linksFile), filemtime($catsFile));
        }
        return $info;
    }

    /* ====================== 内部工具 ====================== */
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
    }

    private static function loadLinks(): array
    {
        $file = self::DATA_DIR . 'links.json';
        if (!file_exists($file)) return [];
        $links = json_decode(file_get_contents($file), true);
        return is_array($links) ? $links : [];
    }

    private static function saveLinks(array $links): void
    {
        $file = self::DATA_DIR . 'links.json';
        $json = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $file);
        self::$linksCache = null;
        self::clearRenderedCache();
    }

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
        self::$linksCache = null;
        self::clearRenderedCache();
    }

    private static function getFrontLinks(): array
    {
        if (self::$linksCache !== null) return self::$linksCache;
        $links = self::loadLinks();
        self::$linksCache = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        return self::$linksCache;
    }

    private static function filterLinks(bool $includeHidden, string $categoryFilter): array
    {
        $links = self::loadLinks();
        if (!$includeHidden) {
            $links = array_values(array_filter($links, fn($l) => $l['status'] == 1));
        }
        $links = match ($categoryFilter) {
            'uncategorized' => array_values(array_filter($links, fn($l) => empty($l['category_id']))),
            'dead'          => array_values(array_filter($links, fn($l) => isset($l['alive']) && $l['alive'] == 0)),
            'all'           => $links,
            default         => array_values(array_filter($links, fn($l) => ($l['category_id'] ?? null) == (int) $categoryFilter)),
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

    private static function clearRenderedCache(): void
    {
        foreach (glob(self::CACHE_DIR . 'friendlinks_rendered_*.html') as $file) {
            @unlink($file);
        }
    }

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