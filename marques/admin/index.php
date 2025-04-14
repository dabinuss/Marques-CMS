<?php
declare(strict_types=1);

// Starte globales Output-Buffering als allererstes, bevor irgendetwas anderes passiert
ob_start();

ini_set('display_errors', '1'); // Fehler im Browser anzeigen (Unsicher für Produktion!)
ini_set('display_startup_errors', '1'); // Auch Startfehler anzeigen
error_reporting(E_ALL); // Alle Fehler melden

// HTTP Security Headers setzen
// 👇 Nonce für CSP (Cryptographically Secure)
$nonce = base64_encode(random_bytes(16));
define('CSP_NONCE', $nonce);

// 👇 Strict Policy mit Safe Defaults
header("Content-Security-Policy: " . implode('; ', [
    "default-src 'none'", // 👈 Default: Alles verboten
    "script-src 'self' 'nonce-".CSP_NONCE."'",
    "style-src 'self' 'nonce-".CSP_NONCE."'",
    "img-src 'self' data:",
    "font-src 'self'",
    "connect-src 'self'",
    "form-action 'self'",
    "frame-src 'none'", // 👇 Keine Iframes erlaubt
    "base-uri 'self'" // 👉 Schutz gegen DOM-Manipulation
]));

// 👇 Essential Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

/**
 * marques CMS - Admin-Panel index.php
 * 
 * Haupteinstiegspunkt für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
*/

$rootContainer = require_once __DIR__ . '/../lib/boot/bootstrap.php';

require_once MARQUES_ROOT_DIR . '/admin/lib/core/MarquesAdmin.php';

try {
    $adminApp = new \Admin\Core\MarquesAdmin($rootContainer);
    $adminApp->init();
    $adminApp->run();
} catch (\Throwable $e) {
    // Zeige den Fehler direkt an, statt der generischen Meldung
    echo "<pre>"; // Für bessere Lesbarkeit
    echo "FATAL UNHANDLED EXCEPTION in admin/index.php:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
    echo "</pre>";
    // error_log(...) kannst du drin lassen
    error_log("FATAL UNHANDLED EXCEPTION in admin/index.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit; // Beende die Ausführung hier
}

// Buffer ausgeben falls nicht bereits geschehen
if (ob_get_level() > 0) {
    ob_end_flush();
}