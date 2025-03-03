<?php
/**
 * marces CMS - TinyMCE Self-Hosted Installer
 * 
 * Dieses Skript lädt die TinyMCE-Bibliothek herunter und installiert sie für die Self-Hosted-Verwendung
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));
define('MARCES_ADMIN_DIR', __DIR__);

// Zielverzeichnis für TinyMCE
$targetDir = MARCES_ADMIN_DIR . '/assets/js/tinymce';

// Temporärer Download-Ordner
$tempDir = sys_get_temp_dir() . '/tinymce_download';

// TinyMCE-Downloadlink für Version 6.7.0 (die letzte Version 6.x)
$tinyMceUrl = 'https://download.tiny.cloud/tinymce/community/tinymce_6.7.0.zip';

echo "marces CMS - TinyMCE Self-Hosted Installer\n";
echo "--------------------------------------\n\n";

// Prüfen, ob das Zielverzeichnis bereits existiert
if (is_dir($targetDir)) {
    echo "TinyMCE ist bereits installiert in: $targetDir\n";
    echo "Möchten Sie die bestehende Installation überschreiben? (j/n): ";
    $response = trim(fgets(STDIN));
    
    if (strtolower($response) !== 'j') {
        echo "Installation abgebrochen.\n";
        exit(0);
    }
    
    // Bestehendes Verzeichnis löschen
    echo "Lösche bestehende Installation...\n";
    system("rm -rf " . escapeshellarg($targetDir));
}

// Temporäres Verzeichnis erstellen
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// TinyMCE herunterladen
echo "Lade TinyMCE herunter von: $tinyMceUrl\n";
$zipFile = $tempDir . '/tinymce.zip';

// Versuche, file_get_contents zu verwenden, oder fallback auf curl
$content = @file_get_contents($tinyMceUrl);
if ($content === false) {
    echo "Versuche Download mit cURL...\n";
    $ch = curl_init($tinyMceUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $content = curl_exec($ch);
    curl_close($ch);
    
    if ($content === false) {
        echo "Fehler beim Herunterladen von TinyMCE!\n";
        exit(1);
    }
}

if (file_put_contents($zipFile, $content) === false) {
    echo "Fehler beim Speichern der ZIP-Datei!\n";
    exit(1);
}

// ZIP-Datei entpacken
echo "Entpacke ZIP-Datei...\n";
$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    $zip->extractTo($tempDir);
    $zip->close();
} else {
    echo "Fehler beim Entpacken der ZIP-Datei!\n";
    exit(1);
}

// Zielverzeichnis erstellen
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Dateien kopieren
echo "Installiere TinyMCE in: $targetDir\n";

// Für Windows
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Verwende xcopy für Windows
    system('xcopy "' . $tempDir . '\tinymce" "' . $targetDir . '" /E /I /Y');
} else {
    // Für Unix/Linux/Mac
    system("cp -R " . escapeshellarg($tempDir . '/tinymce') . "/* " . escapeshellarg($targetDir));
}

// Sprachdatei prüfen
$langDir = $targetDir . '/langs';
if (!is_dir($langDir)) {
    mkdir($langDir, 0755, true);
}

$deLanguageFile = $langDir . '/de.js';
if (!file_exists($deLanguageFile)) {
    echo "Deutsche Sprachdatei nicht gefunden, lade sie herunter...\n";
    $langUrl = 'https://cdn.tiny.cloud/1/no-api-key/tinymce/6/langs/de.js';
    $langContent = @file_get_contents($langUrl);
    
    if ($langContent === false) {
        echo "Versuche Download mit cURL...\n";
        $ch = curl_init($langUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $langContent = curl_exec($ch);
        curl_close($ch);
    }
    
    if ($langContent !== false) {
        file_put_contents($deLanguageFile, $langContent);
        echo "Deutsche Sprachdatei heruntergeladen und installiert.\n";
    } else {
        echo "Warnung: Konnte deutsche Sprachdatei nicht herunterladen.\n";
    }
}

// Temporäres Verzeichnis aufräumen
echo "Räume temporäre Dateien auf...\n";
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    system('rmdir /S /Q "' . $tempDir . '"');
} else {
    system("rm -rf " . escapeshellarg($tempDir));
}

echo "\nTinyMCE wurde erfolgreich im Admin-Bereich installiert!\n";
echo "Die TinyMCE-Dateien befinden sich jetzt in: $targetDir\n";