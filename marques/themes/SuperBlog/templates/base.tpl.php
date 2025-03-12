<!DOCTYPE html>
<html lang="<?= isset($system_settings['admin_language']) ? $system_settings['admin_language'] : 'de'; ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title ?? 'Super Blog'); ?> - <?= htmlspecialchars($system_settings['site_name'] ?? 'Super Blog CMS'); ?></title>
  <link rel="stylesheet" href="<?= marques_theme_url('css/super-blog-style.css'); ?>">
  <link rel="icon" type="image/x-icon" href="<?= marques_theme_url('images/favicon.svg'); ?>">
</head>
<body>
  <?php $this->includePartial('header'); ?>

  <!-- Optional: Hero Section -->
  <?php if (!empty($hero_title) || !empty($hero_subtitle)) : ?>
  <section class="super-blog-hero">
    <h1><?= htmlspecialchars($hero_title ?? 'Willkommen zu Super Blog'); ?></h1>
    <p><?= htmlspecialchars($hero_subtitle ?? 'Inspiriert. Innovativ. Informativ.'); ?></p>
  </section>
  <?php endif; ?>

  <main class="super-blog-content">
    <div class="super-blog-container">
      <?php 
        // Dynamisches Laden des gewünschten Templates (z. B. Blogliste oder Einzelbeitrag)
        include __DIR__ . '/' . $templateName . '.tpl.php'; 
      ?>
    </div>
  </main>

  <?php $this->includePartial('footer'); ?>

  <script src="<?= marques_theme_url('js/script.js'); ?>"></script>
</body>
</html>
