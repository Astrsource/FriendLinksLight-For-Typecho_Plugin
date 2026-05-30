# FriendLinksLight 使用说明

> **版本**：v1.0.0 Light  
> **作者**：Astrsource  
> **适用**：Typecho 1.2+  
> **存储**：JSON 本地文件（无需数据库表）  
> **功能**：友情链接管理、分类管理、存活状态检测、HTML 渲染缓存、短代码/模板输出

---

## 目录

1. [插件简介](#1-插件简介)
2. [安装与启用](#2-安装与启用)
3. [目录结构](#3-目录结构)
4. [后台配置](#4-后台配置)
5. [管理面板](#5-管理面板)
6. [短代码使用](#6-短代码使用)
7. [模板函数调用](#7-模板函数调用)
8. [存活检测机制](#8-存活检测机制)
9. [定时任务 Cron](#9-定时任务-cron)
10. [HTML 渲染缓存](#10-html-渲染缓存)
11. [模板占位符](#11-模板占位符)
12. [常见问题](#12-常见问题)

---

## 1. 插件简介

FriendLinksLight 是 FriendLinks 的轻量版本，主要改进：

- **JSON 本地存储**：数据保存在 `data/links.json` 与 `data/categories.json`，无需数据库表，迁移方便。
- **移除抓取功能**：不再自动抓取标题、描述、图标，所有字段由用户手动填写，更可控。
- **仅保留存活检测**：通过 `HEAD` 请求检测链接 HTTP 状态码（2xx/3xx 为正常），支持单条检查与批量检查。
- **移除访客排序**：前台排序完全由后台设置控制，不再提供访客下拉切换，减少前端复杂度。
- **全排序支持 HTML 缓存**：包括“随机排序”也支持缓存——服务器按手动排序输出 HTML，由浏览器端 JavaScript 随机打乱卡片顺序。
- **简化的短代码/函数**：移除 `include_dead` 参数，是否显示异常链接由全局设置统一控制；新增 `dead` 无值属性用于单独输出异常链接。

---

## 2. 安装与启用

### 2.1 安装步骤

1. 下载插件，将文件夹命名为 `FriendLinksLight`。
2. 上传至 Typecho 的 `usr/plugins/` 目录。
3. 确保目录结构如下：

```
usr/plugins/FriendLinksLight/
├── Plugin.php          # 主插件文件
├── Action.php          # Ajax / Cron 接口
├── panel.php           # 后台管理面板
├── cache/              # 渲染缓存目录（自动创建）
└── data/               # JSON 数据目录（自动创建）
    ├── links.json      # 链接数据
    └── categories.json # 分类数据
```

4. 登录 Typecho 后台 → **控制台** → **插件** → 找到 **FriendLinksLight** → 点击 **启用**。

### 2.2 权限检查

插件启用时会自动创建 `cache/` 和 `data/` 目录。如果服务器权限不足，请手动创建并赋予写入权限：

```bash
cd /www/wwwroot/your-site/usr/plugins/FriendLinksLight
mkdir -p cache data
chmod 755 cache data
chown -R www:www cache data   # 根据实际运行用户调整，如 www-data / nginx
```

> 若启用时提示“无法创建数据目录”或“无法写入初始化文件”，请按上述命令修复目录权限后重新启用。

---

## 3. 目录结构

```
FriendLinksLight/
├── Plugin.php              # 核心逻辑：激活、配置、CRUD、渲染、存活检测
├── Action.php              # 后台 Ajax 接口 & Cron 定时任务入口
├── panel.php               # 后台管理界面（HTML + JS）
├── cache/                  # HTML 渲染缓存文件
│   └── friendlinks_rendered_*.html
└── data/                   # JSON 数据存储
    ├── links.json          # 所有链接数据
    └── categories.json     # 所有分类数据
```

---

## 4. 后台配置

启用插件后，进入 **控制台** → **插件** → **FriendLinksLight** → **设置**，可配置以下选项：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| **渲染缓存时间（秒）** | `604800` | 前台 HTML 缓存有效期，默认 7 天。数据变更会自动清缓存，也可手动刷新。 |
| **请求超时（秒）** | `10` | 存活检测时 cURL 的超时时间。 |
| **默认图标 URL** | `/favicon.png` | 当链接未填写图标时，前台显示的默认图标。 |
| **卡片模板** | 默认模板 | HTML 模板，支持占位符（见第 11 节）。 |
| **自定义 CSS** | 默认样式 | 友情链接卡片的自定义样式，直接写入前台 `<style>` 标签。 |
| **前台排序方式** | 手动排序 | 可选：手动排序 / 添加时间（新→旧）/ 添加时间（旧→新）/ 标题 A→Z / 标题 Z→A / 随机。 |
| **跳过异常网站** | 不跳过 | 开启后，前台默认隐藏存活状态为“异常”的链接。短代码 `[friendlinks dead]` 不受此限制。 |
| **Cron 密钥** | 空 | 服务器定时任务访问 `/FriendLinksLight/cron` 时的验证密钥，为空则不验证。 |
| **禁用时删除数据文件** | 不删除 | 若选择“删除”，禁用插件时会自动删除 `links.json` 和 `categories.json`，数据永久丢失。 |

---

## 5. 管理面板

启用插件后，后台左侧菜单会出现 **友情链接** 入口。

### 5.1 分类管理

- **添加分类**：填写分类名称和排序值（留空自动递增）。
- **编辑分类**：修改名称或排序。
- **删除分类**：删除后，该分类下的链接自动变为“未分类”。
- **未分类**：不属于任何分类的链接归入此项。

### 5.2 链接管理

表格字段说明：

| 字段 | 说明 |
|------|------|
| **ID** | 链接唯一标识 |
| **存活** | 正常（绿色）/ 异常（红色）/ 未知（灰色） |
| **分类** | 所属分类名称 |
| **标题** | 网站名称 |
| **描述** | 网站简介（鼠标悬停可查看完整内容） |
| **URL** | 网站地址，点击可新窗口打开 |
| **图标** | 图标 URL 预览 |
| **状态** | 显示 / 隐藏（隐藏的前台不输出） |
| **排序** | 数字越小越靠前 |
| **最后更新** | 链接信息最后修改时间 |
| **操作** | 编辑 / 检查状态 / 删除 |

### 5.3 工具栏按钮

- **➕ 添加链接**：弹出模态框填写链接信息。
- **🔄 检查所有状态**：批量并发检测所有链接的 HTTP 存活状态（Ajax 提交，完成后需手动刷新页面查看结果）。
- **🗑️ 刷新缓存**：清除所有 HTML 渲染缓存文件。
- **🔢 重整序号**：将所有链接的 `sort` 值从 1 开始连续重排。
- **🗑️ 删除异常链接**：一键删除所有存活状态为“异常”的链接（不可恢复，谨慎操作）。

### 5.4 筛选与分页

- **分类筛选**：全部 / 未分类 / 异常 / 具体分类。
- **排序**：手动 / 时间 / 标题 / 随机（仅影响后台列表展示）。
- **分页**：支持 10 / 20 / 50 条每页切换。

---

## 6. 短代码使用

在文章或页面中插入短代码即可输出友情链接列表。

### 6.1 基本语法

```
[friendlinks]
```

### 6.2 支持的参数

参数顺序任意，可组合使用：

| 参数 | 类型 | 说明 | 示例 |
|------|------|------|------|
| `dead` | 无值属性 | **仅输出异常链接**（存活状态为 0），不受全局“跳过异常”设置影响 | `[friendlinks dead]` |
| `container_class="..."` | 字符串 | 外层容器自定义类名，默认 `friendlinks-container` | `[friendlinks container_class="my-links"]` |
| `card_class="..."` | 字符串 | 追加到每张卡片 `.friendlink-card` 的类名，用于微调样式 | `[friendlinks card_class="compact-card"]` |
| `category_id="数字"` | 整数 | 仅输出指定分类 ID 的链接 | `[friendlinks category_id="1"]` |
| `include_uncategorized="1/0/2"` | 字符串 | `1`=全部（默认），`0`=排除未分类，`2`=仅未分类。指定 `category_id` 时此参数被忽略 | `[friendlinks include_uncategorized="0"]` |

### 6.3 短代码示例

```html
<!-- 默认输出所有可见链接 -->
[friendlinks]

<!-- 输出分类ID为2的链接，并追加卡片类 -->
[friendlinks category_id="2" card_class="my-card"]

<!-- 仅输出异常链接，自定义容器类 -->
[friendlinks dead container_class="dead-links"]

<!-- 排除未分类，只显示已归属分类的链接 -->
[friendlinks include_uncategorized="0"]

<!-- 参数顺序随意，效果相同 -->
[friendlinks card_class="wide" dead container_class="alert-links"]
```

---

## 7. 模板函数调用

在主题模板（如 `sidebar.php`、`page.php`）中使用 PHP 函数直接输出。

### 7.1 函数签名

```php
/**
 * 输出友情链接（默认模式）
 *
 * @param string $containerClass    外层容器类名，默认 friendlinks-container
 * @param string $cardClass         追加到卡片的类名，默认空
 * @param int|null $categoryId       指定分类ID，null 为所有分类
 * @param int $uncategorizedMode    0=排除未分类, 1=全部(默认), 2=仅未分类
 */
FriendLinksLight_Plugin::output($containerClass = '', $cardClass = '', $categoryId = null, $uncategorizedMode = 1);

/**
 * 仅输出异常链接
 * 参数与 output() 完全相同，内部强制 deadOnly = true
 */
FriendLinksLight_Plugin::outputDead($containerClass = '', $cardClass = '', $categoryId = null, $uncategorizedMode = 1);
```

### 7.2 调用示例

```php
<?php
// 1. 默认输出所有可见链接
FriendLinksLight_Plugin::output();

// 2. 自定义容器类，不显示未分类链接
FriendLinksLight_Plugin::output('sidebar-links', '', null, 0);

// 3. 只显示分类ID为3的链接
FriendLinksLight_Plugin::output('', '', 3);

// 4. 只显示异常链接，并追加卡片类
FriendLinksLight_Plugin::outputDead('error-links', 'highlight-card');
?>
```

---

## 8. 存活检测机制

### 8.1 检测方式

插件使用 PHP `cURL` 发送 `HEAD` 请求到目标 URL：

- HTTP 状态码 `2xx` 或 `3xx` → **正常**（`alive = 1`）
- 其他状态码或超时/无法连接 → **异常**（`alive = 0`）

### 8.2 触发场景

| 场景 | 说明 |
|------|------|
| **添加/编辑链接** | 保存时会自动进行一次存活检测。 |
| **单条检查** | 管理面板表格中点击链接右侧的 **检查** 按钮。 |
| **批量检查** | 管理面板点击 **检查所有状态**，使用 `curl_multi` 并发检测，每批 10 个链接。 |
| **定时任务** | 通过服务器 Cron 定期触发 `/FriendLinksLight/cron` 自动批量检测。 |

### 8.3 前台显示控制

- 若插件设置中开启 **跳过异常网站**，前台默认不显示 `alive = 0` 的链接。
- 使用短代码 `[friendlinks dead]` 或模板函数 `outputDead()` 时，**强制只显示异常链接**，不受全局设置影响。

---

## 9. 定时任务 Cron

### 9.1 Cron URL

```
https://your-site.com/FriendLinksLight/cron
```

若设置了 **Cron 密钥**，URL 需携带密钥：

```
https://your-site.com/FriendLinksLight/cron?key=你的密钥
```

### 9.2 添加 Cron 任务

Linux 服务器示例（每 2 小时执行一次）：

```bash
0 */2 * * * curl -s "https://your-site.com/FriendLinksLight/cron?key=你的密钥" > /dev/null 2>&1
```

或使用 `wget`：

```bash
0 */2 * * * wget -q -O /dev/null "https://your-site.com/FriendLinksLight/cron?key=你的密钥"
```

### 9.3 返回结果

- 成功：`OK: Checked 15 links at 2026-05-30 14:00:00`
- 密钥错误：`Invalid key`（HTTP 403）

---

## 10. HTML 渲染缓存

### 10.1 缓存机制

为提升前台性能，插件在以下条件下自动生成 HTML 渲染缓存：

- 未指定 `category_id`
- `include_uncategorized` 为默认值 `1`（全部）
- 未使用 `dead` 模式

缓存文件保存在 `usr/plugins/FriendLinksLight/cache/` 目录，文件名基于参数哈希生成，例如：

```
friendlinks_rendered_a1b2c3d4e5f6...html
```

### 10.2 缓存清除时机

以下操作会自动清除所有渲染缓存：

- 添加 / 编辑 / 删除链接
- 添加 / 编辑 / 删除分类
- 点击管理面板的 **刷新缓存**
- 修改插件设置（模板、CSS、排序等）后保存

### 10.3 随机排序与缓存兼容

当后台设置排序为 **随机** 时：

1. 服务器按 **手动排序** 输出静态 HTML（确保缓存一致）。
2. 在 HTML 末尾注入一段 JavaScript，在浏览器端随机打乱卡片 DOM 顺序。
3. 这样既享受了 HTML 文件缓存的高性能，又实现了每次刷新页面随机展示的效果。

---

## 11. 模板占位符

在插件设置的 **卡片模板** 中，可使用以下占位符，渲染时会被替换为实际值：

| 占位符 | 替换内容 | 示例 |
|--------|----------|------|
| `{url}` | 网站地址 | `https://typecho.org` |
| `{title}` | 网站标题 | `Typecho 官方` |
| `{description}` | 网站描述 | `一款轻量级博客程序` |
| `{icon}` | 图标 URL | `https://typecho.org/favicon.ico` |
| `{last_update}` | 最后更新日期 | `2026-05-30` |
| `{alive}` | 存活状态文字 | `正常` / `异常` / `未知` |
| `{category}` | 所属分类名称 | `技术博客` / `未分类` |

### 11.1 card_class 追加规则

若短代码或函数传入了 `card_class="my-card"`，插件会自动将模板中的 `friendlink-card` 替换为：

```html
class="friendlink-card my-card"
```

因此模板中必须保留 `friendlink-card` 作为基础类名。

---

## 12. 常见问题

### Q1：启用插件时提示“无法创建数据目录”

**原因**：PHP 进程没有写入 `usr/plugins/FriendLinksLight/` 的权限。  
**解决**：

```bash
chmod 755 usr/plugins/FriendLinksLight
chown -R www:www usr/plugins/FriendLinksLight
```

> 将 `www:www` 替换为实际运行 PHP 的用户组（如 `www-data:www-data`、`nginx:nginx`）。

### Q2：前台不显示友情链接

1. 检查链接的 **状态** 是否为“显示”。
2. 检查链接的 **存活** 状态是否为“异常”——若开启了“跳过异常网站”，异常链接会被隐藏。
3. 检查短代码或函数参数中的 `category_id` 是否正确。
4. 尝试在管理面板点击 **刷新缓存**。

### Q3：随机排序每次刷新都一样

**原因**：随机排序依赖浏览器端 JS 执行。如果页面使用了 PJAX 或无刷新加载，JS 可能未重新执行。  
**解决**：确保页面完整刷新，或检查主题是否阻止了内联 `<script>` 的执行。

### Q4：如何迁移数据？

由于使用 JSON 文件存储，直接复制以下两个文件即可：

```
usr/plugins/FriendLinksLight/data/links.json
usr/plugins/FriendLinksLight/data/categories.json
```

### Q5：禁用插件会丢失数据吗？

默认 **不会**。只有在插件设置中手动选择 **禁用时删除数据文件 → 删除**，禁用后才会删除 `links.json` 和 `categories.json`。请谨慎操作。

### Q6：定时任务返回 Invalid key

**原因**：插件设置中填写了 Cron 密钥，但 URL 未携带 `?key=...` 或密钥不匹配。  
**解决**：检查 URL 中的 `key` 参数与后台设置完全一致（区分大小写）。

### Q7：修改模板后前台没有变化

**原因**：HTML 渲染缓存仍在有效期内。  
**解决**：在管理面板点击 **刷新缓存**，或等待缓存自动过期。

---

## 附录：快速参考卡

### 短代码速查

```
[friendlinks]                                    ← 默认全部
[friendlinks dead]                               ← 仅异常
[friendlinks category_id="1"]                    ← 指定分类
[friendlinks include_uncategorized="0"]          ← 排除未分类
[friendlinks container_class="x" card_class="y"] ← 自定义样式
```

### 模板函数速查

```php
FriendLinksLight_Plugin::output();                        // 全部
FriendLinksLight_Plugin::output('', '', null, 0);        // 排除未分类
FriendLinksLight_Plugin::output('', '', 3);              // 分类ID=3
FriendLinksLight_Plugin::outputDead();                   // 仅异常
```

### Cron 速查

```bash
# 每2小时检查一次
0 */2 * * * curl -s "https://yoursite.com/FriendLinksLight/cron?key=密钥" > /dev/null 2>&1
```

---

*文档最后更新：2026-05-30*
