<?php
/**
 * marques CMS - 404 Fehlertemplate
 * 
 * Template für "Seite nicht gefunden"-Fehler.
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
    <title><?= htmlspecialchars($title ?? '404 - Seite nicht gefunden'); ?> - <?= htmlspecialchars($system_settings['site_name'] ?? 'marques CMS'); ?></title>
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
            color: #e74c3c;
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
        <h1 class="error-code">404</h1>
        <h2 class="error-title">Seite nicht gefunden</h2>
        
        <p class="error-message">
            <?= htmlspecialchars($content ?? 'Die von Ihnen gesuchte Seite existiert nicht oder wurde verschoben.'); ?>
        </p>
        
        <div class="error-illustration">
            <!-- Einfache SVG-Illustration für 404-Fehler -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path fill="#e74c3c" d="M256 0C114.6 0 0 114.6 0 256s114.6 256 256 256s256-114.6 256-256S397.4 0 256 0zM256 464c-114.7 0-208-93.31-208-208S141.3 48 256 48s208 93.31 208 208S370.7 464 256 464z"/>
                <path fill="#e74c3c" d="M256 304c13.25 0 24-10.75 24-24v-128C280 138.8 269.3 128 256 128S232 138.8 232 152v128C232 293.3 242.8 304 256 304z"/>
                <path fill="#e74c3c" d="M256 337.1c-17.36 0-31.44 14.08-31.44 31.44C224.6 385.9 238.6 400 256 400s31.44-14.08 31.44-31.44C287.4 351.2 273.4 337.1 256 337.1z"/>
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