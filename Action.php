<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 友链RSS Action 处理器
 *
 * 兼容 Typecho 1.2.x / 1.3.0（需实现 ActionInterface / Widget_Interface_Do）
 */

namespace TypechoPlugin\FriendsRSS;

use Exception;
use stdClass;

class Action extends \Typecho_Widget implements \Widget_Interface_Do
{
    /**
     * Action 入口（Typecho 1.2/1.3 均通过此方法调度）
     */
    public function action()
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
     * 兼容旧逻辑：部分环境会在构造后调用 execute()
     */
    public function execute()
    {
        // 避免与 Widget\Action 再次调用 action() 时重复执行
        // 仅在直接以 Widget 方式实例化时作为入口
    }

    /**
     * 输出RSS
     */
    public function rss()
    {
        require_once __DIR__ . '/Core.php';
        $core = class_exists(__NAMESPACE__ . '\\Core', false)
            ? new Core()
            : new \FriendsRSS_Core();
        $articles = $core->getAggregatedArticles(false);

        header('Content-Type: application/rss+xml; charset=utf-8');

        $options = \Typecho_Widget::widget('Widget_Options');
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
            $options = \Typecho_Widget::widget('Widget_Options');
            $pluginOptions = $options->plugin('FriendsRSS');

            if (!$pluginOptions->enableFrontend) {
                echo json_encode([
                    'success' => false,
                    'error' => '前台页面已禁用'
                ]);
                exit;
            }

            require_once __DIR__ . '/Core.php';
            $core = new \FriendsRSS_Core();

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
        $options = \Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('FriendsRSS');

        if (!$pluginOptions->enableFrontend) {
            throw new \Typecho_Widget_Exception('前台页面已禁用', 404);
        }

        require_once __DIR__ . '/Core.php';
        $core = new \FriendsRSS_Core();
        $articles = $core->getAggregatedArticles(false);
        $stats = $core->getStats();

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
        $user = \Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('administrator', true)) {
            throw new \Typecho_Widget_Exception('权限不足', 403);
        }

        require_once __DIR__ . '/Core.php';
        $core = new \FriendsRSS_Core();
        $core->clearCache();

        $this->response->goBack();
    }

    /**
     * RSS检测API
     */
    public function detectRSS()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $user = \Typecho_Widget::widget('Widget_User');
            if (!$user->hasLogin() || !$user->pass('administrator', true)) {
                echo json_encode([
                    'success' => false,
                    'error' => '权限不足'
                ]);
                exit;
            }

            require_once __DIR__ . '/Core.php';
            $core = new \FriendsRSS_Core();

            $links = $core->getFriendLinks();

            if (empty($links)) {
                echo json_encode([
                    'success' => false,
                    'error' => '没有找到友链'
                ]);
                exit;
            }

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
            $core = new \FriendsRSS_Core();

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
            $core = new \FriendsRSS_Core();

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
     * 定时任务处理器（检测 + 解析）
     */
    public function cronTask()
    {
        ignore_user_abort(true);
        @set_time_limit(600);

        $secret = isset($_GET['secret']) ? $_GET['secret'] : '';
        $options = \Typecho_Widget::widget('Widget_Options');

        if ($secret && $secret !== md5($options->siteUrl . 'friends_rss_cron')) {
            http_response_code(403);
            exit('Access Denied');
        }

        try {
            require_once __DIR__ . '/Core.php';
            $core = new \FriendsRSS_Core();
            $result = $core->runScheduledTasks(false);
            echo implode('; ', $result['messages']);
        } catch (Exception $e) {
            http_response_code(500);
            echo 'Cron error: ' . $e->getMessage();
        }
        exit;
    }
}

// 兼容旧类名（1.2 插件句柄 / 手动引用）
if (!class_exists('FriendsRSS_Action', false)) {
    class_alias(__NAMESPACE__ . '\\Action', 'FriendsRSS_Action');
}
