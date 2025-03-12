<article class="marques-page">
    <header class="marques-page-header">
        <h1 class="marques-page-title"><?= marques_escape_html($title ?? ''); ?></h1>
        
        <?php if (!empty($featured_image)): ?>
        <div class="marques-featured-image">
            <img src="<?= marques_theme_url('media/' . $featured_image); ?>" alt="<?= marques_escape_html($title ?? ''); ?>">
        </div>
        <?php endif; ?>
    </header>
    
    <div class="marques-page-content">
        <?= $content ?? ''; ?>
    </div>
</article>