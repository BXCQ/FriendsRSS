<?php
// 清除任何之前的输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 开启新的输出缓冲
ob_start();

// 开启错误报告但不显示，记录到日志
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 日志记录函数
function frss_log($message)
{
    try {
        $logFile = __DIR__ . '/error.log';
        $timestamp = date('Y-m-d H:i:s');
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        $logMessage = "[$timestamp] " . $message . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    } catch (Exception $e) {
        // 如果日志写入失败，不做任何事，避免循环错误
    }
}

// 设置错误处理函数
set_error_handler(function ($severity, $message, $file, $line) {
    frss_log("PHP Error: [$severity] $message in $file on line $line");
    // 清除输出缓冲并返回JSON错误
    if (!headers_sent()) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => "PHP Error: $message in $file on line $line"]);
    }
    exit;
});

// 设置异常处理函数
set_exception_handler(function ($exception) {
    frss_log("Uncaught Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
    // 清除输出缓冲并返回JSON错误
    if (!headers_sent()) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Uncaught Exception: ' . $exception->getMessage()]);
    }
    exit;
});

// 默认设置JSON响应头（SSE操作会单独设置）
if (!isset($_GET['action']) || !in_array($_GET['action'], ['batch_detect_rss', 'clear_cache_progress'])) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    frss_log("开始初始化Ajax处理");

    if (!defined('__TYPECHO_ROOT_DIR__')) {
        include __DIR__ . '/../../../config.inc.php';
    }

    // 确保缓冲区清理
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    frss_log("配置文件加载完成，开始初始化Widget");

    // 确保加载Typecho核心文件
    if (!class_exists('Typecho_Widget')) {
        // 如果没有加载，尝试加载admin环境
        if (file_exists(__TYPECHO_ROOT_DIR__ . '/admin/common.php')) {
            require_once __TYPECHO_ROOT_DIR__ . '/admin/common.php';
        } else {
            // 备用方案：直接加载必要的类文件
            require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Widget.php';
        }
    }

    // 使用与其他文件一致的旧API
    $options = Typecho_Widget::widget('Widget_Options');
    $user = Typecho_Widget::widget('Widget_User');

    frss_log("Widget初始化完成，检查用户权限");

    // 检查用户权限
    if (!$user->pass('administrator')) {
        frss_log("用户权限检查失败");
        ob_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '禁止访问']);
        exit;
    }

    frss_log("用户权限检查通过，加载核心类");

    // 引入核心类
    require_once __DIR__ . '/Core.php';
    $core = new FriendsRSS_Core();

    frss_log("核心类加载完成，准备处理操作");
} catch (Exception $e) {
    frss_log('初始化失败: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => '初始化失败: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    frss_log('系统错误: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'error' => '系统错误: ' . $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'detect_single_rss':
        try {
            $url = trim($_POST['url'] ?? '');
            if (empty($url)) {
                echo json_encode(['success' => false, 'error' => 'URL不能为空']);
                exit;
            }

            $rssUrl = $core->detectRSSUrl($url);
            if ($rssUrl) {
                echo json_encode([
                    'success' => true,
                    'rssUrl' => $rssUrl,
                    'message' => '检测到RSS地址: ' . $rssUrl
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => '未检测到RSS地址'
                ]);
            }
        } catch (Exception $e) {
            frss_log('检测单个RSS失败: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '检测失败: ' . $e->getMessage()
            ]);
        } catch (Error $e) {
            frss_log('检测单个RSS系统错误: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '系统错误: ' . $e->getMessage()
            ]);
        }
        exit;

    case 'batch_detect_simple':
        try {
            // 获取友链列表
            $links = $core->getFriendLinks();
            if (empty($links)) {
                echo json_encode(['success' => false, 'error' => '没有友链需要检测']);
                exit;
            }

            // 设置长时间执行
            set_time_limit(0);
            ignore_user_abort(false);

            // 批量检测RSS
            $results = $core->batchDetectRSS($links);

            $detected = 0;
            $failed = 0;

            foreach ($results as $result) {
                if ($result['success'] && $result['rssUrl']) {
                    $detected++;
                } else {
                    $failed++;
                }
            }

            // 清除输出缓冲并输出结果
            ob_clean();
            echo json_encode([
                'success' => true,
                'detected' => $detected,
                'failed' => $failed,
                'total' => count($links)
            ]);
        } catch (Exception $e) {
            frss_log('简单批量检测失败: ' . $e->getMessage());
            ob_clean();
            echo json_encode([
                'success' => false,
                'error' => '检测失败: ' . $e->getMessage()
            ]);
        } catch (Error $e) {
            frss_log('简单批量检测系统错误: ' . $e->getMessage());
            ob_clean();
            echo json_encode([
                'success' => false,
                'error' => '系统错误: ' . $e->getMessage()
            ]);
        }
        exit;

    case 'batch_detect_rss':
        try {
            // 设置SSE响应头
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            // 获取友链列表
            $links = $core->getFriendLinks();
            if (empty($links)) {
                echo "data: " . json_encode(['type' => 'complete', 'total' => 0, 'detected' => 0, 'failed' => 0, 'results' => []]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
                exit;
            }

            // 设置长时间执行
            set_time_limit(0);
            ignore_user_abort(false);

            // 清除输出缓冲
            if (ob_get_level()) ob_end_clean();

            // 发送开始信号
            echo "data: " . json_encode(['type' => 'start', 'total' => count($links)]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();

            // 开始批量检测
            $results = [];
            $total = count($links);
            $processed = 0;

            foreach ($links as $index => $link) {
                // 发送单个链接开始检测信号
                echo "data: " . json_encode([
                    'type' => 'link_start',
                    'linkIndex' => $index,
                    'linkName' => $link['name'],
                    'linkUrl' => $link['url']
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();

                // 检测RSS
                $rssUrl = $core->detectRSSUrl($link['url']);
                $hasRSS = $rssUrl !== false;

                $results[] = [
                    'name' => $link['name'],
                    'url' => $link['url'],
                    'rssUrl' => $rssUrl,
                    'success' => $hasRSS
                ];

                $processed++;

                // 发送单个链接完成信号
                echo "data: " . json_encode([
                    'type' => 'link_complete',
                    'linkIndex' => $index,
                    'hasRSS' => $hasRSS,
                    'rssUrl' => $rssUrl
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();

                // 发送整体进度更新
                echo "data: " . json_encode([
                    'type' => 'progress',
                    'processed' => $processed,
                    'total' => $total,
                    'percentage' => round(($processed / $total) * 100, 1)
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();

                // 短暂延迟避免过度占用资源
                usleep(100000); // 0.1秒
            }

            // 发送最终结果
            $successCount = array_reduce($results, function ($count, $result) {
                return $count + ($result['success'] ? 1 : 0);
            }, 0);

            $finalResult = [
                'type' => 'complete',
                'success' => true,
                'total' => count($links),
                'detected' => $successCount,
                'failed' => count($links) - $successCount,
                'results' => $results
            ];

            echo "data: " . json_encode($finalResult) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        } catch (Exception $e) {
            frss_log('SSE批量检测失败: ' . $e->getMessage());
            echo "data: " . json_encode([
                'type' => 'error',
                'success' => false,
                'error' => '批量检测失败: ' . $e->getMessage()
            ]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
        exit;

    case 'get_friend_links':
        try {
            $links = $core->getFriendLinks();
            echo json_encode([
                'success' => true,
                'links' => $links
            ]);
        } catch (Exception $e) {
            frss_log('获取友链失败: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '获取友链失败: ' . $e->getMessage()
            ]);
        } catch (Error $e) {
            frss_log('获取友链系统错误: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '系统错误: ' . $e->getMessage()
            ]);
        }
        exit;

    case 'clear_cache_simple':
        try {
            $cleared = $core->clearCache();
            echo json_encode([
                'success' => true,
                'cleared' => $cleared,
                'message' => "缓存清除完成！清除了 {$cleared} 个文件"
            ]);
        } catch (Exception $e) {
            frss_log('简单清除缓存失败: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '清除失败: ' . $e->getMessage()
            ]);
        } catch (Error $e) {
            frss_log('简单清除缓存系统错误: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '系统错误: ' . $e->getMessage()
            ]);
        }
        exit;

    case 'clear_cache_progress':
        try {
            // 设置SSE响应头
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            // 设置长时间执行
            set_time_limit(0);
            ignore_user_abort(false);

            // 清除输出缓冲
            if (ob_get_level()) ob_end_clean();

            // 开始清除缓存
            $cleared = $core->clearCache(function ($processed, $total, $cleared) {
                $progress = [
                    'type' => 'progress',
                    'processed' => $processed,
                    'total' => $total,
                    'cleared' => $cleared,
                    'percentage' => round(($processed / $total) * 100, 1)
                ];
                echo "data: " . json_encode($progress) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            });

            // 发送完成信息
            echo "data: " . json_encode([
                'type' => 'complete',
                'success' => true,
                'cleared' => $cleared,
                'message' => "已清除 {$cleared} 个缓存文件"
            ]) . "\n\n";

            if (ob_get_level()) ob_flush();
            flush();
        } catch (Exception $e) {
            frss_log('SSE清除缓存失败: ' . $e->getMessage());
            echo "data: " . json_encode([
                'type' => 'error',
                'success' => false,
                'error' => '清除缓存失败: ' . $e->getMessage()
            ]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
        exit;

    case 'get_cache_stats':
        try {
            $stats = $core->getCacheStats();
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (Exception $e) {
            frss_log('获取缓存统计失败: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => '获取缓存统计失败: ' . $e->getMessage()
            ]);
        }
        exit;

    case 'refresh_aggregation':
        try {
            // 强制刷新聚合数据
            $articles = $core->getAggregatedArticles(true);
            echo json_encode([
                'success' => true,
                'count' => count($articles),
                'message' => '已刷新聚合数据，获取到 ' . count($articles) . ' 篇文章'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => '刷新聚合数据失败: ' . $e->getMessage()
            ]);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'error' => '未知操作: ' . $action]);
        break;
}
