<?php

/**
 * FriendsRSS 定时任务脚本
 */

// 定义Typecho根目录
define('__TYPECHO_ROOT_DIR__', dirname(dirname(dirname(__DIR__))));

// 检查是否在命令行或HTTP环境中
$isCli = php_sapi_name() === 'cli';

// 引入Typecho核心
require_once __TYPECHO_ROOT_DIR__ . '/config.inc.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Widget.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Options.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Db.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Plugin.php';

// 初始化Typecho
Typecho_Common::init();

// 引入插件核心
require_once __DIR__ . '/Core.php';

try {
    $options = Typecho_Widget::widget('Widget_Options');
    $pluginOptions = $options->plugin('FriendsRSS');

    // 检查定时解析间隔设置
    $interval = intval($pluginOptions->autoRefreshInterval);
    if ($interval <= 0) {
        if ($isCli) {
            echo "Cron disabled (interval: 0)\n";
        } else {
            echo "Cron disabled (interval: 0)";
        }
        exit(0);
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
                $nextRun = date('Y-m-d H:i:s', $nextRunTime);
            }
        }
    }

    if (!$shouldRun) {
        if ($isCli) {
            echo "Not time to run yet. Next run: $nextRun\n";
        } else {
            echo "Not time to run yet. Next run: $nextRun";
        }
        exit(0);
    }

    // 执行RSS聚合
    $core = new FriendsRSS_Core();
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

    if ($isCli) {
        echo "Cron executed successfully: " . count($articles) . " articles\n";
    } else {
        echo "Cron executed successfully: " . count($articles) . " articles";
    }
} catch (Exception $e) {
    // 记录错误状态
    $cronData = array(
        'last_run' => time(),
        'status' => 'error',
        'error' => $e->getMessage(),
        'next_run' => time() + ($interval * 3600)
    );
    file_put_contents($cronFile, json_encode($cronData, JSON_PRETTY_PRINT), LOCK_EX);

    if ($isCli) {
        echo "Cron error: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo "Cron error: " . $e->getMessage();
    }
    exit(1);
}
