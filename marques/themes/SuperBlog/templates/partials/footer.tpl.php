<footer class="super-blog-footer">
  <div class="super-blog-container">
    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($system_settings['site_name'] ?? 'Super Blog'); ?>. Alle Rechte vorbehalten.</p>
    <p>Powered by <a href="#">marques CMS</a></p>
    <?php echo $this->getNavigationManager()->renderFooterMenu(); ?>
  </div>
</footer>
