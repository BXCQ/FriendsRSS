<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 友链RSS聚合插件
 * 
 * 自动获取友链博客的最新文章，生成RSS聚合feed，支持前台展示和RSS订阅。
 * 兼容 Typecho 1.2.1 和 PHP 8.0
 * 
 * @package FriendsRSS
 * @author 璇
 * @version 2.2.1
 * @link https://blog.ybyq.wang/
 */

class FriendsRSS_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     *
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 检查PHP版本
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            throw new Typecho_Plugin_Exception('友链RSS插件需要PHP 7.0或更高版本');
        }

        // 检查必要的PHP扩展
        if (!extension_loaded('curl') && !function_exists('file_get_contents')) {
            throw new Typecho_Plugin_Exception('友链RSS插件需要cURL扩展或file_get_contents函数');
        }

        if (!extension_loaded('simplexml')) {
            throw new Typecho_Plugin_Exception('友链RSS插件需要SimpleXML扩展');
        }

        // 创建缓存目录
        $cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/friends_rss/';
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                throw new Typecho_Plugin_Exception('无法创建缓存目录：' . $cacheDir);
            }
        }

        // 添加管理面板
        Typecho_Plugin::factory('admin/menu.php')->navBar = array('FriendsRSS_Plugin', 'render');
        Helper::addPanel(3, 'FriendsRSS/admin.php', '友链RSS', '友链RSS聚合管理', 'administrator');

        // 添加Action处理器
        Helper::addAction('friends-rss', 'FriendsRSS_Action');

        // 注册短代码处理器
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('FriendsRSS_Plugin', 'parseShortcode');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('FriendsRSS_Plugin', 'parseShortcode');

        return '友链RSS插件激活成功！';
    }

    /**
     * 禁用插件方法
     *
     * @access public
     * @return void
     */
    public static function deactivate()
    {
        Helper::removePanel(3, 'FriendsRSS/admin.php');
        Helper::removeAction('friends-rss');
    }

    /**
     * 渲染菜单
     */
    public static function render()
    {
        // 菜单渲染逻辑（如果需要）
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $maxArticles = new Typecho_Widget_Helper_Form_Element_Text(
            'maxArticles',
            null,
            '20',
            _t('最大文章数'),
            _t('RSS聚合显示的最大文章数量，默认20篇')
        );
        $form->addInput($maxArticles);

        $articlesPerBlog = new Typecho_Widget_Helper_Form_Element_Text(
            'articlesPerBlog',
            null,
            '3',
            _t('每个博客文章数'),
            _t('每个友链博客获取的最大文章数量，默认3篇')
        );
        $form->addInput($articlesPerBlog);

        $enableFrontend = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableFrontend',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('启用前台页面'),
            _t('是否启用前台友链RSS展示页面')
        );
        $form->addInput($enableFrontend);

        $linkCategory = new Typecho_Widget_Helper_Form_Element_Text(
            'linkCategory',
            null,
            '',
            _t('友链分类'),
            _t('要聚合的友链分类，留空表示获取所有友链。<br/>• Handsome主题：填写"ten"获取全站链接，填写"one"获取内页链接，填写"good"获取推荐链接<br/>• 其他主题：通常留空即可，插件会自动获取所有友链')
        );
        $form->addInput($linkCategory);

        $excludeUrls = new Typecho_Widget_Helper_Form_Element_Textarea(
            'excludeUrls',
            null,
            '',
            _t('排除检测的博客网址'),
            _t('每行一个网址，支持部分匹配。例如：<br/>• example.com - 排除所有包含example.com的友链<br/>• https://blog.example.com - 排除特定博客<br/>• 留空表示不排除任何友链')
        );
        $form->addInput($excludeUrls);

        $autoRefreshInterval = new Typecho_Widget_Helper_Form_Element_Text(
            'autoRefreshInterval',
            null,
            '6',
            _t('定时解析间隔'),
            _t('定时解析友链RSS的间隔时间（小时），默认6小时。设置为0表示禁用定时解析。文章缓存时间会自动与定时解析间隔保持一致。')
        );
        $form->addInput($autoRefreshInterval);

        $autoDetectInterval = new Typecho_Widget_Helper_Form_Element_Text(
            'autoDetectInterval',
            null,
            '240',
            _t('定时检测RSS间隔'),
            _t('定时检测友链RSS地址的间隔时间（小时），默认240小时（10天）。设置为0表示禁用定时检测。RSS地址缓存时间会自动与定时检测间隔保持一致。')
        );
        $form->addInput($autoDetectInterval);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人配置暂时为空
    }

    /**
     * 检查友链表是否存在
     *
     * @return bool
     */
    public static function checkLinksTable()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        try {
            $db->fetchRow($db->select()->from($prefix . 'links')->limit(1));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取插件版本
     *
     * @return string
     */
    public static function getVersion()
    {
        return '1.0.0';
    }

    /**
     * 获取插件信息
     *
     * @return array
     */
    public static function getInfo()
    {
        return array(
            'name' => '友链RSS聚合',
            'version' => self::getVersion(),
            'author' => '璇',
            'description' => '自动获取友链博客的最新文章，生成RSS聚合feed',
            'homepage' => 'https://blog.ybyq.wang/'
        );
    }

    /**
     * 解析短代码
     *
     * @param string $content 内容
     * @param Widget $widget  Widget对象
     * @param string $lastResult 最后结果
     * @return string
     */
    public static function parseShortcode($content, $widget, $lastResult)
    {
        if (strpos($content, '[rss') === false) {
            return $content;
        }

        return preg_replace_callback('/\[rss(?:\s+([^\]]*))?\]/', function ($matches) {
            // 解析参数
            $attributes = array();
            if (isset($matches[1])) {
                preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $matches[1], $attrMatches, PREG_SET_ORDER);
                foreach ($attrMatches as $attr) {
                    $attributes[$attr[1]] = $attr[2];
                }
            }

            // 设置默认参数，简化配置
            $defaults = array(
                'limit' => '10'
            );
            $attributes = array_merge($defaults, $attributes);

            return self::renderRSSShortcode($attributes);
        }, $content);
    }

    /**
     * 渲染RSS短代码
     *
     * @param array $attributes 属性
     * @return string
     */
    public static function renderRSSShortcode($attributes)
    {
        try {
            // 引入核心类
            require_once 'Core.php';
            $core = new FriendsRSS_Core();

            // 获取RSS文章
            $articles = $core->getAggregatedArticles(false); // 不强制刷新，只读取缓存

            if (empty($articles)) {
                return '<div class="friends-rss-empty">暂无友链文章</div>';
            }

            // 应用限制
            $limit = intval($attributes['limit']);
            if ($limit > 0) {
                $articles = array_slice($articles, 0, $limit);
            }

            // 渲染文章列表
            return self::renderBlockStyle($articles, $attributes);
        } catch (Exception $e) {
            return '<div class="friends-rss-error">获取友链RSS失败: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    /**
     * 渲染块状样式文章列表
     *
     * @param array $articles 文章数组
     * @param array $attributes 属性
     * @return string
     */
    private static function renderBlockStyle($articles, $attributes)
    {
        $html = '<div class="friends-rss-container">';

        foreach ($articles as $article) {
            $html .= '<div class="friends-rss-block">';

            // 文章标题
            $html .= '<h3 class="article-title">';
            $html .= '<a href="' . htmlspecialchars($article['link']) . '" target="_blank" rel="noopener">';
            $html .= htmlspecialchars($article['title']);
            $html .= '</a>';
            $html .= '</h3>';

            // 文章信息（博客名称、作者、时间）
            $html .= '<div class="article-meta">';
            $html .= '<span class="blog-name">' . htmlspecialchars($article['blogName']) . '</span>';
            if (!empty($article['author'])) {
                $html .= ' <span class="meta-separator">·</span> ';
                $html .= '<span class="article-author">' . htmlspecialchars($article['author']) . '</span>';
            }
            $html .= ' <span class="meta-separator">·</span> ';
            $html .= '<span class="article-time">' . self::timeAgo($article['pubDate']) . '</span>';
            $html .= '</div>';

            // 文章摘要
            if (!empty($article['description'])) {
                $description = strip_tags($article['description']);
                $description = mb_substr($description, 0, 200, 'UTF-8');
                if (mb_strlen($description, 'UTF-8') >= 200) {
                    $description .= '...';
                }
                $html .= '<div class="article-excerpt">' . htmlspecialchars($description) . '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        // 添加CSS样式
        $html .= self::getShortcodeCSS();

        return $html;
    }







    /**
     * 时间格式化函数
     */
    private static function timeAgo($timestamp)
    {
        // 如果传入的是字符串，转换为时间戳
        if (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' 分钟前';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' 小时前';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . ' 天前';
        } else {
            return date('Y-m-d', $timestamp);
        }
    }

    /**
     * 获取短代码CSS样式
     */
    private static function getShortcodeCSS()
    {
        static $cssIncluded = false;
        if ($cssIncluded) return '';
        $cssIncluded = true;

        return '<style>
/* Friends RSS 短代码样式 */
.friends-rss-container { margin: 20px 0; }
.friends-rss-empty, .friends-rss-error { 
    padding: 15px; 
    text-align: center; 
    color: #666; 
    background: #f5f5f5; 
    border-radius: 8px; 
}

/* 块状文章样式 */
.friends-rss-block { 
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    transition: box-shadow 0.2s ease;
}
.friends-rss-block:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.article-meta {
    font-size: 0.9em;
    color: #666;
    margin-bottom: 12px;
}

.blog-name {
    font-weight: 600;
    color: #007cba;
}

.meta-separator {
    color: #999;
    margin: 0 4px;
}

.article-author {
    color: #666;
}

.article-time {
    color: #888;
}

.article-title { 
    margin: 0 0 12px 0; 
    font-size: 1.2em; 
    line-height: 1.4; 
    font-weight: 600;
}
.article-title a { 
    text-decoration: none; 
    color: #333; 
}
.article-title a:hover { 
    color: #007cba; 
    text-decoration: underline;
}

.article-excerpt { 
    color: #666; 
    font-size: 0.95em; 
    line-height: 1.6; 
    margin-top: 8px;
}



/* 响应式 */
@media (max-width: 768px) {
    .friends-rss-block {
        padding: 12px;
        margin-bottom: 12px;
    }
    
    .article-meta {
        font-size: 0.85em;
    }
    
    .article-title {
        font-size: 1.1em;
    }
    
    .article-excerpt {
        font-size: 0.9em;
    }
}
</style>';
    }
}
