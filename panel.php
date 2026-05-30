<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$user = \Typecho\Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) die(_t('权限不足'));
/* ──────── POST 处理 ──────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'add_category':
            FriendLinksLight_Plugin::addCategory($_POST['name'] ?? '', intval($_POST['sort'] ?? 0));
            $message = _t('分类添加成功'); break;
        case 'edit_category':
            FriendLinksLight_Plugin::updateCategory(intval($_POST['id']), $_POST['name'] ?? '', isset($_POST['sort']) ? intval($_POST['sort']) : null);
            $message = _t('分类更新成功'); break;
        case 'delete_category':
            FriendLinksLight_Plugin::deleteCategory(intval($_POST['id']));
            $message = _t('分类删除成功'); break;
        case 'add':
            FriendLinksLight_Plugin::addLink($_POST);
            $message = _t('添加成功'); break;
        case 'edit':
            FriendLinksLight_Plugin::updateLink(intval($_POST['id']), $_POST);
            $message = _t('更新成功'); break;
        case 'delete':
            FriendLinksLight_Plugin::deleteLink(intval($_POST['id']));
            $message = _t('删除成功'); break;
        case 'check_status':
            FriendLinksLight_Plugin::checkLinkStatus(intval($_POST['id']));
            $message = _t('状态检查完成'); break;
        case 'check_all':
            $cnt = FriendLinksLight_Plugin::checkAllLinksStatus();
            $message = sprintf(_t('已检查 %d 个链接的状态'), $cnt); break;
        case 'clear_cache':
            FriendLinksLight_Plugin::refreshCache();
            $message = _t('缓存已刷新'); break;
        case 'compact_sorts':
            FriendLinksLight_Plugin::compactSorts();
            $message = _t('序号已重新排列'); break;
        case 'delete_dead':
            $del = FriendLinksLight_Plugin::deleteDeadLinks();
            $message = sprintf(_t('删除了 %d 个异常链接'), $del); break;
    }
}
/* ──────── 页面数据──────── */
$allowedSort = ['sort','created_desc','created_asc','title_asc','title_desc','random'];
$currentSort  = $_GET['sortby'] ?? ($_COOKIE['friendlinks_sort'] ?? 'sort');
$currentCategory = $_GET['category_id'] ?? 'all';
$perPageOptions = [10, 20, 50];
$perPage = 10;
if (isset($_GET['per_page'])) {
    $val = (int)$_GET['per_page'];
    if (in_array($val, $perPageOptions, true)) {
        $perPage = $val;
    }
}
$perPage = max(1, $perPage);
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$totalCount = FriendLinksLight_Plugin::getLinksCount(true, $currentCategory);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$offset = ($currentPage - 1) * $perPage;
$links = FriendLinksLight_Plugin::getLinksPaginated(true, $currentSort, $currentCategory, $perPage, $offset);
$cacheInfo = FriendLinksLight_Plugin::getCacheInfo();
$categories = FriendLinksLight_Plugin::getCategories();
$categoryCounts = FriendLinksLight_Plugin::getCategoryLinkCounts();
$categoryMap = array_column($categories, 'name', 'id');
$nextSort = FriendLinksLight_Plugin::getMaxSort() + 1;
$maxCatSort = max(array_column($categories, 'sort') ?: [0]) + 1;
$nextCatSort = $maxCatSort;
$options = \Utils\Helper::options();
$siteUrl = $options->siteUrl;
$secretKey = ($options->plugin('FriendLinksLight')->secretKey ?? '');
$cronUrl = rtrim($siteUrl, '/') . '/friendlinkslight/cron' . ($secretKey ? '?key=' . $secretKey : '');
/* 分页 HTML */
$urlParams = $_GET;
unset($urlParams['page']);
$paginationHtml = '<div class="friendlinks-pagination" style="margin-top:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
$paginationHtml .= '<select id="perPageSelect" style="padding:5px;">';
foreach ($perPageOptions as $opt) {
    $sel = $opt == $perPage ? ' selected' : '';
    $paginationHtml .= "<option value=\"$opt\"$sel>$opt 条/页</option>";
}
$paginationHtml .= '</select>';
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i == $currentPage) {
        $paginationHtml .= '<span class="btn btn-sm" style="background:#0073aa;color:#fff;">'.$i.'</span>';
    } else {
        $params = array_merge($urlParams, ['page' => $i]);
        $paginationHtml .= '<a class="btn btn-sm" href="?'.http_build_query($params).'">'.$i.'</a>';
    }
}
$paginationHtml .= '<span style="margin-left:10px;">共 '.$totalCount.' 条</span></div>';
include 'header.php';
include 'menu.php';
?>
<style>
    .friendlinks-panel { padding: 20px; }
    .table-container { overflow-x: auto; max-width: 100%; }
    .friendlinks-table { min-width: 900px; width: 100%; border-collapse: collapse; }
    .friendlinks-table th, .friendlinks-table td { padding: 8px; border-bottom: 1px solid #eee; text-align: left; }
    .status-badge { padding: 2px 8px; border-radius: 12px; font-size: 12px; display: inline-block; }
    .status-show { background:#d4edda; color:#155724; }
    .status-hide { background:#f8d7da; color:#721c24; }
    .category-badge { background:#e8f0fe; color:#1967d2; padding:2px 8px; border-radius:12px; font-size:12px; }
    .btn { height:auto;display:inline-block; padding:6px 12px; border:1px solid #ddd; border-radius:4px; text-decoration:none; color:#333; background:#fff; cursor:pointer; }
    .btn-primary { background:#0073aa; border-color:#0073aa; color:#fff; }
    .btn-danger { background:#dc3545; border-color:#dc3545; color:#fff; }
    .btn-warning { background:#ffc107; border-color:#ffc107; color:#333; }
    .btn-sm { padding:2px 8px; font-size:12px; }
    .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; }
    .modal-header {display: flex;justify-content: space-between;align-items: center;margin-bottom: 20px;}
    .modal-close {font-size: 24px;cursor: pointer;}
    .modal-content { background:#fff; margin:50px auto; padding:20px; width:500px; max-height:80vh; overflow-y:auto; border-radius:8px; }
    .form-group { margin-bottom:12px; }
    .form-group label { display:block; font-weight:bold; margin-bottom:4px; }
    .form-group input, .form-group select, .form-group textarea { width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; }
    .cache-info, .category-section, .cron-info { background:#f9f9f9; padding:15px; border-radius:6px; margin:20px 0; }
    .category-grid { display:flex; gap:12px; flex-wrap:wrap; }
    .category-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:12px 16px; min-width:170px; }
    .toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:20px 0; }
    .message.notice { transition: opacity 0.5s; }
</style>

<div class="friendlinks-panel">
    <h2><?php _e('友情链接管理'); ?></h2>
    <?php if (isset($message)): ?><div class="message notice"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <!-- 分类管理 -->
    <div class="category-section">
        <h3><?php _e('分类管理'); ?></h3>
        <div class="category-grid">
            <div class="category-card" style="border-style:dashed;">
                <div class="cat-name">📁 <?php _e('未分类'); ?></div>
                <div class="cat-meta"><?php echo sprintf(_t('%d 条'), $categoryCounts['uncategorized'] ?? 0); ?></div>
            </div>
            <?php foreach ($categories as $cat): ?>
                <div class="category-card">
                    <div class="cat-name">📁 <?php echo htmlspecialchars($cat['name']); ?></div>
                    <div class="cat-meta">ID: <?php echo $cat['id']; ?> | <?php echo sprintf(_t('%d 条'), $categoryCounts[$cat['id']] ?? 0); ?></div>
                    <div class="cat-actions">
                        <button class="btn btn-sm" onclick='editCategory(<?php echo json_encode($cat); ?>)'>✏️</button>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                            <button class="btn btn-sm btn-danger" onclick="return confirm('确定删除该分类？')">🗑️</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="category-card" style="border-style:dashed; display:flex; align-items:center; justify-content:center;">
                <button class="btn btn-primary btn-sm" onclick="openCategoryModal()">➕ <?php _e('添加分类'); ?></button>
            </div>
        </div>
    </div>

    <!-- 缓存状态 -->
    <div class="cache-info">
        <h3><?php _e('数据状态'); ?></h3>
        <p><?php _e('数据文件:'); ?> <?php echo $cacheInfo['exists'] ? _t('存在') : _t('不存在'); ?></p>
        <?php if ($cacheInfo['exists']): ?>
            <p><?php _e('大小:'); ?> <?php echo round($cacheInfo['size']/1024,2); ?> KB</p>
            <p><?php _e('最后修改:'); ?> <?php echo date('Y-m-d H:i:s', $cacheInfo['modified']); ?></p>
        <?php endif; ?>
    </div>

    <!-- 工具栏 -->
    <div class="toolbar">
        <button class="btn btn-primary" onclick="openModal()">➕ <?php _e('添加链接'); ?></button>
        <form method="post" class="ajax-form" data-confirm="确定要检查所有链接的状态吗？这可能耗时较长。">
            <input type="hidden" name="action" value="check_all">
            <button class="btn btn-warning">🔄 <?php _e('检查所有状态'); ?></button>
        </form>
        <form method="post" class="ajax-form">
            <input type="hidden" name="action" value="clear_cache">
            <button class="btn">🗑️ <?php _e('刷新缓存'); ?></button>
        </form>
        <form method="post" class="ajax-form">
            <input type="hidden" name="action" value="compact_sorts">
            <button class="btn">🔢 <?php _e('重整序号'); ?></button>
        </form>
        <form method="post" class="ajax-form" data-confirm="确定要删除所有异常链接吗？此操作不可恢复。">
            <input type="hidden" name="action" value="delete_dead">
            <button class="btn btn-danger">🗑️ <?php _e('删除异常链接'); ?></button>
        </form>
        <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
            <label>分类：</label>
            <select id="categoryFilterSelect" style="padding:4px;">
                <option value="all" <?php if($currentCategory=='all') echo 'selected'; ?>>全部</option>
                <option value="uncategorized" <?php if($currentCategory=='uncategorized') echo 'selected'; ?>>未分类</option>
                <option value="dead" <?php if($currentCategory=='dead') echo 'selected'; ?>>异常</option>
                <?php foreach($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php if($currentCategory==$cat['id']) echo 'selected'; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>排序：</label>
            <select id="sortSelect" style="padding:4px;">
                <option value="sort" <?php if($currentSort=='sort') echo 'selected'; ?>>手动</option>
                <option value="created_desc" <?php if($currentSort=='created_desc') echo 'selected'; ?>>时间 ↓</option>
                <option value="created_asc" <?php if($currentSort=='created_asc') echo 'selected'; ?>>时间 ↑</option>
                <option value="title_asc" <?php if($currentSort=='title_asc') echo 'selected'; ?>>A-Z</option>
                <option value="title_desc" <?php if($currentSort=='title_desc') echo 'selected'; ?>>Z-A</option>
                <option value="random" <?php if($currentSort=='random') echo 'selected'; ?>>随机</option>
            </select>
            <button id="refreshSortBtn" class="btn btn-sm">🔄</button>
        </div>
    </div>

    <!-- 链接表格 -->
    <div class="table-container">
        <table class="friendlinks-table">
            <thead><tr>
                <th>ID</th><th>存活</th><th>分类</th><th>标题</th><th>描述</th><th>URL</th><th>图标</th><th>状态</th><th>排序</th><th>最后更新</th><th>操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($links as $link): ?>
                <tr>
                    <td><?php echo $link['id']; ?></td>
                    <td><?php
                        if (($link['alive'] ?? null) === 1) echo '<span class="status-badge status-show">正常</span>';
                        elseif (($link['alive'] ?? null) === 0) echo '<span class="status-badge status-hide">异常</span>';
                        else echo '<span class="status-badge" style="background:#eee;color:#666;">未知</span>';
                    ?></td>
                    <td><?php if (!empty($link['category_id']) && isset($categoryMap[$link['category_id']])): ?>
                        <span class="category-badge"><?php echo htmlspecialchars($categoryMap[$link['category_id']]); ?></span>
                    <?php else: ?><span style="color:#999;">未分类</span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($link['title']); ?></td>
                    <td><?php echo htmlspecialchars(mb_substr($link['description'] ?? '', 0, 30)); ?></td>
                    <td><a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank"><?php echo htmlspecialchars(substr($link['url'],0,30)); ?></a></td>
                    <td><?php if($link['icon']): ?><img src="<?php echo htmlspecialchars($link['icon']); ?>" width="20"><?php else: ?>-<?php endif; ?></td>
                    <td><span class="status-badge <?php echo $link['status'] ? 'status-show' : 'status-hide'; ?>"><?php echo $link['status'] ? '显示' : '隐藏'; ?></span></td>
                    <td><?php echo $link['sort']; ?></td>
                    <td><?php echo $link['last_update'] ? date('Y-m-d', $link['last_update']) : '-'; ?></td>
                    <td class="actions">
                        <button class="btn btn-sm" onclick='editLink(<?php echo json_encode($link); ?>)'>编辑</button>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="check_status">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <button class="btn btn-sm btn-warning">检查</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <button class="btn btn-sm btn-danger" onclick="return confirm('确定删除？')">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($links)): ?><tr><td colspan="11" style="text-align:center;">暂无链接</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php echo $paginationHtml; ?>
    </div>

    <div class="cron-info">
        <h3>📌 使用说明 (完整)</h3>
        <style>
            .usage-dl dt { font-weight:bold; margin-top:10px; }
            .usage-code { background:#f4f4f4; padding:2px 6px; border-radius:4px; font-family:monospace; }
            .usage-pre { background:#2d2d2d; color:#f8f8f2; padding:10px; border-radius:6px; overflow-x:auto; }
        </style>
    
        <h4>1. 短代码</h4>
        <p>在文章/页面中使用 <code>[friendlinks ...]</code> 输出友情链接列表。支持以下参数（可任意顺序）：</p>
        <table class="friendlinks-table" style="margin-top:8px;">
            <thead><tr>
                <th style="width:160px;">参数</th><th>说明</th><th>示例</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code>dead</code></td>
                    <td><strong>无值属性</strong>，加上后只输出存活状态为“异常”的链接（忽略全局跳过设置）</td>
                    <td><code>[friendlinks dead]</code></td>
                </tr>
                <tr>
                    <td><code>container_class="..."</code></td>
                    <td>外层容器的自定义类名，默认 <code>friendlinks-container</code></td>
                    <td><code>container_class="my-links"</code></td>
                </tr>
                <tr>
                    <td><code>card_class="..."</code></td>
                    <td>追加到每张卡片 <code>.friendlink-card</code> 上的自定义类，用于微调样式</td>
                    <td><code>card_class="compact-card"</code></td>
                </tr>
                <tr>
                    <td><code>category_id="数字"</code></td>
                    <td>只显示指定分类ID的链接（管理面板可见分类ID）</td>
                    <td><code>category_id="1"</code></td>
                </tr>
                <tr>
                    <td><code>include_uncategorized="1/0/2"</code></td>
                    <td>未分类链接包含模式：<code>1</code>=全部（默认），<code>0</code>=仅已分类，<code>2</code>=仅未分类。当指定 <code>category_id</code> 时此参数被忽略</td>
                    <td><code>include_uncategorized="0"</code></td>
                </tr>
            </tbody>
        </table>
    
        <h4>短代码示例</h4>
        <pre class="usage-pre">    &lt;!-- 默认输出所有可见链接 --&gt;
    [friendlinks]
    
    &lt;!-- 输出分类ID为2的链接，且追加卡片类 --&gt;
    [friendlinks category_id="2" card_class="my-card"]
    
    &lt;!-- 仅输出“异常”链接，且自定义容器类 --&gt;
    [friendlinks dead container_class="dead-links"]
    
    &lt;!-- 排除未分类链接，只显示已归属分类的链接 --&gt;
    [friendlinks include_uncategorized="0"]
    
    &lt;!-- 参数顺序随意，效果相同 --&gt;
    [friendlinks card_class="wide" dead container_class="alert-links"]
    </pre>
    
        <h4>2. 模板函数调用</h4>
        <p>在主题模板中使用以下静态方法输出链接：</p>
        <table class="friendlinks-table" style="margin-top:8px;">
            <tbody><tr><td style="width:250px;"><code>FriendLinksLight_Plugin::output()</code></td><td>输出所有可见链接（参数：容器类, 卡片类, 分类ID, 未分类模式）</td></tr>
            <tr><td><code>FriendLinksLight_Plugin::outputDead()</code></td><td>仅输出异常链接（参数与 <code>output</code> 相同）</td></tr>
        </tbody></table>
        <p>函数签名：</p>
        <pre class="usage-pre">    public static function output(
        $containerClass = 'friendlinks-container',
        $cardClass = '',
        $categoryId = null,       // null=所有分类，数字=指定分类ID
        $uncategorizedMode = 1    // 0排除未分类，2仅未分类，1全部
    );
    // outputDead() 参数相同，但强制 deadOnly=true
    </pre>
    
        <h5>模板调用示例</h5>
        <pre class="usage-pre">    &lt;?php
    // 默认方式
    FriendLinksLight_Plugin::output();
    
    // 自定义容器类，不显示未分类
    FriendLinksLight_Plugin::output('sidebar-links', '', null, 0);
    
    // 只显示分类ID为3的链接
    FriendLinksLight_Plugin::output('', '', 3);
    
    // 只显示异常链接，并追加卡片类
    FriendLinksLight_Plugin::outputDead('error-links', 'highlight-card');
    ?&gt;
    </pre>
    
        <h4>3. 前台排序与随机</h4>
        <p>排序方式由<strong>插件设置</strong>决定（手动 / 时间 / 标题 / 随机）。<br>
        选择“随机”时，服务器按手动顺序输出 DOM，然后通过 JavaScript 在浏览器端随机打乱卡片顺序，兼容 HTML 渲染缓存。</p>
    
        <h4>4. 存活检测机制</h4>
        <ul>
            <li>添加/编辑链接时会自动进行一次 HEAD 请求检测存活状态（仅判断 HTTP 2xx/3xx）。</li>
            <li>管理面板可对单个链接点击“检查”，或点击“检查所有状态”批量检测。</li>
            <li>前端默认跳过异常链接可在插件设置中开启（<code>跳过异常网站</code>），该设置对 <code>[friendlinks dead]</code> 无效。</li>
            <li>Cron 定时任务：自动批量检测所有链接存活状态。</li>
        </ul>
    
        <h4>5. 定时任务 Cron</h4>
        <p>服务器定时触发，批量更新所有链接的存活状态：</p>
        <pre class="usage-pre">    Cron URL：https://astrsource.com/friendlinkslight/cron<br>
    示例 (每 2 小时执行)：<br>
    0 */2 * * * curl -s "https://astrsource.com/friendlinkslight/cron" &gt; /dev/null 2&gt;&amp;1
    </pre>
        <p><strong>密钥</strong>：若插件设置中填写了 Cron 密钥，必须在 URL 中携带 <code>?key=你的密钥</code>，否则请求会被拒绝（HTTP 403）。</p>
    
        <h4>6. HTML 渲染缓存</h4>
        <ul>
            <li>未指定分类/未筛选异常且未分类模式为“全部”时，前端输出会自动生成渲染缓存文件（<code>usr/plugins/FriendLinksLight/cache/</code>），过期时间由插件设置控制（默认7天）。</li>
            <li>数据或模板变更后，缓存会自动清除（保存链接/分类时触发）。</li>
            <li>管理面板的“刷新缓存”按钮可手动清除所有渲染缓存。</li>
        </ul>
    
        <h4>7. 自定义模板占位符</h4>
        <p>在插件设置的“卡片模板”中可使用以下占位符：</p>
        <ul>
            <li><code>{url}</code> - 网站地址</li>
            <li><code>{title}</code> - 网站标题</li>
            <li><code>{description}</code> - 描述</li>
            <li><code>{icon}</code> - 图标 URL</li>
            <li><code>{last_update}</code> - 最后更新日期（Y-m-d 格式）</li>
            <li><code>{alive}</code> - 存活状态文字（正常/异常）</li>
            <li><code>{category}</code> - 所属分类名称</li>
        </ul>
    </div>
</div>

<!-- 分类模态框 -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="categoryModalTitle">添加分类</h3><span onclick="closeCategoryModal()" style="cursor:pointer;">&times;</span></div>
        <form id="categoryForm" method="post">
            <input type="hidden" name="action" id="catFormAction" value="add_category">
            <input type="hidden" name="id" id="catId">
            <div class="form-group"><label>名称</label><input name="name" id="catName" required></div>
            <div class="form-group"><label>排序</label><input type="number" name="sort" id="catSort" value=""></div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </div>
</div>

<!-- 链接模态框 -->
<div id="linkModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="modalTitle">添加链接</h3><span class="modal-close" onclick="closeModal()">&times;</span></div>
        <form id="linkForm" method="post">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="linkId">
            <div class="form-group"><label>标题</label><input name="title" id="linkTitle"></div>
            <div class="form-group"><label>URL *</label><input name="url" id="linkUrl" required></div>
            <div class="form-group"><label>描述</label><textarea name="description" id="linkDescription" rows="2"></textarea></div>
            <div class="form-group"><label>图标</label><input name="icon" id="linkIcon"></div>
            <div class="form-group"><label>分类</label><select name="category_id" id="linkCategory"><option value="">未分类</option><?php foreach($categories as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>状态</label><select name="status" id="linkStatus"><option value="1">显示</option><option value="0">隐藏</option></select></div>
            <div class="form-group"><label>排序</label><input type="number" name="sort" id="linkSort" value="0"></div>
            <button type="submit" class="btn btn-primary">保存</button>
        </form>
    </div>
</div>

<script>
    var nextSortValue = <?php echo $nextSort; ?>;
    var nextCatSortValue = <?php echo $nextCatSort; ?>;

    (function() {
        var msg = document.querySelector('.message.notice');
        if (msg) {
            setTimeout(function() {
                msg.style.opacity = '0';
                setTimeout(function() { if (msg.parentNode) msg.remove(); }, 500);
            }, 3000);
        }
    })();

    function openCategoryModal(){ document.getElementById('categoryModalTitle').textContent='添加分类'; document.getElementById('catFormAction').value='add_category'; document.getElementById('catId').value=''; document.getElementById('catName').value=''; document.getElementById('catSort').value=nextCatSortValue; document.getElementById('categoryModal').style.display='block'; }
    function editCategory(cat){ document.getElementById('categoryModalTitle').textContent='编辑分类'; document.getElementById('catFormAction').value='edit_category'; document.getElementById('catId').value=cat.id; document.getElementById('catName').value=cat.name; document.getElementById('catSort').value=cat.sort; document.getElementById('categoryModal').style.display='block'; }
    function closeCategoryModal(){ document.getElementById('categoryModal').style.display='none'; }
    function openModal(){ document.getElementById('modalTitle').textContent='添加链接'; document.getElementById('formAction').value='add'; document.getElementById('linkId').value=''; document.getElementById('linkTitle').value=''; document.getElementById('linkUrl').value=''; document.getElementById('linkDescription').value=''; document.getElementById('linkIcon').value=''; document.getElementById('linkCategory').value=''; document.getElementById('linkStatus').value='1'; document.getElementById('linkSort').value=nextSortValue; document.getElementById('linkModal').style.display='block'; }
    function editLink(link){ document.getElementById('modalTitle').textContent='编辑链接'; document.getElementById('formAction').value='edit'; document.getElementById('linkId').value=link.id; document.getElementById('linkTitle').value=link.title||''; document.getElementById('linkUrl').value=link.url||''; document.getElementById('linkDescription').value=link.description||''; document.getElementById('linkIcon').value=link.icon||''; document.getElementById('linkCategory').value=link.category_id||''; document.getElementById('linkStatus').value=link.status; document.getElementById('linkSort').value=link.sort; document.getElementById('linkModal').style.display='block'; }
    function closeModal(){ document.getElementById('linkModal').style.display='none'; }
    window.onclick=function(e){ if(e.target==document.getElementById('linkModal')) closeModal(); if(e.target==document.getElementById('categoryModal')) closeCategoryModal(); };

    document.addEventListener('DOMContentLoaded', function(){
        document.getElementById('linkForm').addEventListener('submit', function(e){ e.preventDefault(); submitAjax(this, true); });
        document.getElementById('categoryForm').addEventListener('submit', function(e){ e.preventDefault(); submitAjax(this, true); });
        document.querySelectorAll('.ajax-form').forEach(f=>{
            f.addEventListener('submit', function(e){
                e.preventDefault();
                var confirmMsg = this.getAttribute('data-confirm');
                if (confirmMsg && !confirm(confirmMsg)) {
                    return false;
                }
                submitAjax(this, true);
            });
        });

        // 排序、筛选事件
        document.getElementById('sortSelect').addEventListener('change', function(){ loadTableBySort(this.value); });
        document.getElementById('refreshSortBtn').addEventListener('click', function(){ loadTableBySort(document.getElementById('sortSelect').value); });
        document.getElementById('categoryFilterSelect').addEventListener('change', function(){ loadTableBySort(document.getElementById('sortSelect').value); });
        bindPaginationEvents();
    });

    /**
     * Ajax 提交函数
     * @param {HTMLFormElement} form
     * @param {boolean} reload - 提交后是否刷新页面
     */
    function submitAjax(form, reload){
        var data = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        if(btn){ btn.disabled=true; var origText=btn.textContent; btn.textContent='处理中...'; }
        fetch(window.location.href, {method:'POST', body:data})
            .then(r=>{ if(!r.ok) throw new Error('Server error'); return r.text(); })
            .then(html=>{
                if (reload) {
                    location.reload();
                } else {
                    // 局部更新表格等（用于排序/分页）
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newTable = doc.querySelector('.friendlinks-table');
                    if(newTable) document.querySelector('.friendlinks-table').outerHTML = newTable.outerHTML;
                    var newPagination = doc.querySelector('.friendlinks-pagination');
                    if(newPagination) {
                        var oldPag = document.querySelector('.friendlinks-pagination');
                        if(oldPag) oldPag.outerHTML = newPagination.outerHTML;
                    }
                    bindPaginationEvents();
                }
            })
            .catch(e=>alert('操作失败'))
            .finally(()=>{ if(btn){ btn.disabled=false; btn.textContent=origText; } });
    }

    function loadTableBySort(sortBy){
        var catId = document.getElementById('categoryFilterSelect').value;
        var perPage = document.getElementById('perPageSelect') ? document.getElementById('perPageSelect').value : <?php echo $perPage; ?>;
        var url = new URL(window.location.href);
        url.searchParams.set('sortby', sortBy);
        if(catId==='all') url.searchParams.delete('category_id'); else url.searchParams.set('category_id', catId);
        url.searchParams.set('page', 1);
        url.searchParams.set('per_page', perPage);
        loadTableByUrl(url.toString());
    }

    function loadTableByUrl(url){
        fetch(url).then(r=>r.text()).then(html=>{
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newTable = doc.querySelector('.friendlinks-table');
            if(newTable) document.querySelector('.friendlinks-table').outerHTML = newTable.outerHTML;
            var newPagination = doc.querySelector('.friendlinks-pagination');
            if(newPagination) {
                var oldPag = document.querySelector('.friendlinks-pagination');
                if(oldPag) oldPag.outerHTML = newPagination.outerHTML;
            }
            var newCache = doc.querySelector('.cache-info');
            if(newCache) document.querySelector('.cache-info').outerHTML = newCache.outerHTML;
            var newCategory = doc.querySelector('.category-section');
            if(newCategory) document.querySelector('.category-section').outerHTML = newCategory.outerHTML;
            window.history.replaceState(null, '', url);
            document.cookie = 'friendlinks_sort='+(new URL(url).searchParams.get('sortby')||'sort')+';path=/;max-age=31536000;SameSite=Lax';
            bindEditButtons();
            bindPaginationEvents();
        });
    }

    function bindEditButtons(){
        document.querySelectorAll('.actions button[onclick^="editLink"]').forEach(btn => {
            var fn = btn.getAttribute('onclick');
            if (fn) btn.onclick = function() { eval(fn); };
        });
    }

    function bindPaginationEvents(){
        document.querySelectorAll('.friendlinks-pagination a').forEach(a=>{
            a.addEventListener('click', function(e){ e.preventDefault(); loadTableByUrl(this.href); });
        });
        var perPageSel = document.getElementById('perPageSelect');
        if(perPageSel){
            perPageSel.addEventListener('change', function(){
                var url = new URL(window.location.href);
                url.searchParams.set('per_page', this.value);
                url.searchParams.set('page', 1);
                loadTableByUrl(url.toString());
            });
        }
    }
</script>
<?php include 'copyright.php'; include 'common-js.php'; include 'footer.php'; ?>