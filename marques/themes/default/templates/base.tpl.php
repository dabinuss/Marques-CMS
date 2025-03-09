<!DOCTYPE html>
<html lang="<?php echo isset($tpl->system_settings['admin_language']) ? $tpl->system_settings['admin_language'] : 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tpl->title; ?> - <?php echo  $tpl->system_settings['site_name']; ?></title>
    <link rel="stylesheet" href="<?php echo $tpl->themeUrl('css/main-style.css'); ?>">
    <link rel="icon" type="image/x-icon" href="<?php echo $tpl->themeUrl('images/favicon.svg'); ?>">
</head>
<body>
    <?php $this->includePartial('header', get_defined_vars()); ?>

    <main class="marques-content">
        <div class="marques-container">
            <?php
            include $tpl->templateFile; // WICHTIG: Verwende $tpl->templateFile
            ?>
        </div>
    </main>

    <?php $this->includePartial('footer', get_defined_vars()); ?>

    <script src="<?php echo $tpl->themeUrl('js/main.js');  ?>"></script>
</body>
</html>