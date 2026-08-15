<?php

/**
 * FriendsRSS 管理面板
 *
 * @package FriendsRSS
 */

// 确保在Typecho后台环境中
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'header.php';
include 'menu.php';

// 检查用户权限
Typecho_Widget::widget('Widget_User')->to($user);
if (!$user->pass('administrator')) {
    throw new Typecho_Widget_Exception(_t('禁止访问'), 403);
}

// 引入核心类
require_once __DIR__ . '/Core.php';
$core = new FriendsRSS_Core();

// 处理操作
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'save_rss':
            $linkName = trim($_POST['link_name']);
            $rssUrl = trim($_POST['rss_url']);
            if ($linkName && $rssUrl) {
                try {
                    // 保存RSS地址到配置文件
                    $rssConfig = array();
                    $configFile = __DIR__ . '/rss_config.json';
                    
                    // 确保配置文件存在
                    if (!file_exists($configFile)) {
                        file_put_contents($configFile, '{}', LOCK_EX);
                    }
                    
                    if (file_exists($configFile)) {
                        $content = file_get_contents($configFile);
                        $rssConfig = json_decode($content, true) ?: array();
                    }
                    
                    $rssConfig[$linkName] = $rssUrl;
                    $result = file_put_contents($configFile, json_encode($rssConfig, JSON_PRETTY_PRINT), LOCK_EX);
                    
                    if ($result !== false) {
                        $core->log("手动保存RSS配置成功: $linkName -> $rssUrl");
                        $notice = new Typecho_Widget_Helper_Layout();
                        $notice->html('<div class="message success">RSS地址保存成功：' . htmlspecialchars($linkName) . ' -> ' . htmlspecialchars($rssUrl) . '</div>');
                    } else {
                        throw new Exception('文件写入失败');
                    }
                } catch (Exception $e) {
                    $core->log("手动保存RSS配置失败: " . $e->getMessage(), 'ERROR');
                    $notice = new Typecho_Widget_Helper_Layout();
                    $notice->html('<div class="message error">RSS地址保存失败：' . htmlspecialchars($e->getMessage()) . '</div>');
                }
            } else {
                $notice = new Typecho_Widget_Helper_Layout();
                $notice->html('<div class="message error">请填写完整的友链名称和RSS地址</div>');
            }
            break;

        case 'parse_rss':
            $articles = $core->getAggregatedArticles(true);
            $notice = new Typecho_Widget_Helper_Layout();
            $notice->html('<div class="message success">RSS解析完成，获取到 ' . count($articles) . ' 篇文章</div>');
            break;
        case 'manual_cron':
            $articles = $core->getAggregatedArticles(true);
            $parseInterval = $core->getConfiguredInterval('autoRefreshInterval', 6);
            $now = time();
            $core->writeScheduleStatus('cron_status.json', array(
                'last_run' => $now,
                'articles_count' => count($articles),
                'status' => 'success',
                'next_run' => $parseInterval > 0 ? ($now + $parseInterval * 3600) : 0,
                'trigger' => 'manual'
            ));
            $notice = new Typecho_Widget_Helper_Layout();
            $notice->html('<div class="message success">定时解析执行完成，获取到 ' . count($articles) . ' 篇文章</div>');
            break;
        case 'manual_detect':
            $links = $core->getFriendLinks();
            $results = $core->batchDetectRSS($links, null, true);
            $successCount = array_sum(array_column($results, 'success'));
            $detectInterval = $core->getConfiguredInterval('autoDetectInterval', 240);
            $now = time();
            $core->writeScheduleStatus('detect_status.json', array(
                'last_run' => $now,
                'success_count' => $successCount,
                'total_count' => count($links),
                'status' => 'success',
                'next_run' => $detectInterval > 0 ? ($now + $detectInterval * 3600) : 0,
                'trigger' => 'manual'
            ));
            $notice = new Typecho_Widget_Helper_Layout();
            $notice->html('<div class="message success">定时检测执行完成，成功检测到 ' . $successCount . ' 个RSS地址</div>');
            break;
    }
}

// 获取数据（不自动解析RSS）
$links = $core->getFriendLinks();
$stats = $core->getStats();
// 获取可用的友链分类
$availableCategories = $core->getAvailableCategories();
// 只获取已有的聚合文章，不进行新的RSS解析
$cacheFile = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/aggregated_articles.json';
$articles = array();
if (file_exists($cacheFile)) {
    $cached = @file_get_contents($cacheFile);
    if ($cached) {
        $articles = json_decode($cached, true) ?: array();
    }
}
$pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('FriendsRSS');

// 读取RSS配置
$rssConfig = array();
$configFile = __DIR__ . '/rss_config.json';
if (file_exists($configFile)) {
    $rssConfig = json_decode(file_get_contents($configFile), true) ?: array();
}
?>

<style>
    .friends-rss-admin {
        max-width: 1200px;
        margin: 0 auto;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 15px;
        margin-bottom: 20px;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 15px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #467b96;
        display: block;
    }

    .stat-label {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }

    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }

    .action-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .action-card h3 {
        margin: 0 0 10px 0;
        color: #333;
        font-size: 16px;
    }

    .action-card .description {
        margin: 10px 0;
        font-size: 13px;
        color: #666;
        line-height: 1.4;
    }

    .btn {
        display: inline-block;
        padding: 8px 16px;
        background: #467b96;
        color: white;
        text-decoration: none;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        transition: background-color 0.2s;
    }

    .btn:hover {
        background: #3a6a82;
    }

    .btn.secondary {
        background: #6c757d;
    }

    .btn.secondary:hover {
        background: #5a6268;
    }

    .btn.danger {
        background: #dc3545;
    }

    .btn.danger:hover {
        background: #c82333;
    }

    .success {
        color: #28a745;
    }

    .error {
        color: #dc3545;
    }

    .info {
        color: #17a2b8;
    }

    .section-title {
        margin: 30px 0 15px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #467b96;
        color: #333;
        font-size: 18px;
    }

    .table-responsive {
        background: white;
        border: 1px solid #ddd;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .table-responsive table {
        margin: 0;
    }

    .rss-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
    }

    .rss-status.detected {
        background: #d4edda;
        color: #155724;
    }

    .rss-status.pending {
        background: #fff3cd;
        color: #856404;
    }

    .rss-status.failed {
        background: #f8d7da;
        color: #721c24;
    }

    .rss-status.excluded {
        background: #e2e3e5;
        color: #6c757d;
    }

    /* RSS状态样式优化 */
    /* .rss-status-cell {
        text-align: center;
    } */

    .input-group {
        display: flex;
        margin-bottom: 10px;
        align-items: stretch;
        gap: 10px;
    }

    .input-group input,
    .input-group select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 13px;
        box-sizing: border-box;
    }

    /* 覆盖Typecho默认选择框样式 */
    .input-group select {
        height: auto !important;
    }

    .input-group input {
        flex: 1;
        min-width: 0;
        width: 65%;
    }

    .input-group select {
        min-width: 120px;
        flex-shrink: 0;
        width: 30%;
    }

    .input-group .btn {
        flex-shrink: 0;
        white-space: nowrap;
    }

    /* 响应式优化 */
    @media (max-width: 600px) {
        .input-group {
            flex-direction: column;
            align-items: stretch;
        }

        .input-group select,
        .input-group input,
        .input-group .btn {
            margin: 0;
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .action-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }



    /* 定时解析状态样式 */
    .cron-status {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
        margin-left: 10px;
    }

    .cron-status.active {
        background: #d4edda;
        color: #155724;
    }

    .cron-status.inactive {
        background: #f8d7da;
        color: #721c24;
    }
</style>

<div class="main friends-rss-admin">
    <div class="body container">
        <?php if (isset($notice)): $notice->render();
        endif; ?>

        <div class="colgroup">
            <div class="col-mb-12">
                <div class="typecho-page-title">
                    <h2>友链RSS聚合管理</h2>
                </div>
            </div>
        </div>

        <!-- 统计信息 -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number"><?php echo $stats['blogCount']; ?></span>
                <div class="stat-label">友链数量</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo $stats['articleCount']; ?></span>
                <div class="stat-label">文章数量</div>
            </div>
            <div class="stat-card">
                <span class="stat-number">
                    <?php
                    if ($stats['lastUpdate'] && $stats['lastUpdate'] > 0) {
                        echo date('m-d H:i', $stats['lastUpdate']);
                    } else {
                        echo '暂无';
                    }
                    ?>
                </span>
                <div class="stat-label">最后更新</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo isset($stats['configuredRss']) ? $stats['configuredRss'] : count($rssConfig); ?></span>
                <div class="stat-label">已配置RSS</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo count($availableCategories); ?></span>
                <div class="stat-label">可用分类</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php 
                    $excludeUrls = $pluginOptions->excludeUrls ?: '';
                    $excludeCount = 0;
                    if (!empty($excludeUrls)) {
                        $lines = explode("\n", $excludeUrls);
                        foreach ($lines as $line) {
                            if (!empty(trim($line))) {
                                $excludeCount++;
                            }
                        }
                    }
                    echo $excludeCount;
                ?></span>
                <div class="stat-label">排除网址</div>
            </div>
        </div>

        <!-- 操作面板 -->
        <div class="action-grid">
            <div class="action-card">
                <h3>⏰ 定时检测RSS地址</h3>
                <p class="description">
                    <?php
                    $detectInterval = $core->getConfiguredInterval('autoDetectInterval', 240);
                    ?>
                    当前定时检测间隔：<?php echo $detectInterval > 0 ? $detectInterval . '小时' : '已禁用'; ?>
                    <?php
                    $detectFile = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/detect_status.json';
                    $detectStatus = 'inactive';
                    $nextDetectRun = '未设置';
                    if ($detectInterval <= 0) {
                        $detectStatus = 'inactive';
                        $nextDetectRun = '已禁用';
                    } elseif (file_exists($detectFile)) {
                        $detectData = json_decode(file_get_contents($detectFile), true);
                        if ($detectData && isset($detectData['last_run'])) {
                            $detectStatus = 'active';
                            $nextDetectRunTime = intval($detectData['last_run']) + ($detectInterval * 3600);
                            $nextDetectRun = date('m-d H:i', $nextDetectRunTime);
                        }
                    }
                    ?>
                    <span class="cron-status <?php echo $detectStatus; ?>">
                        <?php echo $detectStatus == 'active' ? '运行中' : '已停止'; ?>
                    </span>
                </p>
                <p class="description">下次执行时间：<?php echo $nextDetectRun; ?></p>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="manual_detect">
                    <button type="submit" class="btn">立即执行</button>
                </form>
            </div>

            <div class="action-card">
                <h3>📰 定时解析RSS内容</h3>
                <p class="description">
                    <?php
                    $parseInterval = $core->getConfiguredInterval('autoRefreshInterval', 6);
                    ?>
                    当前定时解析间隔：<?php echo $parseInterval > 0 ? $parseInterval . '小时' : '已禁用'; ?>
                    <?php
                    $cronFile = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/cron_status.json';
                    $cronStatus = 'inactive';
                    $nextRun = '未设置';
                    if ($parseInterval <= 0) {
                        $cronStatus = 'inactive';
                        $nextRun = '已禁用';
                    } elseif (file_exists($cronFile)) {
                        $cronData = json_decode(file_get_contents($cronFile), true);
                        if ($cronData && isset($cronData['last_run'])) {
                            $cronStatus = 'active';
                            $nextRunTime = intval($cronData['last_run']) + ($parseInterval * 3600);
                            $nextRun = date('m-d H:i', $nextRunTime);
                        }
                    }
                    ?>
                    <span class="cron-status <?php echo $cronStatus; ?>">
                        <?php echo $cronStatus == 'active' ? '运行中' : '已停止'; ?>
                    </span>
                </p>
                <p class="description">下次执行时间：<?php echo $nextRun; ?></p>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="manual_cron">
                    <button type="submit" class="btn">立即执行</button>
                </form>
            </div>

            <div class="action-card">
                <h3>📝 添加RSS地址</h3>
                <p class="description">为友链手动添加RSS订阅地址。</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_rss">
                    <div class="input-group">
                        <select name="link_name" required>
                            <option value="">选择友链</option>
                            <?php foreach ($links as $link): ?>
                                <option value="<?php echo htmlspecialchars($link['name']); ?>">
                                    <?php echo htmlspecialchars($link['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="url" name="rss_url" placeholder="RSS地址" required>
                        <button type="submit" class="btn">保存</button>
                    </div>
                </form>
            </div>


        </div>

        <!-- 访问地址 -->
        <h3 class="section-title">📡 访问地址</h3>
        <div class="table-responsive">
            <table class="typecho-list-table">
                <tbody>
                    <tr>
                        <td style="width: 120px;"><strong>RSS订阅地址</strong></td>
                        <td>
                            <a href="<?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=rss" target="_blank">
                                <?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=rss
                            </a>
                        </td>
                    </tr>
                    <?php if ($pluginOptions->enableFrontend): ?>
                        <tr>
                            <td><strong>前台接口地址</strong></td>
                            <td>
                                <a href="<?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=page" target="_blank">
                                    <?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=page
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>前台展示页面</strong></td>
                            <td>
                                <a href="<?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=pageview" target="_blank">
                                    <?php echo Typecho_Widget::widget('Widget_Options')->siteUrl; ?>action/friends-rss?do=pageview
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>可用友链分类</strong></td>
                        <td>
                            <?php if (empty($availableCategories)): ?>
                                <span style="color: #999;">无分类（所有友链）</span>
                            <?php else: ?>
                                <?php foreach ($availableCategories as $index => $category): ?>
                                    <span class="rss-status <?php echo $category == ($pluginOptions->linkCategory ?: '') ? 'detected' : 'pending'; ?>">
                                        <?php echo htmlspecialchars($category); ?>
                                        <?php if ($category == ($pluginOptions->linkCategory ?: '')): ?>
                                            (当前使用)
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($index < count($availableCategories) - 1): ?> &nbsp; <?php endif; ?>
                                <?php endforeach; ?>
                                <br><small style="color: #666; margin-top: 5px; display: inline-block;">
                                    💡 提示：在插件设置中可以选择特定分类，留空表示获取所有友链
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>排除检测网址</strong></td>
                        <td>
                            <?php 
                            $excludeUrls = $pluginOptions->excludeUrls ?: '';
                            if (empty($excludeUrls)): ?>
                                <span style="color: #999;">无排除网址</span>
                            <?php else: ?>
                                <?php 
                                $lines = explode("\n", $excludeUrls);
                                foreach ($lines as $index => $line): 
                                    $line = trim($line);
                                    if (!empty($line)):
                                ?>
                                    <span class="rss-status excluded">
                                        <?php echo htmlspecialchars($line); ?>
                                    </span>
                                    <?php if ($index < count($lines) - 1): ?> &nbsp; <?php endif; ?>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                                <br><small style="color: #666; margin-top: 5px; display: inline-block;">
                                    💡 提示：这些网址的友链将被排除在RSS检测和聚合之外
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>



        <!-- 友链列表 -->
        <h3 class="section-title">👥 友链列表 (<?php echo count($links); ?> 个)</h3>
        <?php if (empty($links)): ?>
            <div class="action-card">
                <p class="description">暂无友链数据。请先在 <a href="<?php echo Typecho_Widget::widget('Widget_Options')->adminUrl; ?>extending.php?panel=Handsome%2Fmanage-links.php">友情链接管理</a> 中添加友链。</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="typecho-list-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">名称</th>
                            <th style="width: 30%;">地址</th>
                            <th style="width: 35%;">RSS地址</th>
                            <th style="width: 15%;">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $index => $link): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($link['name']); ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($link['url']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $rssUrl = isset($rssConfig[$link['name']]) ? $rssConfig[$link['name']] : '';
                                    if ($rssUrl) {
                                        echo '<a href="' . htmlspecialchars($rssUrl) . '" target="_blank" rel="noopener">' .
                                            htmlspecialchars($rssUrl) . '</a>';
                                    } else {
                                        echo '<span style="color: #999;">未配置</span>';
                                    }
                                    ?>
                                </td>
                                <td class="rss-status-cell">
                                    <?php
                                    if (isset($rssConfig[$link['name']]) && $rssConfig[$link['name']]) {
                                        echo '<span class="rss-status detected">✓ 已配置</span>';
                                    } else {
                                        echo '<span class="rss-status pending">未配置</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- 最新文章 -->
        <?php
        $displayArticles = array_slice($articles, 0, 15);
        ?>
        <h3 class="section-title">📝 最新文章 (显示 <?php echo count($displayArticles); ?> / <?php echo count($articles); ?> 篇)</h3>
        <?php if (empty($articles)): ?>
            <div class="action-card">
                <p class="description">暂无文章数据。请先检测RSS或等待聚合完成。</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="typecho-list-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">标题</th>
                            <th style="width: 20%;">博客</th>
                            <th style="width: 20%;">作者</th>
                            <th style="width: 20%;">发布时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayArticles as $article): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo htmlspecialchars($article['link']); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($article['title']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($article['blogName']); ?></td>
                                <td><?php echo htmlspecialchars($article['author']); ?></td>
                                <td><?php echo date('m-d H:i', $article['pubDate']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- 运行日志 -->
        <h3 class="section-title">📋 运行日志</h3>
        <div class="table-responsive">
            <?php
            $logFile = __DIR__ . '/error.log';
            $logContent = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (empty($logContent)) {
                    $logContent = '暂无运行日志';
                }
            } else {
                $logContent = '日志文件不存在';
            }
            ?>
            <div id="logContainer" style="padding: 15px; background: #f8f9fa; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; white-space: pre-wrap;">
                <?php echo htmlspecialchars($logContent); ?>
            </div>
        </div>
    </div>
</div>

<script>
    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 自动滚动日志到最新内容
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        // 为立即执行按钮添加加载状态
        const manualDetectBtn = document.querySelector('input[name="action"][value="manual_detect"]');
        if (manualDetectBtn) {
            manualDetectBtn.parentElement.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                btn.textContent = '执行中...';
                btn.disabled = true;
            });
        }

        // 为解析RSS按钮添加加载状态
        const parseBtn = document.querySelector('input[name="action"][value="parse_rss"]');
        if (parseBtn) {
            parseBtn.parentElement.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                btn.textContent = '解析中...';
                btn.disabled = true;
            });
        }

        // 为手动执行定时解析按钮添加加载状态
        const manualCronBtn = document.querySelector('input[name="action"][value="manual_cron"]');
        if (manualCronBtn) {
            manualCronBtn.parentElement.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                btn.textContent = '执行中...';
                btn.disabled = true;
            });
        }
    });
</script>

<?php
include __DIR__ . '/../../../admin/footer.php';
?>