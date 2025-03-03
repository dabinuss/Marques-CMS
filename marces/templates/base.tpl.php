<!DOCTYPE html>
<html lang="<?php echo $config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo marces_escape_html($title ?? $config['site_name']); ?></title>
    <meta name="description" content="<?php echo marces_escape_html($description ?? $config['site_description']); ?>">
    <link rel="stylesheet" href="<?php echo marces_asset_url('css/main-style.css'); ?>">
</head>
<body class="marces-body">
    <div class="marces-container">
        <?php $this->includePartial('header'); ?>
        
        <main class="marces-content">
            <?php include MARCES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php'; ?>
        </main>
        
        <?php $this->includePartial('footer'); ?>
    </div>
    
    <script src="<?php echo marces_asset_url('js/main.js'); ?>"></script>
</body>
</html>