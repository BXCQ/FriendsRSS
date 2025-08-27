<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 友链RSS Action处理器
 */
class FriendsRSS_Action extends Typecho_Widget
{
    /**
     * 执行Action
     */
    public function execute()
    {
        $this->on($this->request->is('do=rss'))->rss();
        $this->on($this->request->is('do=page'))->pageData();
        $this->on($this->request->is('do=pageview'))->page();
        $this->on($this->request->is('do=clear'))->clearCache();
        $this->on($this->request->is('do=detect'))->detectRSS();
        $this->on($this->request->is('do=refresh'))->refreshData();
        $this->on($this->request->is('do=stats'))->getStats();
        $this->on($this->request->is('do=cron'))->cronTask();
    }

    /**
     * 输出RSS
     */
    public function rss()
    {
        require_once __DIR__ . '/Core.php';
        $core = new FriendsRSS_Core();
        $articles = $core->getAggregatedArticles(false); // 不强制刷新，只读取缓存
        
        header('Content-Type: application/rss+xml; charset=utf-8');
        
        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = $options->siteUrl;
        $title = $options->title . ' - 友链RSS聚合';
        $description = '来自友链博客的最新文章聚合';
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0">' . "\n";
        echo '<channel>' . "\n";
        echo '<title>' . htmlspecialchars($title) . '</title>' . "\n";
        echo '<link>' . htmlspecialchars($siteUrl) . '</link>' . "\n";
        echo '<description>' . htmlspecialchars($description) . '</description>' . "\n";
        echo '<language>zh-CN</language>' . "\n";
        echo '<lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
        
        foreach ($articles as $article) {
            echo '<item>' . "\n";
            echo '<title>' . htmlspecialchars($article['title']) . '</title>' . "\n";
            echo '<link>' . htmlspecialchars($article['link']) . '</link>' . "\n";
            echo '<description>' . htmlspecialchars($article['description']) . '</description>' . "\n";
            echo '<author>' . htmlspecialchars($article['author']) . '</author>' . "\n";
            echo '<pubDate>' . date('r', $article['pubDate']) . '</pubDate>' . "\n";
            echo '<guid>' . htmlspecialchars($article['link']) . '</guid>' . "\n";
            echo '</item>' . "\n";
        }
        
        echo '</channel>' . "\n";
        echo '</rss>' . "\n";
        exit;
    }

    /**
     * 返回页面数据 (JSON API)
     */
    public function pageData()
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $pluginOptions = $options->plugin('FriendsRSS');
            
            if (!$pluginOptions->enableFrontend) {
                echo json_encode([
                    'success' => false,
                    'error' => '前台页面已禁用'
                ]);
                exit;
            }
            
            require_once __DIR__ . '/Core.php';
            $core = new FriendsRSS_Core();
            
            // 检查是否强制刷新
            $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';
            
            $articles = $core->getAggregatedArticles($forceRefresh);
            $stats = $core->getStats();
            
            echo json_encode([
                'success' => true,
                'articles' => $articles,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * 显示前台页面
     */
    public function page()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('FriendsRSS');
        
        if (!$pluginOptions->enableFrontend) {
            throw new Typecho_Widget_Exception('前台页面已禁用', 404);
        }
        
        require_once __DIR__ . '/Core.php';
        $core = new FriendsRSS_Core();
        $articles = $core->getAggregatedArticles(false); // 不强制刷新，只读取缓存
        $stats = $core->getStats();
        
        // 创建模拟的$this对象供模板使用
        $templateThis = new stdClass();
        $templateThis->options = $options;
        
        include_once __DIR__ . '/template/page.php';
        exit;
    }

    /**
     * 清除缓存
     */
    public function clearCache()
    {
        // 检查权限
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('administrator')) {
            throw new Typecho_Widget_Exception('权限不足', 403);
        }
        
        require_once __DIR__ . '/Core.php';
        $core = new FriendsRSS_Core();
        $result = $core->clearCache();
        
        $this->response->goBack();
    }

    /**
     * RSS检测API
     */
    public function detectRSS()
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // 检查权限
            $user = Typecho_Widget::widget('Widget_User');
            if (!$user->hasLogin() || !$user->pass('administrator')) {
                echo json_encode([
                    'success' => false,
                    'error' => '权限不足'
                ]);
                exit;
            }

            require_once __DIR__ . '/Core.php';
            $core = new FriendsRSS_Core();
            
            // 获取友链列表
            $links = $core->getFriendLinks();
            
            if (empty($links)) {
                echo json_encode([
                    'success' => false,
                    'error' => '没有找到友链'
                ]);
                exit;
            }

            // 批量检测RSS
            $results = $core->batchDetectRSS($links);
            
            echo json_encode([
                'success' => true,
                'results' => $results,
                'total' => count($links),
                'detected' => array_sum(array_column($results, 'success'))
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * 刷新数据API
     */
    public function refreshData()
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            require_once __DIR__ . '/Core.php';
            $core = new FriendsRSS_Core();
            
            // 强制刷新聚合文章
            $articles = $core->getAggregatedArticles(true);
            $stats = $core->getStats();
            
            echo json_encode([
                'success' => true,
                'articles' => $articles,
                'stats' => $stats,
                'timestamp' => time()
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * 获取统计信息API
     */
    public function getStats()
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            require_once __DIR__ . '/Core.php';
            $core = new FriendsRSS_Core();
            
            $stats = $core->getStats();
            $cacheStats = $core->getCacheStats();
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'cache' => $cacheStats
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * 定时任务处理器
     */
    public function cronTask()
    {
        // 检查是否有定时任务密钥（可选的安全验证）
        $secret = isset($_GET['secret']) ? $_GET['secret'] : '';
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('FriendsRSS');
        
        // 如果设置了密钥，需要验证
        if ($secret && $secret !== md5($options->siteUrl . 'friends_rss_cron')) {
            http_response_code(403);
            exit('Access Denied');
        }
        
        // 检查定时解析间隔设置
        $interval = intval($pluginOptions->autoRefreshInterval);
        if ($interval <= 0) {
            exit('Cron disabled');
        }
        
        // 检查是否需要执行（基于上次执行时间）
        $cronFile = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/cron_status.json';
        $shouldRun = true;
        
        if (file_exists($cronFile)) {
            $cronData = json_decode(file_get_contents($cronFile), true);
            if ($cronData && isset($cronData['last_run'])) {
                $nextRunTime = $cronData['last_run'] + ($interval * 3600);
                if (time() < $nextRunTime) {
                    $shouldRun = false;
                }
            }
        }
        
        if (!$shouldRun) {
            exit('Not time to run yet');
        }
        
        try {
            require_once __DIR__ . '/Core.php';
            $core = new FriendsRSS_Core();
            
            // 执行RSS聚合
            $articles = $core->getAggregatedArticles(true);
            
            // 更新定时任务状态
            $cronData = array(
                'last_run' => time(),
                'articles_count' => count($articles),
                'status' => 'success',
                'next_run' => time() + ($interval * 3600)
            );
            file_put_contents($cronFile, json_encode($cronData, JSON_PRETTY_PRINT), LOCK_EX);
            
            // 记录日志
            $logMessage = "定时任务执行成功，获取到 " . count($articles) . " 篇文章";
            $core->log($logMessage, 'CRON');
            
            echo "Cron executed successfully: " . count($articles) . " articles";
        } catch (Exception $e) {
            // 记录错误状态
            $cronData = array(
                'last_run' => time(),
                'status' => 'error',
                'error' => $e->getMessage(),
                'next_run' => time() + ($interval * 3600)
            );
            file_put_contents($cronFile, json_encode($cronData, JSON_PRETTY_PRINT), LOCK_EX);
            
            http_response_code(500);
            echo "Cron error: " . $e->getMessage();
        }
        exit;
    }
}