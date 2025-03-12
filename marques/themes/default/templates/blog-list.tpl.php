<?php 
declare(strict_types=1);

error_reporting(E_ALL);

/*
$testGlobPathTpl = MARQUES_ROOT_DIR . '/content/blog'; // TEST: Pfad im Template
$testGlobResultTpl = glob($testGlobPathTpl . '/*', GLOB_ONLYDIR); // TEST: glob() im Template
\Marques\Core\Helper::debug("--- TEST GLOB() IM TEMPLATE ---");
\Marques\Core\Helper::debug("Test-Pfad (Template): " . $testGlobPathTpl);
\Marques\Core\Helper::debug("Ergebnis von glob() TEST (Template):" . $testGlobResultTpl);
\Marques\Core\Helper::debug("--- ENDE TEST GLOB() IM TEMPLATE ---");
*/

// Falls noch nicht vorhanden, initialisieren wir die nötigen Werte:
$tpl->config = $tpl->config ?? require MARQUES_CONFIG_DIR . '/system.config.php';
$blogManager = $blogManager ?? new \Marques\Core\BlogManager();

// URL-Parameter übernehmen
$tpl->category = isset($_GET['category']) ? $_GET['category'] : '';
$tpl->tag      = $_GET['tag'] ?? '';
$tpl->page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$tpl->perPage  = $tpl->config['posts_per_page'] ?? 10;

// Blog-Beiträge abrufen
$offset = ($tpl->page - 1) * $tpl->perPage;
$posts = $blogManager->getAllPosts($tpl->perPage, $offset, $tpl->category);
// $posts = $blogManager->getAllPosts(0, 0, $tpl->category);
// Blog-Beiträge abrufen
// $posts = $blogManager->getAllPosts(0, 0, $filter_category);

// marques_debug($tpl->posts, true);

// Titel anpassen
$tpl->pageTitle = 'Blog';
if (!empty($tpl->category)) {
    $tpl->pageTitle .= ' - Kategorie: ' . htmlspecialchars($tpl->category);
} elseif (!empty($tpl->tag)) {
    $tpl->pageTitle .= ' - Tag: ' . htmlspecialchars($tpl->tag);
}

// Alle Kategorien abrufen
$categories = $blogManager->getCategories();
?>

<div class="blog-container">
    <div class="blog-header">
        <h1 class="blog-title"><?= $tpl->pageTitle; ?></h1>
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
                            <a href="<?= \Marques\Core\Helper::formatBlogUrl($post); ?>">  <!-- KORREKTUR: formatBlogUrl verwenden -->
                                <?= htmlspecialchars($post['title']); ?>
                            </a>
                        </h2>
                        <div class="post-meta">
                            <span class="post-date"><?= marques_format_date($post['date'], 'd.m.Y'); ?></span>
                            <span class="post-author">von <?= htmlspecialchars($post['author']); ?></span>
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
                            <a href="<?= \Marques\Core\Helper::formatBlogUrl($post); ?>"> <!-- KORREKTUR: formatBlogUrl verwenden -->
                                <img src="<?= marques_site_url($post['featured_image']); ?>" alt="<?= htmlspecialchars($post['title']); ?>">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="post-excerpt">
                        <?= htmlspecialchars($post['excerpt']); ?>
                    </div>

                    <div class="post-read-more">
                        <a href="<?= \Marques\Core\Helper::formatBlogUrl($post); ?>" class="read-more-link"> <!-- KORREKTUR: formatBlogUrl verwenden -->
                            Weiterlesen
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>

            <!-- Paginierung -->
            <div class="blog-pagination">
                <?php if ($tpl->page > 1): ?>
                    <a href="<?= marques_site_url('blog?page=' . ($tpl->page - 1) . (!empty($tpl->category) ? '&category=' . urlencode($tpl->category) : '') . (!empty($tpl->tag) ? '&tag=' . urlencode($tpl->tag) : '')); ?>" class="pagination-prev marques-button marques-button--outline">
                        « Vorherige
                    </a>
                <?php endif; ?>

                <?php if (count($posts) === $tpl->perPage): ?>
                    <a href="<?= marques_site_url('blog?page=' . ($tpl->page + 1) . (!empty($tpl->category) ? '&category=' . urlencode($tpl->category) : '') . (!empty($tpl->tag) ? '&tag=' . urlencode($tpl->tag) : '')); ?>" class="pagination-next marques-button marques-button--outline">
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
                            <a href="<?= marques_site_url('blog?category=' . urlencode($cat)); ?>">
                                <?= htmlspecialchars($cat); ?> (<?= $count; ?>)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>
