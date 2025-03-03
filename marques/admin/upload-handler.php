<?php
/**
 * marques CMS - Media Upload Handler
 * 
 * Verarbeitet Medien-Uploads für TinyMCE.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Antwort-Header setzen
header('Content-Type: application/json');

// Konfiguration laden
$system_config = require MARCES_CONFIG_DIR . '/system.config.php';

// Fehlerfunktion
function returnError($message) {
    echo json_encode(['error' => ['message' => $message]]);
    exit;
}

// Upload-Verzeichnis erstellen, falls nicht vorhanden
$uploadDir = MARCES_ROOT_DIR . '/assets/media';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        returnError('Upload-Verzeichnis konnte nicht erstellt werden.');
    }
}

// Prüfen, ob eine Datei hochgeladen wurde
if (!isset($_FILES['file']) || empty($_FILES['file']['name'])) {
    returnError('Keine Datei hochgeladen.');
}

$file = $_FILES['file'];

// Datei validieren
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    returnError('Ungültiger Dateityp. Erlaubt sind nur JPG, PNG, GIF und WEBP.');
}

// Maximale Dateigröße (5 MB)
$maxFileSize = 5 * 1024 * 1024;
if ($file['size'] > $maxFileSize) {
    returnError('Die Datei ist zu groß (max. 5 MB).');
}

// Prüfen, ob die Datei bereits existiert (basierend auf MD5-Hash)
$fileHash = md5_file($file['tmp_name']);
$existingFiles = glob($uploadDir . '/*');
$fileExists = false;
$existingFilename = '';

foreach ($existingFiles as $existingFile) {
    if (is_file($existingFile)) {
        $existingHash = md5_file($existingFile);
        if ($existingHash === $fileHash) {
            $fileExists = true;
            $existingFilename = basename($existingFile);
            break;
        }
    }
}

// Wenn die Datei bereits existiert, verwende die bestehende
if ($fileExists) {
    $uniqueName = $existingFilename;
} else {
    // Sicheren Dateinamen generieren
    $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($file['name']));
    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $safeBasename = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($filename, PATHINFO_FILENAME));
    
    // Stellen sicher, dass der Basisname nicht leer ist
    if (empty($safeBasename)) {
        $safeBasename = 'image';
    }
    
    $uniqueName = uniqid() . '_' . $safeBasename . '.' . $fileExt;
    $filePath = $uploadDir . '/' . $uniqueName;
    
    // Datei verschieben
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        returnError('Fehler beim Hochladen der Datei.');
    }
}

// Absolute URL mit Domain zum Bild für TinyMCE erzeugen
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$domain = $_SERVER['HTTP_HOST'];

// Bestimme den Basis-URL (außerhalb des Admin-Verzeichnisses)
$scriptPath = dirname($_SERVER['SCRIPT_NAME']); // z.B. /marques/admin
$baseUrl = dirname($scriptPath); // z.B. /marques

// Stellen wir sicher, dass baseUrl nicht leer ist
$baseUrl = $baseUrl === '/' ? '' : $baseUrl;

$fileUrl = $protocol . '://' . $domain . $baseUrl . '/assets/media/' . $uniqueName;

// Erfolgreiche Antwort senden
echo json_encode([
    'location' => $fileUrl
]);