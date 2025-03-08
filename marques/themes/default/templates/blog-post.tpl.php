<?php 
declare(strict_types=1);

// URL-Parameter sollten idealerweise bereits in $tpl vorhanden sein.
// Falls nicht, initialisieren wir sie:
$tpl->path   = $tpl->path ?? '';
$tpl->params = $tpl->params ?? [];

// Slug Parameter extrahieren (wichtig für URL-Mapping)
$slug  = $tpl->params['slug']  ?? null;

// BlogManager initialisieren, falls noch nicht vorhanden
$tpl->blogManager = $tpl->blogManager ?? new \Marques\Core\BlogManager();

// Blog-Beitrag anhand des Slugs abrufen (URL-Mapping sollte das korrekte ID finden)
$tpl->post = $tpl->blogManager->getPostBySlug($slug);

// Prüfen, ob der Beitrag existiert
if (!$tpl->post) {
    echo '<div class="error">Dieser Blogbeitrag existiert nicht.</div>';
    return;
}

// Alle Kategorien für die Seitenleiste abrufen
$tpl->categories = $tpl->blogManager->getCategories();
?>

<div class="blog-container">
    <div class="blog-main">
        <article class="blog-post">
            <header class="post-header">
                <h1 class="post-title"><?php echo htmlspecialchars($tpl->post['title']); ?></h1>
                <div class="post-meta">
                    <span class="post-date"><?php echo marques_format_date($tpl->post['date'], 'd.m.Y'); ?></span>
                    <span class="post-author">von <?php echo htmlspecialchars($tpl->post['author']); ?></span>
                    <?php if (!empty($tpl->post['categories'])): ?>
                        <span class="post-categories">
                            in
                            <?php
                            $categoryLinks = [];
                            foreach ($tpl->post['categories'] as $cat) {
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

            <?php if (!empty($tpl->post['featured_image'])): ?>
                <div class="post-featured-image">
                    <img src="<?php echo marques_site_url($tpl->post['featured_image']); ?>" alt="<?php echo htmlspecialchars($tpl->post['title']); ?>">
                </div>
            <?php endif; ?>

            <div class="post-content">
                <?php echo $tpl->post['content']; ?>
            </div>

            <?php if (!empty($tpl->post['tags'])): ?>
                <div class="post-tags">
                    <span class="tags-title">Tags:</span>
                    <?php
                    $tagLinks = [];
                    foreach ($tpl->post['tags'] as $tag) {
                        if (!empty($tag)) {
                            $tagLinks[] = '<a href="' . marques_site_url('blog?tag=' . urlencode($tag)) . '" class="tag-link">' . htmlspecialchars($tag) . '</a>';
                        }
                    }
                    echo implode(' ', $tagLinks);
                    ?>
                </div>
            <?php endif; ?>

            <div class="post-navigation">
                <a href="<?php echo marques_site_url('blog'); ?>" class="back-to-blog">
                    « Zurück zum Blog
                </a>
            </div>
        </article>
    </div>

    <aside class="blog-sidebar">
        <div class="sidebar-section categories-section">
            <h3 class="sidebar-title">Kategorien</h3>
            <?php if (empty($tpl->categories)): ?>
                <p>Keine Kategorien vorhanden.</p>
            <?php else: ?>
                <ul class="categories-list">
                    <?php foreach ($tpl->categories as $cat => $count): ?>
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