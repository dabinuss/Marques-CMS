<!DOCTYPE html>
<html lang="<?php echo isset($system_settings['admin_language']) ? $system_settings['admin_language'] : 'de'; ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($title ?? '500 - Serverfehler'); ?> - <?php echo htmlspecialchars($system_settings['site_name'] ?? 'Super Blog'); ?></title>
  <link rel="stylesheet" href="<?php echo marques_theme_url('css/super-blog-style.css'); ?>">
</head>
<body>
  <div class="super-blog-error-container">
    <h1 class="super-blog-error-code">500</h1>
    <h2 class="super-blog-error-title">Interner Serverfehler</h2>
    <p class="super-blog-error-message"><?php echo htmlspecialchars($content ?? 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuche es später erneut.'); ?></p>
    <a href="<?php echo rtrim($system_settings['base_url'] ?? '', '/'); ?>/" class="super-blog-error-home-button">Zurück zur Startseite</a>
  </div>
</body>
</html>
