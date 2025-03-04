<?php
/**
 * marques CMS - 403 Fehlertemplate
 * 
 * Template für "Zugriff verweigert"-Fehler.
 *
 * @package marques
 * @subpackage templates
 */
?>
<!DOCTYPE html>
<html lang="<?php echo isset($system_settings['admin_language']) ? $system_settings['admin_language'] : 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? '403 - Zugriff verweigert'); ?> - <?php echo htmlspecialchars($system_settings['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="<?php echo marques_theme_url('css/main-style.css'); ?>">
    <style>
        .error-container {
            text-align: center;
            padding: 50px 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            color: #f39c12;
            margin: 0;
            line-height: 1;
        }
        .error-title {
            font-size: 32px;
            margin: 20px 0;
            color: #2c3e50;
        }
        .error-message {
            font-size: 18px;
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        .home-button {
            display: inline-block;
            background-color: #3498db;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .home-button:hover {
            background-color: #2980b9;
        }
        .error-illustration {
            max-width: 300px;
            margin: 20px auto;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-code">403</h1>
        <h2 class="error-title">Zugriff verweigert</h2>
        
        <p class="error-message">
            <?php echo htmlspecialchars($content ?? 'Sie haben keine Berechtigung, auf diese Seite zuzugreifen.'); ?>
        </p>
        
        <div class="error-illustration">
            <!-- Einfache SVG-Illustration für 403-Fehler -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512">
                <path fill="#f39c12" d="M144 144v48H304V144c0-44.2-35.8-80-80-80s-80 35.8-80 80zM80 224v48H272V224H80zM288 144c0 22.1-17.9 40-40 40H104c-22.1 0-40-17.9-40-40v-48c0-66.3 53.7-120 120-120s120 53.7 120 120v48zm-144 80c0-17.7 14.3-32 32-32h128c17.7 0 32 14.3 32 32v48H144v-48zm336-80v48c0 22.1-17.9 40-40 40H344c0-17.7 14.3-32 32-32h88v-48c0-22.1-17.9-40-40-40h-40c0-22.1-17.9-40-40-40h-40V80c0-26.5 21.5-48 48-48h16c53 0 96 43 96 96zm96 144c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H16c-8.8 0-16-7.2-16-16v-32c0-8.8 7.2-16 16-16h32v-64c0-84.8 69.2-154.3 154.7-152 83.9 2.2 149.3 73.1 149.3 156.9v59.1h32z"/>
            </svg>
        </div>
        
        <a href="<?php echo rtrim($system_settings['base_url'] ?? '', '/'); ?>/" class="home-button">Zurück zur Startseite</a>
    </div>

    <?php if (isset($debug) && $debug === true && isset($exception)): ?>
    <div class="debug-container">
        <h3>Debug-Informationen</h3>
        <div class="debug-info">
            <p><strong>Fehlermeldung:</strong> <?php echo htmlspecialchars($exception->getMessage()); ?></p>
            <p><strong>Datei:</strong> <?php echo htmlspecialchars($exception->getFile()); ?></p>
            <p><strong>Zeile:</strong> <?php echo (int)$exception->getLine(); ?></p>
            
            <h4>Stack Trace:</h4>
            <pre><?php echo htmlspecialchars($exception->getTraceAsString()); ?></pre>
        </div>
        <p class="debug-note">Diese detaillierte Fehlermeldung wird nur im Debug-Modus angezeigt.</p>
    </div>
    
    <style>
        .debug-container {
            text-align: left;
            margin: 40px auto;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            max-width: 800px;
        }
        .debug-info {
            background-color: #fff;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin: 10px 0;
        }
        .debug-info pre {
            background-color: #f5f5f5;
            padding: 10px;
            overflow-x: auto;
            font-family: monospace;
            font-size: 13px;
        }
        .debug-note {
            font-size: 12px;
            color: #6c757d;
            margin-top: 10px;
        }
    </style>
    <?php endif; ?>
</body>
</html>