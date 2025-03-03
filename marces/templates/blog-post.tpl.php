<?php
// URL-Parameter
$path = isset($path) ? $path : '';
$params = isset($params) ? $params : [];

// Datumsparameter extrahieren
$year = isset($params['year']) ? $params['year'] : null;
$month = isset($params['month']) ? $params['month'] : null;
$day = isset($params['day']) ? $params['day'] : null;
$slug = isset($params['slug']) ? $params['slug'] : null;

// Prüfen, ob alle erforderlichen Parameter vorhanden sind
if (!$year || !$month || !$day || !$slug) {
    // Fehler: Unvollständige Parameter
    echo '<div class="error">Dieser Blogbeitrag existiert nicht.</div>';
    return;
}

// Datei-ID erstellen (YYYY-MM-DD-slug)
$postId = $year . '-' . $month . '-' . $day . '-' . $slug;

// BlogManager initialisieren
$blogManager = new \Marces\Core\BlogManager();

// Blog-Beitrag abrufen
$post = $blogManager->getPost($postId);

// Prüfen, ob der Beitrag existiert
if (!$post) {
    // Fehler: Beitrag nicht gefunden
    echo '<div class="error">Dieser Blogbeitrag existiert nicht.</div>';
    return;
}

// Alle Kategorien für die Seitenleiste abrufen
$categories = $blogManager->getCategories();
?>

<div class="blog-container">
    <article class="blog-post">
        <header class="post-header">
            <h1 class="post-title"><?php echo htmlspecialchars($post['title']); ?></h1>
            <div class="post-meta">
                <span class="post-date"><?php echo marces_format_date($post['date'], 'd.m.Y'); ?></span>
                <span class="post-author">von <?php echo htmlspecialchars($post['author']); ?></span>
                <?php if (!empty($post['categories'])): ?>
                    <span class="post-categories">
                        in
                        <?php 
                        $categoryLinks = [];
                        foreach ($post['categories'] as $cat) {
                            if (!empty($cat)) {
                                $categoryLinks[] = '<a href="' . marces_site_url('blog?category=' . urlencode($cat)) . '">' . htmlspecialchars($cat) . '</a>';
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
                <img src="<?php echo marces_site_url($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
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
                        $tagLinks[] = '<a href="' . marces_site_url('blog?tag=' . urlencode($tag)) . '" class="tag-link">' . htmlspecialchars($tag) . '</a>';
                    }
                }
                echo implode(' ', $tagLinks);
                ?>
            </div>
        <?php endif; ?>
        
        <div class="post-navigation">
            <a href="<?php echo marces_site_url('blog'); ?>" class="back-to-blog">
                &laquo; Zurück zum Blog
            </a>
        </div>
    </article>
    
    <aside class="blog-sidebar">
        <div class="sidebar-section categories-section">
            <h3 class="sidebar-title">Kategorien</h3>
            <?php if (empty($categories)): ?>
                <p>Keine Kategorien vorhanden.</p>
            <?php else: ?>
                <ul class="categories-list">
                    <?php foreach ($categories as $cat => $count): ?>
                        <li>
                            <a href="<?php echo marces_site_url('blog?category=' . urlencode($cat)); ?>">
                                <?php echo htmlspecialchars($cat); ?> (<?php echo $count; ?>)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>