<?php
/**
 * marques CMS - 500 Fehlertemplate
 * 
 * Template für interne Serverfehler.
 *
 * @package marques
 * @subpackage templates
 */
?>
<!DOCTYPE html>
<html lang="<?= isset($system_settings['admin_language']) ? $system_settings['admin_language'] : 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? '500 - Serverfehler'); ?> - <?= htmlspecialchars($system_settings['site_name'] ?? 'marques CMS'); ?></title>
    <link rel="stylesheet" href="<?= marques_theme_url('css/main-style.css'); ?>">
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
            color: #e67e22;
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
        <h1 class="error-code">500</h1>
        <h2 class="error-title">Interner Serverfehler</h2>
        
        <p class="error-message">
            <?= htmlspecialchars($content ?? 'Es ist ein unerwarteter Fehler aufgetreten. Bitte versuchen Sie es später erneut.'); ?>
        </p>
        
        <div class="error-illustration">
            <!-- Einfache SVG-Illustration für 500-Fehler -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="#e67e22" d="M506.3 417l-213.3-364c-16.33-28-57.54-28-73.98 0l-213.2 364C-10.59 444.9 9.851 480 42.74 480h426.6C502.1 480 522.6 445 506.3 417zM232 168c0-13.25 10.75-24 24-24S280 154.8 280 168v128c0 13.25-10.75 24-23.1 24S232 309.3 232 296V168zM256 416c-17.36 0-31.44-14.08-31.44-31.44c0-17.36 14.07-31.44 31.44-31.44s31.44 14.08 31.44 31.44C287.4 401.9 273.4 416 256 416z"/>
            </svg>
        </div>
        
        <a href="<?= rtrim($system_settings['base_url'] ?? '', '/'); ?>/" class="home-button">Zurück zur Startseite</a>
    </div>

    <?php if (isset($debug) && $debug === true && isset($exception)): ?>
    <div class="debug-container">
        <h3>Debug-Informationen</h3>
        <div class="debug-info">
            <p><strong>Fehlermeldung:</strong> <?= htmlspecialchars($exception->getMessage()); ?></p>
            <p><strong>Datei:</strong> <?= htmlspecialchars($exception->getFile()); ?></p>
            <p><strong>Zeile:</strong> <?= (int)$exception->getLine(); ?></p>
            
            <h4>Stack Trace:</h4>
            <pre><?= htmlspecialchars($exception->getTraceAsString()); ?></pre>
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