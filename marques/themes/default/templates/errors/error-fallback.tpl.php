<?php
/**
 * marques CMS - Fallback-Fehlertemplate
 * 
 * Generisches Fallback-Template für Fehlerseiten, wenn das spezifische Template fehlt.
 *
 * @package marques
 * @subpackage templates
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler <?php echo (int)($error_code ?? 500); ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            text-align: center;
            background-color: #f8f9fa;
        }
        .error-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
        }
        .error-code {
            font-size: 72px;
            font-weight: bold;
            margin: 0;
            color: #d9534f;
        }
        .error-title {
            font-size: 24px;
            margin: 10px 0 20px;
        }
        .error-message {
            color: #6c757d;
            margin-bottom: 30px;
        }
        .home-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 500;
        }
        .home-button:hover {
            background-color: #0069d9;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-code"><?php echo (int)($error_code ?? 500); ?></h1>
        
        <h2 class="error-title">
            <?php 
            switch ($error_code ?? 500) {
                case 404:
                    echo 'Seite nicht gefunden';
                    break;
                case 403:
                    echo 'Zugriff verweigert';
                    break;
                default:
                    echo 'Ein Fehler ist aufgetreten';
            }
            ?>
        </h2>
        
        <p class="error-message">
            <?php echo htmlspecialchars($content ?? 'Es ist ein unerwarteter Fehler aufgetreten.'); ?>
        </p>
        
        <a href="/" class="home-button">Zurück zur Startseite</a>
    </div>

    <?php if (isset($debug) && $debug === true && isset($exception)): ?>
    <div style="max-width: 800px; margin: 20px auto; text-align: left; background: #f8f9fa; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h3 style="color: #d9534f;">Debug-Informationen</h3>
        <div style="background: white; padding: 15px; border-radius: 4px; margin-top: 10px;">
            <p><strong>Fehlermeldung:</strong> <?php echo htmlspecialchars($exception->getMessage()); ?></p>
            <p><strong>Datei:</strong> <?php echo htmlspecialchars($exception->getFile()); ?></p>
            <p><strong>Zeile:</strong> <?php echo (int)$exception->getLine(); ?></p>
            
            <h4>Stack Trace:</h4>
            <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($exception->getTraceAsString()); ?></pre>
        </div>
        <p style="font-size: 12px; color: #6c757d; margin-top: 10px;">Diese detaillierte Fehlermeldung wird nur im Debug-Modus angezeigt.</p>
    </div>
    <?php endif; ?>
</body>
</html>