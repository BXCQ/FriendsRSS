<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 友链RSS聚合核心类 - 重写版
 * 增强RSS检测和拉取功能，完善错误日志系统
 */
class FriendsRSS_Core
{
    private $db;
    private $options;
    private $pluginOptions;
    private $cacheDir;
    private $logFile;

    public function __construct()
    {
        $this->db = Typecho_Db::get();
        $this->options = Typecho_Widget::widget('Widget_Options');
        $this->pluginOptions = $this->options->plugin('FriendsRSS');
        $this->cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/';
        $this->logFile = __DIR__ . '/error.log';

        // 确保缓存目录存在
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        
        // 确保RSS配置文件存在
        $configFile = __DIR__ . '/rss_config.json';
        if (!file_exists($configFile)) {
            @file_put_contents($configFile, '{}', LOCK_EX);
        }
    }

    /**
     * 记录日志
     */
    public function log($message, $level = 'INFO')
    {
        try {
            $timestamp = date('Y-m-d H:i:s');
            if (is_array($message) || is_object($message)) {
                $message = print_r($message, true);
            }
            $logMessage = "[$timestamp] [$level] " . $message . "\n";
            @file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // 静默处理日志写入失败，避免循环错误
        }
    }

    /**
     * 获取友链列表
     */
    public function getFriendLinks()
    {
        $prefix = $this->db->getPrefix();
        $category = $this->pluginOptions->linkCategory ?: '';
        $excludeUrls = $this->getExcludeUrls();

        try {
            // 如果指定了分类，先尝试获取该分类的友链
            if (!empty($category)) {
                $links = $this->db->fetchAll(
                    $this->db->select('name', 'url', 'description')
                        ->from($prefix . 'links')
                        ->where('sort = ?', $category)
                        ->order('order', Typecho_Db::SORT_ASC)
                );
                
                // 如果指定分类有友链，过滤排除的网址后返回
                if (!empty($links)) {
                    $filteredLinks = $this->filterExcludedUrls($links, $excludeUrls);
                    $this->log("成功获取友链列表（分类：{$category}），共 " . count($links) . " 个链接，排除 " . (count($links) - count($filteredLinks)) . " 个");
                    return $filteredLinks;
                }
                
                // 如果指定分类没有友链，记录日志并继续尝试获取所有友链
                $this->log("指定分类 '{$category}' 没有找到友链，尝试获取所有友链");
            }
            
            // 获取所有友链（兼容只有一种友链的主题）
            $links = $this->db->fetchAll(
                $this->db->select('name', 'url', 'description')
                    ->from($prefix . 'links')
                    ->order('order', Typecho_Db::SORT_ASC)
            );

            // 过滤排除的网址
            $filteredLinks = $this->filterExcludedUrls($links, $excludeUrls);
            $this->log("成功获取所有友链列表，共 " . count($links) . " 个链接，排除 " . (count($links) - count($filteredLinks)) . " 个");
            return $filteredLinks ?: array();
        } catch (Exception $e) {
            $this->log("获取友链列表失败: " . $e->getMessage(), 'ERROR');
            return array();
        }
    }

    /**
     * 获取排除的网址列表
     */
    private function getExcludeUrls()
    {
        $excludeUrls = $this->pluginOptions->excludeUrls ?: '';
        if (empty($excludeUrls)) {
            return array();
        }
        
        // 按行分割，去除空行和空白字符
        $urls = array();
        $lines = explode("\n", $excludeUrls);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $urls[] = $line;
            }
        }
        
        return $urls;
    }

    /**
     * 过滤排除的网址
     */
    private function filterExcludedUrls($links, $excludeUrls)
    {
        if (empty($excludeUrls)) {
            return $links;
        }
        
        $filteredLinks = array();
        foreach ($links as $link) {
            $shouldExclude = false;
            foreach ($excludeUrls as $excludeUrl) {
                if (strpos($link['url'], $excludeUrl) !== false) {
                    $shouldExclude = true;
                    $this->log("排除友链: {$link['name']} ({$link['url']}) - 匹配排除规则: {$excludeUrl}");
                    break;
                }
            }
            
            if (!$shouldExclude) {
                $filteredLinks[] = $link;
            }
        }
        
        return $filteredLinks;
    }

    /**
     * 获取可用的友链分类
     */
    public function getAvailableCategories()
    {
        $prefix = $this->db->getPrefix();
        
        try {
            $categories = $this->db->fetchAll(
                $this->db->select('sort')
                    ->from($prefix . 'links')
                    ->group('sort')
                    ->order('sort', Typecho_Db::SORT_ASC)
            );
            
            $result = array();
            foreach ($categories as $cat) {
                if (!empty($cat['sort'])) {
                    $result[] = $cat['sort'];
                }
            }
            
            $this->log("获取到友链分类: " . implode(', ', $result));
            return $result;
        } catch (Exception $e) {
            $this->log("获取友链分类失败: " . $e->getMessage(), 'ERROR');
            return array();
        }
    }

    /**
     * 优化的RSS URL检测（分轮检测策略）
     */
    public function detectRSSUrl($siteUrl, $timeout = 2)
    {
        $siteUrl = rtrim($siteUrl, '/');
        $cacheKey = md5($siteUrl . '_rss_detect');
        $cacheFile = $this->cacheDir . 'rss_' . $cacheKey . '.json';
        $cacheTime = 7 * 86400; // RSS URL缓存7天（RSS地址相对稳定）

        $this->log("开始检测RSS URL: $siteUrl (超时: {$timeout}s)");

        // 检查缓存
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            $cached = @file_get_contents($cacheFile);
            if ($cached) {
                $data = json_decode($cached, true);
                if ($data && isset($data['rssUrl'])) {
                    $this->log("从缓存获取RSS URL: " . ($data['rssUrl'] ?: '未找到'));
                    return $data['rssUrl'] ?: false;
                }
            }
        }

        $detectedUrl = false;

        try {
            // 第一轮：快速检测最常用路径 /feed/ 和 /feed（最高成功率）
            $this->log("第一轮：快速检测最常用路径");
            $detectedUrl = $this->tryFirstRoundPaths($siteUrl, $timeout);

            // 第二轮：如果第一轮失败，尝试HTML解析
            if (!$detectedUrl) {
                $this->log("第二轮：HTML解析检测");
                $detectedUrl = $this->trySecondRoundHTML($siteUrl, $timeout);
            }

            // 第三轮：如果前两轮都失败，尝试其他常见路径
            if (!$detectedUrl) {
                $this->log("第三轮：其他常见路径检测");
                $detectedUrl = $this->tryThirdRoundPaths($siteUrl, $timeout);
            }
        } catch (Exception $e) {
            $this->log("RSS检测过程中发生异常: " . $e->getMessage(), 'ERROR');
        }

        // 缓存结果（包括失败的结果）
        $cacheData = array(
            'rssUrl' => $detectedUrl,
            'lastCheck' => time(),
            'siteUrl' => $siteUrl
        );
        @file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);

        $this->log("RSS检测完成: " . ($detectedUrl ? "成功 - $detectedUrl" : "失败"));
        return $detectedUrl;
    }

    /**
     * 第一轮：快速检测最常用路径（成功率最高）
     */
    private function tryFirstRoundPaths($siteUrl, $timeout)
    {
        $firstRoundPaths = array(
            $siteUrl . '/feed/',
            $siteUrl . '/feed'
        );

        foreach ($firstRoundPaths as $feedUrl) {
            $this->log("第一轮尝试: $feedUrl");
            if ($this->validateRSSContent($feedUrl, $timeout)) {
                $this->log("第一轮成功找到RSS: $feedUrl");
                return $feedUrl;
            }
        }

        return false;
    }

    /**
     * 第二轮：HTML解析检测
     */
    private function trySecondRoundHTML($siteUrl, $timeout)
    {
        $this->log("开始HTML解析检测");
        $htmlContent = $this->fetchContent($siteUrl, $timeout);
        if ($htmlContent) {
            $rssUrl = $this->parseRSSFromHTML($htmlContent, $siteUrl);
            if ($rssUrl && $this->validateRSSContent($rssUrl, $timeout)) {
                $this->log("HTML解析成功找到RSS: $rssUrl");
                return $rssUrl;
            }
        }
        return false;
    }

    /**
     * 第三轮：其他常见路径检测（包含特殊网站类型）
     */
    private function tryThirdRoundPaths($siteUrl, $timeout)
    {
        $thirdRoundPaths = array(
            // 常见XML文件
            $siteUrl . '/rss.xml',
            $siteUrl . '/atom.xml',
            // $siteUrl . '/feed.xml',
            // $siteUrl . '/index.xml',
            // $siteUrl . '/rss',
            // WordPress路径
            // $siteUrl . '/?feed=rss2',
            // $siteUrl . '/index.php?feed=rss2',
            // 博客路径
            // $siteUrl . '/blog/feed',
            // $siteUrl . '/blog/rss.xml',
            // $siteUrl . '/posts/feed',
            // $siteUrl . '/articles/feed',
            // 其他常见路径
            // $siteUrl . '/feeds/all.atom.xml',
            // $siteUrl . '/feeds/posts/default',
            // $siteUrl . '/rss/',
            // $siteUrl . '/atom/',
        );

        foreach ($thirdRoundPaths as $feedUrl) {
            $this->log("第三轮尝试: $feedUrl");
            if ($this->validateRSSContent($feedUrl, $timeout)) {
                $this->log("第三轮成功找到RSS: $feedUrl");
                return $feedUrl;
            }
        }

        return false;
    }

    /**
     * 旧版本兼容：尝试常见的RSS路径（已废弃，使用分轮检测）
     */
    private function tryCommonRSSPaths($siteUrl)
    {
        // 为了向后兼容保留此方法，但实际使用分轮检测
        return $this->tryThirdRoundPaths($siteUrl, 8);
    }



    /**
     * 增强的HTML RSS链接解析
     */
    private function parseRSSFromHTML($html, $baseUrl)
    {
        // 多种RSS/Atom链接模式
        $patterns = array(
            // 标准RSS链接
            '/<link[^>]*type=["\']application\/rss\+xml["\'][^>]*href=["\']([^"\'>]+)["\'][^>]*>/i',
            '/<link[^>]*href=["\']([^"\'>]+)["\'][^>]*type=["\']application\/rss\+xml["\'][^>]*>/i',
            // Atom链接
            '/<link[^>]*type=["\']application\/atom\+xml["\'][^>]*href=["\']([^"\'>]+)["\'][^>]*>/i',
            '/<link[^>]*href=["\']([^"\'>]+)["\'][^>]*type=["\']application\/atom\+xml["\'][^>]*>/i',
            // 其他可能的RSS链接
            '/<link[^>]*rel=["\']alternate["\'][^>]*type=["\']application\/rss\+xml["\'][^>]*href=["\']([^"\'>]+)["\'][^>]*>/i',
            '/<link[^>]*rel=["\']alternate["\'][^>]*href=["\']([^"\'>]+)["\'][^>]*type=["\']application\/rss\+xml["\'][^>]*>/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $rssUrl = trim($matches[1]);

                // 处理相对URL
                if (strpos($rssUrl, 'http') !== 0) {
                    if (strpos($rssUrl, '//') === 0) {
                        $rssUrl = 'https:' . $rssUrl;
                    } elseif (strpos($rssUrl, '/') === 0) {
                        $parsedUrl = parse_url($baseUrl);
                        $rssUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $rssUrl;
                    } else {
                        $rssUrl = rtrim($baseUrl, '/') . '/' . ltrim($rssUrl, '/');
                    }
                }

                $this->log("从HTML解析到RSS链接: $rssUrl");
                return $rssUrl;
            }
        }

        return false;
    }

    /**
     * 验证RSS内容是否有效（支持超时设置）
     */
    private function validateRSSContent($url, $timeout = 10)
    {
        try {
            $content = $this->fetchContent($url, $timeout);
            if (!$content) {
                return false;
            }

            // 检查Content-Type
            $headers = $this->getLastHeaders();
            $contentType = '';
            foreach ($headers as $header) {
                if (stripos($header, 'content-type:') === 0) {
                    $contentType = strtolower($header);
                    break;
                }
            }

            // 检查是否为XML内容类型
            $isXmlContentType = (
                strpos($contentType, 'xml') !== false ||
                strpos($contentType, 'rss') !== false ||
                strpos($contentType, 'atom') !== false
            );

            // 检查内容是否包含RSS/Atom标签
            $hasRssContent = (
                strpos($content, '<rss') !== false ||
                strpos($content, '<feed') !== false ||
                strpos($content, '<channel>') !== false ||
                strpos($content, 'xmlns:atom') !== false ||
                strpos($content, 'xmlns="http://www.w3.org/2005/Atom"') !== false
            );

            $isValid = $isXmlContentType || $hasRssContent;

            if ($isValid) {
                $this->log("RSS验证成功: $url");
            } else {
                $this->log("RSS验证失败: $url - 内容类型: $contentType");
            }

            return $isValid;
        } catch (Exception $e) {
            $this->log("RSS验证异常: $url - " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * 获取最后请求的HTTP响应头
     */
    private $lastHeaders = array();

    private function getLastHeaders()
    {
        return $this->lastHeaders;
    }

    /**
     * 增强的内容获取函数（优化超时处理）
     */
    private function fetchContent($url, $timeout = 2)
    {
        $this->lastHeaders = array();
        $timeout = max(2, intval($timeout)); // 最少2秒超时
        $startTime = microtime(true); // 记录开始时间

        try {
            // 优先使用cURL
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(2, $timeout)); // 连接超时不超过总超时时间
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3); // 减少重定向次数
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; FriendsRSS/2.0; +' . $this->options->siteUrl . ')');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) {
                    $this->lastHeaders[] = trim($header);
                    return strlen($header);
                });

                $content = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    $this->log("cURL错误: $url - $error", 'WARN');
                    return false;
                }

                if ($httpCode >= 200 && $httpCode < 300 && $content !== false) {
                    $actualTime = round(microtime(true) - $startTime, 2); // 计算实际用时
                    $this->log("成功获取内容: $url (HTTP $httpCode, 用时: {$actualTime}s)");
                    return $content;
                } else {
                    $this->log("HTTP错误: $url - 状态码: $httpCode", 'WARN');
                }
            }

            // 备用方案：file_get_contents
            if (function_exists('file_get_contents')) {
                $context = stream_context_create(array(
                    'http' => array(
                        'timeout' => $timeout,
                        'user_agent' => 'Mozilla/5.0 (compatible; FriendsRSS/2.0; +' . $this->options->siteUrl . ')',
                        'follow_location' => 1,
                        'max_redirects' => 3
                    )
                ));

                $content = @file_get_contents($url, false, $context);
                if ($content !== false) {
                    $actualTime = round(microtime(true) - $startTime, 2); // 计算实际用时
                    $this->log("备用方案成功获取内容: $url (用时: {$actualTime}s)");
                    return $content;
                }
            }
        } catch (Exception $e) {
            $this->log("获取内容异常: $url - " . $e->getMessage(), 'WARN');
        }

        $this->log("获取内容失败: $url (超时: {$timeout}s)", 'WARN');
        return false;
    }

    /**
     * 增强的RSS解析功能
     */
    public function parseRSS($rssUrl)
    {
        $this->log("开始解析RSS: $rssUrl");

        try {
            $content = $this->fetchContent($rssUrl, 20);
            if (!$content) {
                $this->log("无法获取RSS内容: $rssUrl", 'WARN');
                return array();
            }

            // 清理和预处理XML内容
            $content = $this->cleanXMLContent($content);

            // 尝试解析XML
            $xml = $this->parseXMLContent($content);
            if (!$xml) {
                $this->log("XML解析失败: $rssUrl", 'ERROR');
                return array();
            }

            $articles = array();
            $maxArticles = intval($this->pluginOptions->articlesPerBlog) ?: 5;

            // RSS 2.0格式解析
            if (isset($xml->channel->item)) {
                $this->log("检测到RSS 2.0格式");
                $articles = $this->parseRSS20($xml, $maxArticles);
            }
            // Atom格式解析
            elseif (isset($xml->entry)) {
                $this->log("检测到Atom格式");
                $articles = $this->parseAtom($xml, $maxArticles);
            }
            // RSS 1.0格式解析
            elseif (isset($xml->item)) {
                $this->log("检测到RSS 1.0格式");
                $articles = $this->parseRSS10($xml, $maxArticles);
            } else {
                $this->log("未识别的RSS格式: $rssUrl", 'WARN');
            }

            $this->log("成功解析RSS: " . $rssUrl . "，获得 " . count($articles) . " 篇文章");
            return $articles;
        } catch (Exception $e) {
            $this->log("RSS解析异常: $rssUrl - " . $e->getMessage(), 'ERROR');
            return array();
        }
    }

    /**
     * 清理XML内容
     */
    private function cleanXMLContent($content)
    {
        // 移除BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // 移除控制字符
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // 修复常见的XML问题
        $content = str_replace('&nbsp;', ' ', $content);
        $content = preg_replace('/&(?!#?[a-zA-Z0-9]+;)/', '&amp;', $content);

        return trim($content);
    }

    /**
     * 解析XML内容
     */
    private function parseXMLContent($content)
    {
        try {
            // 使用内部错误处理
            $previousUseErrors = libxml_use_internal_errors(true);

            // 禁用外部实体加载以提高安全性（仅在PHP 8.0以下版本需要）
            $previousValue = null;
            if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
                $previousValue = libxml_disable_entity_loader(true);
            }

            // 解析XML，使用安全选项
            $xml = simplexml_load_string(
                $content,
                'SimpleXMLElement',
                LIBXML_NOCDATA | LIBXML_NOENT | LIBXML_NONET
            );

            // 恢复设置
            if ($previousValue !== null && function_exists('libxml_disable_entity_loader')) {
                libxml_disable_entity_loader($previousValue);
            }
            libxml_use_internal_errors($previousUseErrors);

            return $xml;
        } catch (Exception $e) {
            $this->log("XML解析错误: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * 解析RSS 2.0格式
     */
    private function parseRSS20($xml, $maxArticles)
    {
        $articles = array();
        $count = 0;

        foreach ($xml->channel->item as $item) {
            if ($count >= $maxArticles) break;

            try {
                $article = $this->parseRSSItem($item);
                if ($article) {
                    $articles[] = $article;
                    $count++;
                }
            } catch (Exception $e) {
                $this->log("解析RSS条目错误: " . $e->getMessage(), 'WARN');
                continue;
            }
        }

        return $articles;
    }

    /**
     * 解析Atom格式
     */
    private function parseAtom($xml, $maxArticles)
    {
        $articles = array();
        $count = 0;

        foreach ($xml->entry as $entry) {
            if ($count >= $maxArticles) break;

            try {
                $article = $this->parseAtomEntry($entry);
                if ($article) {
                    $articles[] = $article;
                    $count++;
                }
            } catch (Exception $e) {
                $this->log("解析Atom条目错误: " . $e->getMessage(), 'WARN');
                continue;
            }
        }

        return $articles;
    }

    /**
     * 解析RSS 1.0格式
     */
    private function parseRSS10($xml, $maxArticles)
    {
        $articles = array();
        $count = 0;

        foreach ($xml->item as $item) {
            if ($count >= $maxArticles) break;

            try {
                $article = $this->parseRSSItem($item);
                if ($article) {
                    $articles[] = $article;
                    $count++;
                }
            } catch (Exception $e) {
                $this->log("解析RSS 1.0条目错误: " . $e->getMessage(), 'WARN');
                continue;
            }
        }

        return $articles;
    }

    /**
     * 解析RSS条目
     */
    private function parseRSSItem($item)
    {
        $title = $this->cleanText((string)$item->title);
        $link = trim((string)$item->link);

        if (empty($title) || empty($link)) {
            return null;
        }

        // 解析发布时间
        $pubDate = $this->parseDate($item);

        // 解析描述
        $description = $this->parseDescription($item);

        // 解析作者
        $author = $this->parseAuthor($item);

        return array(
            'title' => $title,
            'link' => $this->upgradeToHttps($link),
            'description' => $description,
            'pubDate' => $pubDate,
            'author' => $author
        );
    }

    /**
     * 解析Atom条目
     */
    private function parseAtomEntry($entry)
    {
        $title = $this->cleanText((string)$entry->title);

        // 解析链接
        $link = '';
        if (isset($entry->link)) {
            if (isset($entry->link['href'])) {
                $link = (string)$entry->link['href'];
            } else {
                $link = (string)$entry->link;
            }
        }

        if (empty($title) || empty($link)) {
            return null;
        }

        // 解析发布时间
        $pubDate = 0;
        if (isset($entry->published)) {
            $pubDate = strtotime((string)$entry->published);
        } elseif (isset($entry->updated)) {
            $pubDate = strtotime((string)$entry->updated);
        }

        // 解析描述
        $description = '';
        if (isset($entry->summary)) {
            $description = $this->cleanText((string)$entry->summary);
        } elseif (isset($entry->content)) {
            $description = $this->cleanText((string)$entry->content);
        }

        // 解析作者
        $author = '';
        if (isset($entry->author->name)) {
            $author = $this->cleanText((string)$entry->author->name);
        } elseif (isset($entry->author)) {
            $author = $this->cleanText((string)$entry->author);
        }

        return array(
            'title' => $title,
            'link' => $this->upgradeToHttps(trim($link)),
            'description' => $description,
            'pubDate' => $pubDate ?: time(),
            'author' => $author
        );
    }

    /**
     * 解析日期
     */
    private function parseDate($item)
    {
        $dateFields = array('pubDate', 'date', 'published', 'updated');

        foreach ($dateFields as $field) {
            if (isset($item->$field)) {
                $timestamp = strtotime((string)$item->$field);
                if ($timestamp) {
                    return $timestamp;
                }
            }
        }

        // 尝试Dublin Core命名空间
        $dcNamespace = $item->children('http://purl.org/dc/elements/1.1/');
        if (isset($dcNamespace->date)) {
            $timestamp = strtotime((string)$dcNamespace->date);
            if ($timestamp) {
                return $timestamp;
            }
        }

        return time(); // 默认返回当前时间
    }

    /**
     * 解析描述
     */
    private function parseDescription($item)
    {
        $descriptionFields = array('description', 'summary', 'content:encoded', 'content');

        foreach ($descriptionFields as $field) {
            if (isset($item->$field)) {
                $desc = $this->cleanText((string)$item->$field);
                if (!empty($desc)) {
                    return $desc;
                }
            }
        }

        return '';
    }

    /**
     * 解析作者
     */
    private function parseAuthor($item)
    {
        $authorFields = array('author', 'creator', 'managingEditor');

        foreach ($authorFields as $field) {
            if (isset($item->$field)) {
                $author = $this->cleanText((string)$item->$field);
                if (!empty($author)) {
                    return $author;
                }
            }
        }

        // 尝试Dublin Core命名空间
        $dcNamespace = $item->children('http://purl.org/dc/elements/1.1/');
        if (isset($dcNamespace->creator)) {
            $author = $this->cleanText((string)$dcNamespace->creator);
            if (!empty($author)) {
                return $author;
            }
        }

        return '';
    }

    /**
     * 清理文本内容
     */
    private function cleanText($text)
    {
        if (empty($text)) {
            return '';
        }

        // 移除HTML标签
        $text = strip_tags($text);

        // 解码HTML实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 清理空白字符
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // 限制长度
        if (strlen($text) > 500) {
            $text = mb_substr($text, 0, 500, 'UTF-8') . '...';
        }

        return $text;
    }

    /**
     * 升级HTTP链接到HTTPS（如果当前站点使用HTTPS）
     */
    private function upgradeToHttps($url)
    {
        if (empty($url) || !is_string($url)) {
            return $url;
        }

        // 检查当前站点是否使用HTTPS
        $siteUrl = $this->options->siteUrl;
        $isHttpsSite = strpos($siteUrl, 'https://') === 0;

        // 如果当前站点使用HTTPS，将HTTP链接升级为HTTPS
        if ($isHttpsSite && strpos($url, 'http://') === 0) {
            return str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * 批量检测RSS地址（优化版本：分轮检测策略）
     */
    public function batchDetectRSS($links, $progressCallback = null, $forceRefresh = false)
    {
        $this->log("开始批量检测RSS，共 " . count($links) . " 个链接，使用分轮检测策略");

        $results = array();
        $total = count($links);
        $processed = 0;

        // 加载现有RSS配置（带缓存检查）
        $rssConfig = $this->getRSSConfigWithCache($forceRefresh);

        // 分轮检测策略
        $pendingLinks = $links; // 待检测的链接
        $timeout = 2; // 每个地址的超时时间（秒）

        // 第一轮：快速检测 /feed/ 和 /feed
        $this->log("=== 开始第一轮检测：最常用路径 ===");
        $pendingLinks = $this->batchDetectRound($pendingLinks, 1, $timeout, $rssConfig, $results, $processed, $total, $progressCallback);

        // 第二轮：HTML解析检测
        if (!empty($pendingLinks)) {
            $this->log("=== 开始第二轮检测：HTML解析 ===");
            $pendingLinks = $this->batchDetectRound($pendingLinks, 2, $timeout, $rssConfig, $results, $processed, $total, $progressCallback);
        }

        // 第三轮：其他常见路径
        if (!empty($pendingLinks)) {
            $this->log("=== 开始第三轮检测：其他常见路径 ===");
            $this->batchDetectRound($pendingLinks, 3, $timeout, $rssConfig, $results, $processed, $total, $progressCallback);
        }

        // 保存更新后的RSS配置
        $this->saveRSSConfig($rssConfig);

        $successCount = array_sum(array_column($results, 'success'));
        $this->log("批量检测完成，成功: $successCount, 失败: " . ($total - $successCount));

        return $results;
    }

    /**
     * 执行单轮批量检测
     */
    private function batchDetectRound($links, $round, $timeout, &$rssConfig, &$results, &$processed, $total, $progressCallback)
    {
        $remainingLinks = array();

        foreach ($links as $index => $link) {
            try {
                $rssUrl = false;

                switch ($round) {
                    case 1:
                        $rssUrl = $this->tryFirstRoundPaths($link['url'], $timeout);
                        break;
                    case 2:
                        $rssUrl = $this->trySecondRoundHTML($link['url'], $timeout);
                        break;
                    case 3:
                        $rssUrl = $this->tryThirdRoundPaths($link['url'], $timeout);
                        break;
                }

                if ($rssUrl) {
                    // 检测成功
                    $result = array(
                        'name' => $link['name'],
                        'url' => $link['url'],
                        'rssUrl' => $rssUrl,
                        'success' => true,
                        'round' => $round
                    );
                    $results[] = $result;
                    $rssConfig[$link['name']] = $rssUrl;
                    $this->log("第{$round}轮成功 [" . ($processed + 1) . "/$total] " . $link['name'] . ": $rssUrl");
                } else {
                    // 本轮检测失败，加入待检测列表
                    $remainingLinks[] = $link;
                    $this->log("第{$round}轮失败 [" . ($processed + 1) . "/$total] " . $link['name']);
                }
            } catch (Exception $e) {
                $this->log("第{$round}轮检测异常 [" . $link['name'] . "]: " . $e->getMessage(), 'ERROR');
                $remainingLinks[] = $link;
            }

            $processed++;

            // 调用进度回调
            if ($progressCallback && is_callable($progressCallback)) {
                $progressCallback($processed, $total);
            }

            // 短暂延迟，避免服务器压力过大
            usleep(200000); // 0.2秒
        }

        // 为最后一轮的失败链接添加结果记录
        if ($round == 3) {
            foreach ($remainingLinks as $link) {
                $results[] = array(
                    'name' => $link['name'],
                    'url' => $link['url'],
                    'rssUrl' => false,
                    'success' => false,
                    'round' => 'failed'
                );
            }
        }

        return $remainingLinks;
    }

    /**
     * 获取RSS配置
     */
    private function getRSSConfig()
    {
        $configFile = __DIR__ . '/rss_config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            return $config ?: array();
        }
        return array();
    }

    /**
     * 获取RSS配置（带缓存检查）
     */
    private function getRSSConfigWithCache($forceRefresh = false)
    {
        $configFile = __DIR__ . '/rss_config.json';
        $cacheFile = $this->cacheDir . 'rss_config_cache.json';
        
        $this->log("开始获取RSS配置，配置文件: $configFile");
        
        // 根据定时检测间隔设置缓存时间
        $detectInterval = intval($this->pluginOptions->autoDetectInterval) ?: 240; // 默认240小时（10天）
        $cacheTime = $detectInterval * 3600; // 转换为秒
        
        // 检查缓存（如果配置文件比缓存文件新，则强制刷新）
        $configFileTime = file_exists($configFile) ? filemtime($configFile) : 0;
        $cacheFileTime = file_exists($cacheFile) ? filemtime($cacheFile) : 0;
        $configIsNewer = $configFileTime > $cacheFileTime;
        
        if (!$forceRefresh && !$configIsNewer && file_exists($cacheFile) && (time() - $cacheFileTime) < $cacheTime) {
            $cached = @file_get_contents($cacheFile);
            if ($cached) {
                $config = json_decode($cached, true);
                if ($config && is_array($config)) {
                    $this->log("从缓存获取RSS配置，共 " . count($config) . " 个配置");
                    return $config;
                }
            }
        }
        
        if ($configIsNewer) {
            $this->log("检测到配置文件已更新，强制刷新缓存");
        }
        
        // 从主配置文件读取
        if (file_exists($configFile)) {
            $content = @file_get_contents($configFile);
            if ($content === false) {
                $this->log("无法读取RSS配置文件: $configFile", 'ERROR');
                return array();
            }
            
            $config = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("RSS配置文件JSON解析失败: " . json_last_error_msg(), 'ERROR');
                return array();
            }
            
            $config = $config ?: array();
            $this->log("从配置文件获取RSS配置，共 " . count($config) . " 个配置");
            
            // 调试：显示具体的配置内容
            // if (!empty($config)) {
            //     $this->log("RSS配置详情: " . json_encode($config, JSON_UNESCAPED_UNICODE));
            //     if (isset($config['青萍叙事'])) {
            //         $this->log("青萍叙事RSS配置已找到: " . $config['青萍叙事']);
            //     } else {
            //         $this->log("青萍叙事RSS配置未找到");
            //     }
            // }
            
            // 更新缓存
            try {
                if (!is_dir($this->cacheDir)) {
                    @mkdir($this->cacheDir, 0755, true);
                }
                file_put_contents($cacheFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
                $this->log("RSS配置缓存已更新");
            } catch (Exception $e) {
                $this->log("RSS配置缓存更新失败: " . $e->getMessage(), 'ERROR');
            }
            
            return $config;
        } else {
            $this->log("RSS配置文件不存在: $configFile", 'ERROR');
        }
        
        return array();
    }

    /**
     * 保存RSS配置
     */
    private function saveRSSConfig($rssConfig)
    {
        $configFile = __DIR__ . '/rss_config.json';
        try {
            file_put_contents($configFile, json_encode($rssConfig, JSON_PRETTY_PRINT), LOCK_EX);
            $this->log("RSS配置保存成功，共 " . count($rssConfig) . " 个配置");
            return true;
        } catch (Exception $e) {
            $this->log("RSS配置保存失败: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * 获取聚合文章（使用手动配置的RSS地址）
     */
    public function getAggregatedArticles($forceRefresh = false)
    {
        $cacheFile = $this->cacheDir . 'aggregated_articles.json';

        // 根据定时解析间隔设置缓存时间，确保与定时任务同步
        $parseInterval = intval($this->pluginOptions->autoRefreshInterval) ?: 6; // 默认6小时
        $cacheTime = $parseInterval * 3600; // 转换为秒，与定时解析间隔保持一致

        $this->log("获取聚合文章" . ($forceRefresh ? "（强制刷新）" : "") . "，缓存时间: {$parseInterval}小时");

        // 检查缓存
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            $cached = @file_get_contents($cacheFile);
            if ($cached) {
                $articles = json_decode($cached, true);
                if ($articles && is_array($articles)) {
                    $this->log("从缓存获取聚合文章，共 " . count($articles) . " 篇");
                    return $articles;
                }
            }
        }

        // 检查是否有其他聚合任务正在进行
        $lockFile = $this->cacheDir . 'aggregation.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 600) {
            $this->log("检测到聚合任务正在进行，返回旧缓存");
            if (file_exists($cacheFile)) {
                $cached = @file_get_contents($cacheFile);
                if ($cached) {
                    $data = json_decode($cached, true);
                    return is_array($data) ? $data : array();
                }
            }
            return array();
        }

        // 创建锁文件
        @file_put_contents($lockFile, time(), LOCK_EX);

        try {
            $allArticles = array();
            $links = $this->getFriendLinks();
            $rssConfig = $this->getRSSConfigWithCache(true); // 强制刷新缓存

            $this->log("开始聚合文章，共 " . count($links) . " 个友链，已配置RSS: " . count($rssConfig));

            foreach ($links as $index => $link) {
                try {
                    // 获取手动配置的RSS URL
                    $rssUrl = isset($rssConfig[$link['name']]) ? $rssConfig[$link['name']] : '';
                    
                    $this->log("检查友链 [" . ($index + 1) . "/" . count($links) . "] " . $link['name'] . " - 配置状态: " . (isset($rssConfig[$link['name']]) ? '已配置' : '未配置'));
                    
                    if (isset($rssConfig[$link['name']])) {
                        $this->log("友链 '" . $link['name'] . "' 的RSS配置: " . $rssConfig[$link['name']]);
                    }

                    if ($rssUrl && !empty(trim($rssUrl))) {
                        $this->log("解析RSS [" . ($index + 1) . "/" . count($links) . "] " . $link['name'] . " - " . $rssUrl);
                        $articles = $this->parseRSS($rssUrl);

                        foreach ($articles as $article) {
                            $article['blogName'] = $link['name'];
                            $article['blogUrl'] = $this->upgradeToHttps($link['url']);
                            $article['rssUrl'] = $rssUrl;
                            $allArticles[] = $article;
                        }
                    } else {
                        $this->log("跳过未配置RSS的友链: " . $link['name'] . " (RSS URL为空或不存在)");
                    }
                } catch (Exception $e) {
                    $this->log("聚合文章异常 [" . $link['name'] . "]: " . $e->getMessage(), 'ERROR');
                }
            }

            // 按发布时间排序
            usort($allArticles, function ($a, $b) {
                return $b['pubDate'] - $a['pubDate'];
            });

            // 限制文章数量
            $maxArticles = intval($this->pluginOptions->maxArticles) ?: 50;
            $allArticles = array_slice($allArticles, 0, $maxArticles);

            $this->log("聚合完成，共获得 " . count($allArticles) . " 篇文章");

            // 保存缓存
            @file_put_contents($cacheFile, json_encode($allArticles), LOCK_EX);

            return $allArticles;
        } catch (Exception $e) {
            $this->log("聚合文章异常: " . $e->getMessage(), 'ERROR');
            return array();
        } finally {
            // 删除锁文件
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    /**
     * 获取统计信息
     */
    public function getStats()
    {
        try {
            $links = $this->getFriendLinks();
            $articles = $this->getAggregatedArticles();
            $rssConfig = $this->getRSSConfig();

            $blogCount = count($links);
            $articleCount = count($articles);
            $lastUpdate = 0;

            // 获取最后更新时间（聚合操作的时间，不是文章发布时间）
            $cacheFile = $this->cacheDir . 'aggregated_articles.json';
            if (file_exists($cacheFile)) {
                $lastUpdate = filemtime($cacheFile);
            }

            return array(
                'blogCount' => $blogCount,
                'articleCount' => $articleCount,
                'lastUpdate' => $lastUpdate,
                'configuredRss' => count($rssConfig)
            );
        } catch (Exception $e) {
            $this->log("获取统计信息异常: " . $e->getMessage(), 'ERROR');
            return array(
                'blogCount' => 0,
                'articleCount' => 0,
                'lastUpdate' => 0,
                'configuredRss' => 0
            );
        }
    }

    /**
     * 清除缓存（优化版本）
     */
    public function clearCache($progressCallback = null)
    {
        $this->log("开始清除缓存");

        try {
            $files = glob($this->cacheDir . '*.{json,lock}', GLOB_BRACE);
            $total = count($files);
            $cleared = 0;

            if ($total == 0) {
                $this->log("没有缓存文件需要清除");
                return 0;
            }

            foreach ($files as $index => $file) {
                if (file_exists($file) && @unlink($file)) {
                    $cleared++;
                }

                // 调用进度回调
                if ($progressCallback && is_callable($progressCallback)) {
                    $progressCallback($index + 1, $total, $cleared);
                }
            }

            $this->log("缓存清除完成，清除了 $cleared/$total 个文件");
            return $cleared;
        } catch (Exception $e) {
            $this->log("清除缓存异常: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }

    /**
     * 获取缓存文件统计
     */
    public function getCacheStats()
    {
        try {
            $files = glob($this->cacheDir . '*.json');
            $totalSize = 0;
            $oldestTime = time();
            $newestTime = 0;

            foreach ($files as $file) {
                $size = filesize($file);
                $mtime = filemtime($file);

                $totalSize += $size;
                if ($mtime < $oldestTime) $oldestTime = $mtime;
                if ($mtime > $newestTime) $newestTime = $mtime;
            }

            return array(
                'fileCount' => count($files),
                'totalSize' => $totalSize,
                'oldestTime' => count($files) > 0 ? $oldestTime : 0,
                'newestTime' => count($files) > 0 ? $newestTime : 0
            );
        } catch (Exception $e) {
            $this->log("获取缓存统计异常: " . $e->getMessage(), 'ERROR');
            return array(
                'fileCount' => 0,
                'totalSize' => 0,
                'oldestTime' => 0,
                'newestTime' => 0
            );
        }
    }
}
