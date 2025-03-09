<?php
// URL-Parameter (aus $params, NICHT $_GET)
$category = $tpl->params['category'] ?? '';
$tag      = $tpl->params['tag'] ?? '';
$page     = $tpl->params['page'] ?? 1;
$perPage  = $tpl->config['posts_per_page'] ?? 10;

// Blog-Beiträge abrufen
$offset = ($page - 1) * $perPage;
$posts = $tpl->blogManager->getAllPosts($perPage, $offset, $category);

// Titel anpassen
$pageTitle = 'Blog';
if (!empty($category)) {
    $pageTitle .= ' - Kategorie: ' . $tpl->helper->escapeHtml($category);
} elseif (!empty($tag)) {
    $pageTitle .= ' - Tag: ' . $tpl->helper->escapeHtml($tag);
}

// Alle Kategorien abrufen (für die Sidebar)
$categories = $tpl->blogManager->getCategories();
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
                            <a href="<?php echo $tpl->helper->formatBlogUrl($post); ?>">
                                <?php echo $tpl->helper->escapeHtml($post['title']); ?>
                            </a>
                        </h2>
                        <div class="post-meta">
                            <span class="post-date"><?php echo $tpl->helper->formatDate($post['date'], 'd.m.Y'); ?></span>
                            <span class="post-author">von <?php echo $tpl->helper->escapeHtml($post['author']); ?></span>
                            <?php if (!empty($post['categories'])): ?>
                                <span class="post-categories">
                                    in
                                    <?php
                                    $categoryLinks = [];
                                    foreach ($post['categories'] as $cat) {
                                        if (!empty($cat)) {
                                            $categoryLinks[] = '<a href="' . $tpl->helper->getSiteUrl('blog?category=' . urlencode($cat)) . '">' . $tpl->helper->escapeHtml($cat) . '</a>';
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
                            <a href="<?php echo $tpl->helper->formatBlogUrl($post); ?>">
                                <img src="<?php echo $tpl->helper->getSiteUrl($post['featured_image']); ?>" alt="<?php echo $tpl->helper->escapeHtml($post['title']); ?>">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="post-excerpt">
                        <?php echo $tpl->helper->escapeHtml($post['excerpt']); ?>
                    </div>

                    <div class="post-read-more">
                        <a href="<?php echo $tpl->helper->formatBlogUrl($post); ?>" class="read-more-link">
                            Weiterlesen
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>

            <!-- Paginierung -->
            <div class="blog-pagination">
             <?php if ($page > 1): ?>
                 <a href="<?php echo $tpl->helper->getSiteUrl('blog?page=' . ($page - 1) . (!empty($category) ? '&category=' . urlencode($category) : '') . (!empty($tag) ? '&tag=' . urlencode($tag) : '')); ?>"
                    class="pagination-prev marques-button marques-button--outline">
                     « Vorherige
                 </a>
             <?php endif; ?>

             <?php if (count($posts) === $perPage): ?>
                 <a href="<?php echo $tpl->helper->getSiteUrl('blog?page=' . ($page + 1) . (!empty($category) ? '&category=' . urlencode($category) : '') . (!empty($tag) ? '&tag=' . urlencode($tag) : '')); ?>"
                    class="pagination-next marques-button marques-button--outline">
                     Nächste »
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
                            <a href="<?php echo $tpl->helper->getSiteUrl('blog?category=' . urlencode($cat)); ?>">
                                <?php echo $tpl->helper->escapeHtml($cat); ?> (<?php echo $count; ?>)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>