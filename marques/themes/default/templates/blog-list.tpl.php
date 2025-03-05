<?php
// Navigationslinks
$config = require MARQUES_CONFIG_DIR . '/system.config.php';

// BlogManager initialisieren
$blogManager = new \Marques\Core\BlogManager();

// URL-Parameter
$category = isset($_GET['category']) ? $_GET['category'] : '';
$tag = isset($_GET['tag']) ? $_GET['tag'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = $config['posts_per_page'] ?? 10;

// Blog-Beiträge abrufen
$offset = ($page - 1) * $perPage;
$posts = $blogManager->getAllPosts($perPage, $offset, $category);

// Titel anpassen
$pageTitle = 'Blog';
if (!empty($category)) {
    $pageTitle .= ' - Kategorie: ' . htmlspecialchars($category);
} elseif (!empty($tag)) {
    $pageTitle .= ' - Tag: ' . htmlspecialchars($tag);
}

// Alle Kategorien abrufen
$categories = $blogManager->getCategories();
?>

<div class="blog-container">
    <div class="blog-header">
        <h1 class="blog-title"><?php echo $pageTitle; ?></h1>
    </div>
    
    <div class="blog-main">
        <?php if (empty($posts)): ?>
            <div class="blog-no-posts">
                <p>Keine Blog-Beiträge gefunden.</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <article class="blog-post-summary">
                    <header>
                        <h2 class="post-title">
                            <a href="<?php echo marques_format_blog_url($post); ?>">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </a>
                        </h2>
                        <div class="post-meta">
                            <span class="post-date"><?php echo marques_format_date($post['date'], 'd.m.Y'); ?></span>
                            <span class="post-author">von <?php echo htmlspecialchars($post['author']); ?></span>
                            <?php if (!empty($post['categories'])): ?>
                                <span class="post-categories">
                                    in
                                    <?php 
                                    $categoryLinks = [];
                                    foreach ($post['categories'] as $cat) {
                                        if (!empty($cat)) {
                                            $categoryLinks[] = '<a href="' . marques_site_url('blog?category=' . urlencode($cat)) . '">' . htmlspecialchars($cat) . '</a>';
                                        }
                                    }
                                    echo implode(', ', $categoryLinks);
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </header>
                    
                    <?php if (!empty($post['featured_image'])): ?>
                        <div class="post-featured-image">
                            <a href="<?php echo marques_format_blog_url($post); ?>">
                                <img src="<?php echo marques_site_url($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="post-excerpt">
                        <?php echo htmlspecialchars($post['excerpt']); ?>
                    </div>
                    
                    <div class="post-read-more">
                        <a href="<?php echo marques_format_blog_url($post); ?>" class="read-more-link">
                            Weiterlesen
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
            
            <!-- Paginierung -->
            <div class="blog-pagination">
                <?php if ($page > 1): ?>
                    <a href="<?php echo marques_site_url('blog?page=' . ($page - 1) . (!empty($category) ? '&category=' . urlencode($category) : '') . (!empty($tag) ? '&tag=' . urlencode($tag) : '')); ?>" class="pagination-prev marques-button marques-button--outline">
                        &laquo; Vorherige
                    </a>
                <?php endif; ?>
                
                <?php if (count($posts) === $perPage): ?>
                    <a href="<?php echo marques_site_url('blog?page=' . ($page + 1) . (!empty($category) ? '&category=' . urlencode($category) : '') . (!empty($tag) ? '&tag=' . urlencode($tag) : '')); ?>" class="pagination-next marques-button marques-button--outline">
                        Nächste &raquo;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <aside class="blog-sidebar">
        <div class="sidebar-section categories-section">
            <h3 class="sidebar-title">Kategorien</h3>
            <?php if (empty($categories)): ?>
                <p>Keine Kategorien vorhanden.</p>
            <?php else: ?>
                <ul class="categories-list">
                    <?php foreach ($categories as $cat => $count): ?>
                        <li>
                            <a href="<?php echo marques_site_url('blog?category=' . urlencode($cat)); ?>">
                                <?php echo htmlspecialchars($cat); ?> (<?php echo $count; ?>)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>