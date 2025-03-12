<header class="marques-header">
    <div class="marques-container">
        <div class="marques-header-content">
            <div class="marques-site-branding">
                <h1 class="marques-site-title">
                    <a href="<?= marques_site_url(); ?>"><?= marques_escape_html(isset($system_settings) && isset($system_settings['site_name']) ? $system_settings['site_name'] : 'marques CMS'); ?></a>
                </h1>
                <p class="marques-site-description"><?= marques_escape_html(isset($system_settings) && isset($system_settings['site_description']) ? $system_settings['site_description'] : ''); ?></p>
            </div>

            <?php
            echo $this->getNavigationManager()->renderMainMenu();
            ?>
            
            <!--
            <nav class="marques-main-navigation">
                
                <ul class="marques-menu">
                    <li class="marques-menu-item"><a href="<?= marques_site_url(); ?>">Startseite</a></li>
                    <li class="marques-menu-item"><a href="<?= marques_site_url('blog'); ?>">Blog</a></li>
                    <li class="marques-menu-item"><a href="<?= marques_site_url('about'); ?>">Über uns</a></li>
                    <li class="marques-menu-item"><a href="<?= marques_site_url('contact'); ?>">Kontakt</a></li>
                </ul>
            </nav>
            -->
        </div>
    </div>
</header>