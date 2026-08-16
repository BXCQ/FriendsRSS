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
 *
 * 兼容 Typecho 1.2.x / 1.3.0（仅依赖 config.inc.php + 自动加载）
 */

// 定义Typecho根目录（usr/plugins/FriendsRSS -> 站点根目录）
define('__TYPECHO_ROOT_DIR__', dirname(dirname(dirname(__DIR__))));

$isCli = php_sapi_name() === 'cli';

ignore_user_abort(true);
@set_time_limit(600);

// 仅引入站点配置，由 Typecho 自动加载器处理其余类（兼容 1.2 / 1.3）
require_once __TYPECHO_ROOT_DIR__ . '/config.inc.php';

if (class_exists('Typecho_Common', false) || class_exists('\\Typecho\\Common', false)) {
    if (method_exists('Typecho_Common', 'init')) {
        Typecho_Common::init();
    } elseif (method_exists('\\Typecho\\Common', 'init')) {
        \Typecho\Common::init();
    }
}

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

    exit(0);
} catch (Exception $e) {
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
        echo 'Cron error: ' . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo 'Cron error: ' . $e->getMessage();
    }
    exit(1);
}
