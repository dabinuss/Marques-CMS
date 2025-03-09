<header class="marques-header">
    <div class="marques-container">
        <div class="marques-header-content">
            <div class="marques-site-branding">
                <h1 class="marques-site-title">
                    <a href="<?php echo $tpl->helper->getSiteUrl(); ?>"><?php echo $tpl->helper->escapeHtml($tpl->system_settings['site_name'] ?? 'marques CMS'); ?></a>
                </h1>
                <p class="marques-site-description"><?php echo $tpl->helper->escapeHtml($tpl->system_settings['site_description'] ?? ''); ?></p>
            </div>
            <?php  if(!empty($tpl->navigation)): ?>
              <nav class = "marques-main-navigation">
                  <?= $tpl->navigation ?>
              </nav>
            <?php endif; ?>
        </div>
    </div>
</header>