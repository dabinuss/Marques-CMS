<footer class="marces-footer">
    <div class="marces-container">
        <div class="marces-footer-content">
            <p class="marces-copyright">
                &copy; <?php echo date('Y'); ?> <?php echo marces_escape_html(isset($system_settings) && isset($system_settings['site_name']) ? $system_settings['site_name'] : 'marces CMS'); ?>. Alle Rechte vorbehalten.
            </p>
            <p class="marces-powered-by">
                Powered by <a href="#" target="_blank">marces CMS</a>
            </p>
        </div>
    </div>
</footer>