<?php
/**
 * FriendsRSS 定时任务脚本
 *
 * 同时处理：
 * 1. 定时检测 RSS 地址（autoDetectInterval）
 * 2. 定时解析友链文章（autoRefreshInterval）
 *
 * 默认推荐使用插件「访问触发定时」，一般无需配置系统 crontab。
 * 若站点访问很少、希望更准时，可额外配置：
 *   php /path/to/usr/plugins/FriendsRSS/cron.php
 *   curl "https://your-site/action/friends-rss?do=cron"
 */

// 定义Typecho根目录（usr/plugins/FriendsRSS -> 站点根目录）
define('__TYPECHO_ROOT_DIR__', dirname(dirname(dirname(__DIR__))));

// 检查是否在命令行或HTTP环境中
$isCli = php_sapi_name() === 'cli';

ignore_user_abort(true);
@set_time_limit(600);

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
    $core = new FriendsRSS_Core();
    $result = $core->runScheduledTasks(false);

    $output = implode('; ', $result['messages']);
    if ($isCli) {
        echo $output . "\n";
    } else {
        echo $output;
    }

    // 两个任务都未到期 / 都禁用时正常退出
    exit(0);
} catch (Exception $e) {
    // 尽量记录错误，避免下次立刻反复重试时可被状态文件挡住
    try {
        if (isset($core) && $core instanceof FriendsRSS_Core) {
            $core->log('定时任务异常: ' . $e->getMessage(), 'ERROR');
            $parseInterval = $core->getConfiguredInterval('autoRefreshInterval', 6);
            if ($parseInterval > 0) {
                $now = time();
                $core->writeScheduleStatus('cron_status.json', array(
                    'last_run' => $now,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'next_run' => $now + ($parseInterval * 3600)
                ));
            }
        }
    } catch (Exception $ignore) {
        // ignore secondary failures
    }

    if ($isCli) {
        echo "Cron error: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo "Cron error: " . $e->getMessage();
    }
    exit(1);
}
