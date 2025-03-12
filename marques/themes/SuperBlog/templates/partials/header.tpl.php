<header class="super-blog-header">
  <div class="site-branding">
    <a href="<?= marques_site_url(); ?>">
      <?= htmlspecialchars($system_settings['site_name'] ?? 'Super Blog'); ?>
    </a>
  </div>
  <nav>
    <ul>
      <li><a href="<?= marques_site_url(); ?>">Startseite</a></li>
      <li><a href="<?= marques_site_url('blog'); ?>">Blog</a></li>
      <li><a href="<?= marques_site_url('about'); ?>">Über uns</a></li>
      <li><a href="<?= marques_site_url('contact'); ?>">Kontakt</a></li>
    </ul>
  </nav>
</header>
