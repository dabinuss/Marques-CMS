<article class="marques-page">
    <header class="marques-page-header">
        <h1 class="marques-page-title"><?php echo marques_escape_html($title ?? ''); ?></h1>
        
        <?php if (!empty($featured_image)): ?>
        <div class="marques-featured-image">
            <img src="<?php echo marques_theme_url('media/' . $featured_image); ?>" alt="<?php echo marques_escape_html($title ?? ''); ?>">
        </div>
        <?php endif; ?>
    </header>
    
    <div class="marques-page-content">
        <?php echo $content ?? ''; ?>
    </div>
</article>