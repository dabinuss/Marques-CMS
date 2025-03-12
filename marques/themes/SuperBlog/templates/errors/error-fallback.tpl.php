<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fehler <?= (int)($error_code ?? 500); ?></title>
  <link rel="stylesheet" href="<?= marques_theme_url('css/super-blog-style.css'); ?>">
</head>
<body>
  <div class="super-blog-error-container">
    <h1 class="super-blog-error-code"><?= (int)($error_code ?? 500); ?></h1>
    <h2 class="super-blog-error-title">
      <?php 
        switch ($error_code ?? 500) {
          case 403:
            echo 'Zugriff verweigert';
            break;
          case 404:
            echo 'Seite nicht gefunden';
            break;
          default:
            echo 'Ein Fehler ist aufgetreten';
        }
      ?>
    </h2>
    <p class="super-blog-error-message"><?= htmlspecialchars($content ?? 'Ein unerwarteter Fehler ist aufgetreten.'); ?></p>
    <a href="/" class="super-blog-error-home-button">Zurück zur Startseite</a>
  </div>
</body>
</html>
