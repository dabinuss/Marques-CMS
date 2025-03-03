<header class="marces-header">
    <div class="marces-container">
        <div class="marces-header-content">
            <div class="marces-site-branding">
                <h1 class="marces-site-title">
                    <a href="<?php echo marces_site_url(); ?>"><?php echo marces_escape_html(isset($system_settings) && isset($system_settings['site_name']) ? $system_settings['site_name'] : 'marces CMS'); ?></a>
                </h1>
                <p class="marces-site-description"><?php echo marces_escape_html(isset($system_settings) && isset($system_settings['site_description']) ? $system_settings['site_description'] : ''); ?></p>
            </div>
            
            <nav class="marces-main-navigation">
                <ul class="marces-menu">
                    <li class="marces-menu-item"><a href="<?php echo marces_site_url(); ?>">Startseite</a></li>
                    <li class="marces-menu-item"><a href="<?php echo marces_site_url('blog'); ?>">Blog</a></li>
                    <li class="marces-menu-item"><a href="<?php echo marces_site_url('about'); ?>">Über uns</a></li>
                    <li class="marces-menu-item"><a href="<?php echo marces_site_url('contact'); ?>">Kontakt</a></li>
                </ul>
            </nav>
        </div>
    </div>
</header>