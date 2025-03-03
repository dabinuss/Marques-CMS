<article class="marces-page">
    <header class="marces-page-header">
        <h1 class="marces-page-title"><?php echo marces_escape_html($title ?? ''); ?></h1>
        
        <?php if (!empty($featured_image)): ?>
        <div class="marces-featured-image">
            <img src="<?php echo marces_asset_url('media/' . $featured_image); ?>" alt="<?php echo marces_escape_html($title ?? ''); ?>">
        </div>
        <?php endif; ?>
    </header>
    
    <div class="marces-page-content">
        <?php echo $content ?? ''; ?>
    </div>
</article>