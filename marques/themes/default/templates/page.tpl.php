<article class="marques-page">
    <header class="marques-page-header">
        <h1 class="marques-page-title"><?= marques_escape_html($tpl->title ?? ''); ?></h1>
        
        <?php if (!empty($tpl->featured_image)): ?>
        <div class="marques-featured-image">
            <img src="<?= $tpl->themeUrl('media/' . $tpl->featured_image); ?>" alt="<?= marques_escape_html($tpl->title ?? ''); ?>">
        </div>
        <?php endif; ?>
    </header>
    
    <div class="marques-page-content">
        <?= $tpl->content ?? ''; ?>
    </div>
</article>