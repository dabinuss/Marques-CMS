<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : htmlspecialchars($system_settings['site_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo marques_asset_url('css/main-style.css'); ?>">
    <?php if(isset($description) && !empty($description)): ?>
    <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
    <?php endif; ?>
</head>
<body>
    <?php if(isset($this) && method_exists($this, 'includePartial')): ?>
    <?php $this->includePartial('header'); ?>
    <?php endif; ?>
    
    <main class="marques-content">
        <div class="marques-container">
            <?php include MARCES_TEMPLATE_DIR . '/' . $templateName . '.tpl.php'; ?>
        </div>
    </main>
    
    <?php if(isset($this) && method_exists($this, 'includePartial')): ?>
    <?php $this->includePartial('footer'); ?>
    <?php endif; ?>
</body>
</html>