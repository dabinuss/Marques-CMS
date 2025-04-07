<?php
declare(strict_types=1);

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

$adminApp = new \Admin\Core\MarquesAdmin($rootContainer);
$adminApp->init();
$adminApp->run();