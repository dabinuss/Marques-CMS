<footer class="marques-footer">
    <div class="marques-container">
        <div class="marques-footer-content">
            <p class="marques-copyright">
                &copy; <?php echo date('Y'); ?> <?php echo marques_escape_html(isset($system_settings) && isset($system_settings['site_name']) ? $system_settings['site_name'] : 'marques CMS'); ?>. Alle Rechte vorbehalten.
            </p>
            <p class="marques-powered-by">
                Powered by <a href="#" target="_blank">marques CMS</a>
            </p>
            <p>
                <?php echo $this->getNavigationManager()->renderFooterMenu(); ?>
            </p>
        </div>
    </div>
</footer>