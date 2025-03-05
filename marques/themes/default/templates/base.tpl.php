<!DOCTYPE html>
<html lang="<?php echo isset($system_settings['admin_language']) ? $system_settings['admin_language'] : 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? ''); ?> - <?php echo htmlspecialchars($system_settings['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="<?php echo $themeManager->getThemeAssetsUrl('css/main-style.css'); ?>">
    <link rel="icon" type="image/x-icon" href="<?php echo $themeManager->getThemeAssetsUrl('images/favicon.svg'); ?>">
</head>
<body>
    <?php $this->includePartial('header'); ?>
    
    <main class="marques-content">
        <div class="marques-container">
            <?php 
            // Template basierend auf dem angegebenen Template-Namen laden
            include __DIR__ . '/' . $templateName . '.tpl.php';
            ?>
        </div>
    </main>
    
    <?php $this->includePartial('footer'); ?>
    
    <script src="<?php echo $themeManager->getThemeAssetsUrl('js/main.js'); ?>"></script>
</body>
</html>