<?php
// Blog-Beitrag anhand des Slugs abrufen
$post = $tpl->blogManager->getPostBySlug($tpl->params['slug'] ?? '');

// Prüfen, ob der Beitrag existiert
if (!$post) {
    echo '<div class="error">Dieser Blogbeitrag existiert nicht.</div>';
    return; // Wichtig: Hier beenden, wenn kein Post gefunden wurde!
}

// Alle Kategorien für die Seitenleiste abrufen
$categories = $tpl->blogManager->getCategories();
?>

<div class="blog-container">
    <div class="blog-main">
        <article class="blog-post">
            <header class="post-header">
                <h1 class="post-title"><?php echo $tpl->helper->escapeHtml($post['title']); ?></h1>
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
                    <img src="<?php echo $tpl->helper->getSiteUrl($post['featured_image']); ?>" alt="<?php echo $tpl->helper->escapeHtml($post['title']); ?>">
                </div>
            <?php endif; ?>

            <div class="post-content">
                <?php echo $post['content']; ?>
            </div>

            <?php if (!empty($post['tags'])): ?>
                <div class="post-tags">
                    <span class="tags-title">Tags:</span>
                    <?php
                    $tagLinks = [];
                    foreach ($post['tags'] as $tag) {
                        if (!empty($tag)) {
                            $tagLinks[] = '<a href="' . $tpl->helper->getSiteUrl('blog?tag=' . urlencode($tag)) . '" class="tag-link">' . $tpl->helper->escapeHtml($tag) . '</a>';
                        }
                    }
                    echo implode(' ', $tagLinks);
                    ?>
                </div>
            <?php endif; ?>

            <div class="post-navigation">
                <a href="<?php echo $tpl->helper->getSiteUrl('blog'); ?>" class="back-to-blog">
                    « Zurück zum Blog
                </a>
            </div>
        </article>
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