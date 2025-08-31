<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>友链RSS聚合 - <?php echo htmlspecialchars($templateThis->options->title); ?></title>
    <meta name="description" content="来自友链博客的最新文章聚合">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            color: white;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .header p {
            font-size: 1.2em;
            opacity: 0.9;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .articles {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .articles h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.8em;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        
        .article-item {
            border-bottom: 1px solid #eee;
            padding: 20px 0;
            transition: all 0.3s ease;
        }
        
        .article-item:last-child {
            border-bottom: none;
        }
        
        .article-item:hover {
            background: rgba(102, 126, 234, 0.05);
            border-radius: 8px;
            padding: 20px 15px;
        }
        
        .article-title {
            font-size: 1.3em;
            margin-bottom: 8px;
        }
        
        .article-title a {
            color: #333;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .article-title a:hover {
            color: #667eea;
        }
        
        .article-meta {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 10px;
        }
        
        .article-meta span {
            margin-right: 15px;
        }
        
        .blog-name {
            color: #667eea;
            font-weight: 500;
        }
        
        .article-description {
            color: #555;
            line-height: 1.6;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .footer a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .rss-link {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            color: white !important;
            text-decoration: none;
            margin-left: 10px;
            transition: all 0.3s ease;
        }
        
        .rss-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        /* 加载动画 */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* 文章卡片样式优化 */
        .article-item {
            border-bottom: 1px solid #eee;
            padding: 20px 0;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .article-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: #667eea;
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }
        
        .article-item:hover::before {
            transform: scaleY(1);
        }
        
        /* 标签样式 */
        .article-tag {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            margin-right: 8px;
        }
        
        /* 时间显示优化 */
        .article-time {
            color: #999;
            font-size: 0.85em;
        }
        
        /* 空状态样式 */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-state .icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state h3 {
            font-size: 1.5em;
            margin-bottom: 10px;
            color: #999;
        }
        
        /* 响应式优化 */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .stats {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .articles {
                padding: 20px;
            }
            
            .article-item {
                padding: 15px 0;
            }
            
            .article-title {
                font-size: 1.1em;
            }
        }
        
        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.8em;
            }
            
            .stat-number {
                font-size: 1.5em;
            }
            
            .rss-link {
                display: block;
                margin: 10px auto 0;
                text-align: center;
                width: fit-content;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>友链RSS聚合</h1>
            <p>来自友链博客的最新文章</p>
            <a href="<?php echo $templateThis->options->siteUrl; ?>action/friends-rss?do=rss" class="rss-link">📡 RSS订阅</a>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['blogCount']; ?></div>
                <div class="stat-label">友链博客</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['articleCount']; ?></div>
                <div class="stat-label">聚合文章</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['lastUpdate'] ? date('m-d', $stats['lastUpdate']) : '--'; ?></div>
                <div class="stat-label">最后更新</div>
            </div>
        </div>
        
        <div class="articles">
            <h2>最新文章</h2>
            
            <?php if (empty($articles)): ?>
            <div class="empty-state">
                <div class="icon">📰</div>
                <h3>暂无文章</h3>
                <p>还没有获取到友链博客的文章，请稍后再试或检查友链RSS配置。</p>
            </div>
            <?php else: ?>
            <?php foreach ($articles as $article): ?>
            <div class="article-item">
                <div class="article-title">
                    <a href="<?php echo htmlspecialchars($article['link']); ?>" target="_blank" rel="noopener">
                        <?php echo htmlspecialchars($article['title']); ?>
                    </a>
                </div>
                <div class="article-meta">
                    <span class="article-tag"><?php echo htmlspecialchars($article['blogName']); ?></span>
                    <span>作者：<?php echo htmlspecialchars($article['author']); ?></span>
                    <span class="article-time">
                        <?php 
                        $timeAgo = time() - $article['pubDate'];
                        if ($timeAgo < 3600) {
                            echo floor($timeAgo / 60) . ' 分钟前';
                        } elseif ($timeAgo < 86400) {
                            echo floor($timeAgo / 3600) . ' 小时前';
                        } elseif ($timeAgo < 2592000) {
                            echo floor($timeAgo / 86400) . ' 天前';
                        } else {
                            echo date('Y-m-d', $article['pubDate']);
                        }
                        ?>
                    </span>
                </div>
                <?php if (!empty($article['description'])): ?>
                <div class="article-description">
                    <?php echo htmlspecialchars(mb_substr($article['description'], 0, 200, 'UTF-8')); ?>
                    <?php if (mb_strlen($article['description'], 'UTF-8') > 200): ?>...<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>由 <a href="<?php echo $templateThis->options->siteUrl; ?>"><?php echo htmlspecialchars($templateThis->options->title); ?></a> 提供 | 
            <a href="<?php echo $templateThis->options->siteUrl; ?>action/friends-rss?do=rss">RSS订阅</a></p>
        </div>
    </div>
</body>
</html>