<header class="marces-header">
    <div class="marces-site-branding">
        <h1 class="marces-site-title">
            <a href="<?php echo marces_site_url(); ?>"><?php echo marces_escape_html($config['site_name']); ?></a>
        </h1>
        <p class="marces-site-description"><?php echo marces_escape_html($config['site_description']); ?></p>
    </div>
    
    <nav class="marces-main-navigation">
        <ul class="marces-menu">
            <li class="marces-menu-item"><a href="<?php echo marces_site_url(); ?>">Startseite</a></li>
            <li class="marces-menu-item"><a href="<?php echo marces_site_url('blog'); ?>">Blog</a></li>
            <li class="marces-menu-item"><a href="<?php echo marces_site_url('about'); ?>">Über uns</a></li>
            <li class="marces-menu-item"><a href="<?php echo marces_site_url('contact'); ?>">Kontakt</a></li>
        </ul>
    </nav>
</header>