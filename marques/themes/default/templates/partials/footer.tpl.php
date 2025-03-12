<footer class="marques-footer">
    <div class="marques-container">
        <div class="marques-footer-content">
            <p class="marques-copyright">
                &copy; <?= date('Y'); ?> <?= \Marques\Core\Helper::escapeHtml(isset($system_settings) && isset($system_settings['site_name']) ? $system_settings['site_name'] : 'marques CMS'); ?>. Alle Rechte vorbehalten.
            </p>
            <p class="marques-powered-by">
                Powered by <a href="#" target="_blank">marques CMS</a>
            </p>
            <p>
                <?= $this->getNavigationManager()->renderFooterMenu(); ?>
            </p>
        </div>
    </div>
</footer>