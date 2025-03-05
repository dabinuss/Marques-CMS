<?php
/**
 * marques CMS - Systemeinstellungen
 * 
 * Verwaltung der Systemeinstellungen im Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARQUES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARQUES_ROOT_DIR . '/system/core/Bootstrap.inc.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// Nur Administratoren dürfen auf diese Seite zugreifen
if (!$user->isAdmin()) {
    header('Location: index.php');
    exit;
}

// Settings Manager initialisieren
$settings = new \Marques\Core\SettingsManager();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Meldungsvariablen
$success_message = '';
$error_message = '';

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        // Gruppe bestimmen
        $group = $_POST['settings_group'] ?? 'general';
        
        // Einstellungen basierend auf der Gruppe aktualisieren
        switch ($group) {
            case 'general':
                $settings->setMultipleSettings([
                    'site_name' => $_POST['site_name'] ?? '',
                    'site_description' => $_POST['site_description'] ?? '',
                    'admin_email' => $_POST['admin_email'] ?? '',
                    'contact_email' => $_POST['contact_email'] ?? '',
                    'contact_phone' => $_POST['contact_phone'] ?? '',
                ]);
                break;
                
            case 'social':
                $settings->setSetting('social_links.facebook', $_POST['social_facebook'] ?? '');
                $settings->setSetting('social_links.twitter', $_POST['social_twitter'] ?? '');
                $settings->setSetting('social_links.instagram', $_POST['social_instagram'] ?? '');
                $settings->setSetting('social_links.linkedin', $_POST['social_linkedin'] ?? '');
                $settings->setSetting('social_links.youtube', $_POST['social_youtube'] ?? '');
                break;
                
            case 'content':
                $settings->setMultipleSettings([
                    'posts_per_page' => (int)($_POST['posts_per_page'] ?? 10),
                    'excerpt_length' => (int)($_POST['excerpt_length'] ?? 150),
                    'comments_enabled' => isset($_POST['comments_enabled']),
                    'blog_url_format'   => $_POST['blog_url_format'] ?? 'date_slash',
                ]);
                break;
                
            case 'system':
                $settings->setMultipleSettings([
                    'debug' => isset($_POST['debug']),
                    'cache_enabled' => isset($_POST['cache_enabled']),
                    'maintenance_mode' => isset($_POST['maintenance_mode']),
                    'maintenance_message' => $_POST['maintenance_message'] ?? '',
                    'timezone' => $_POST['timezone'] ?? 'Europe/Berlin',
                    'date_format' => $_POST['date_format'] ?? 'd.m.Y',
                    'time_format' => $_POST['time_format'] ?? 'H:i',
                ]);
                break;
                
            case 'appearance':
                $settings->setMultipleSettings([
                    'active_theme' => $_POST['active_theme'] ?? '',
                ]);
                break;

            case 'seo':
                $settings->setMultipleSettings([
                    'meta_keywords' => $_POST['meta_keywords'] ?? '',
                    'meta_author' => $_POST['meta_author'] ?? '',
                    'google_analytics_id' => $_POST['google_analytics_id'] ?? '',
                ]);
                break;
        }
        
        // Einstellungen speichern
        if ($settings->saveSettings()) {
            $success_message = 'Einstellungen wurden erfolgreich gespeichert.';
        } else {
            $error_message = 'Fehler beim Speichern der Einstellungen.';
        }
    }

    // Sicherstellen, dass base_url korrekt ist, falls manuell geändert
    if (isset($_POST['base_url'])) {
        $baseUrl = $_POST['base_url'];
        if (strpos($baseUrl, '/admin') !== false) {
            $_POST['base_url'] = preg_replace('|/admin$|', '', $baseUrl);
        }
    }
}

// Aktuelle Einstellungen laden
$current_settings = $settings->getAllSettings();

// Aktiven Tab bestimmen
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
$allowed_tabs = ['general', 'social', 'content', 'system', 'seo', 'appearance'];

if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'general';
}

// Titel der Seite
$page_title = 'Systemeinstellungen';

// Liste der Zeitzonen
$timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

// Liste der Datumsformate
$date_formats = [
    'd.m.Y' => date('d.m.Y'),
    'Y-m-d' => date('Y-m-d'),
    'd/m/Y' => date('d/m/Y'),
    'm/d/Y' => date('m/d/Y'),
    'j. F Y' => date('j. F Y'),
];

// Liste der Zeitformate
$time_formats = [
    'H:i' => date('H:i'),
    'H:i:s' => date('H:i:s'),
    'g:i a' => date('g:i a'),
    'g:i A' => date('g:i A'),
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin-Panel - marques CMS</title>
    <link rel="stylesheet" href="assets/css/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="admin-layout">
        
        <!-- SIDEBAR & NAVIGATION -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="admin-topbar">
                <h2 class="admin-page-title"><?php echo htmlspecialchars($page_title); ?></h2>
            </div>
            
            <?php if (!empty($success_message)): ?>
                <div class="admin-alert success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="admin-alert error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="admin-card">
                <div class="admin-tabs">
                    <a href="?tab=general" class="admin-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Allgemein
                    </a>
                    <a href="?tab=social" class="admin-tab <?php echo $active_tab === 'social' ? 'active' : ''; ?>">
                        <i class="fas fa-share-alt"></i> Social Media
                    </a>
                    <a href="?tab=content" class="admin-tab <?php echo $active_tab === 'content' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt"></i> Inhalt
                    </a>
                    <a href="?tab=system" class="admin-tab <?php echo $active_tab === 'system' ? 'active' : ''; ?>">
                        <i class="fas fa-server"></i> System
                    </a>
                    <a href="?tab=appearance" class="admin-tab <?php echo $active_tab === 'appearance' ? 'active' : ''; ?>">
                        <i class="fas fa-paint-brush"></i> Design
                    </a>
                    <a href="?tab=seo" class="admin-tab <?php echo $active_tab === 'seo' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> SEO
                    </a>
                </div>
                
                <div class="admin-card-content">
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="settings_group" value="<?php echo htmlspecialchars($active_tab); ?>">
                        
                        <?php if ($active_tab === 'general'): ?>
                            <div class="form-group">
                                <label for="site_name">Website-Name</label>
                                <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($current_settings['site_name'] ?? ''); ?>">
                                <p class="form-hint">Der Name Ihrer Website, wird im Browser-Titel angezeigt.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="site_description">Website-Beschreibung</label>
                                <textarea id="site_description" name="site_description" rows="3"><?php echo htmlspecialchars($current_settings['site_description'] ?? ''); ?></textarea>
                                <p class="form-hint">Eine kurze Beschreibung Ihrer Website.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="admin_email">Administrator-E-Mail</label>
                                <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($current_settings['admin_email'] ?? ''); ?>">
                                <p class="form-hint">E-Mail-Adresse des Administrators für Systembenachrichtigungen.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_email">Kontakt-E-Mail</label>
                                <input type="email" id="contact_email" name="contact_email" value="<?php echo htmlspecialchars($current_settings['contact_email'] ?? ''); ?>">
                                <p class="form-hint">Öffentliche E-Mail-Adresse für Kontaktformulare.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_phone">Telefon</label>
                                <input type="text" id="contact_phone" name="contact_phone" value="<?php echo htmlspecialchars($current_settings['contact_phone'] ?? ''); ?>">
                                <p class="form-hint">Öffentliche Telefonnummer für Kontakt.</p>
                            </div>

                        <?php elseif ($active_tab === 'social'): ?>
                            <div class="form-group">
                                <label for="social_facebook">Facebook</label>
                                <div class="input-icon-wrapper">
                                    <span class="input-icon"><i class="fab fa-facebook-f"></i></span>
                                    <input type="text" id="social_facebook" name="social_facebook" value="<?php echo htmlspecialchars($current_settings['social_links']['facebook'] ?? ''); ?>">
                                </div>
                                <p class="form-hint">Vollständige URL zur Facebook-Seite.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="social_twitter">Twitter / X</label>
                                <div class="input-icon-wrapper">
                                    <span class="input-icon"><i class="fab fa-twitter"></i></span>
                                    <input type="text" id="social_twitter" name="social_twitter" value="<?php echo htmlspecialchars($current_settings['social_links']['twitter'] ?? ''); ?>">
                                </div>
                                <p class="form-hint">Vollständige URL zum Twitter/X-Profil.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="social_instagram">Instagram</label>
                                <div class="input-icon-wrapper">
                                    <span class="input-icon"><i class="fab fa-instagram"></i></span>
                                    <input type="text" id="social_instagram" name="social_instagram" value="<?php echo htmlspecialchars($current_settings['social_links']['instagram'] ?? ''); ?>">
                                </div>
                                <p class="form-hint">Vollständige URL zum Instagram-Profil.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="social_linkedin">LinkedIn</label>
                                <div class="input-icon-wrapper">
                                    <span class="input-icon"><i class="fab fa-linkedin-in"></i></span>
                                    <input type="text" id="social_linkedin" name="social_linkedin" value="<?php echo htmlspecialchars($current_settings['social_links']['linkedin'] ?? ''); ?>">
                                </div>
                                <p class="form-hint">Vollständige URL zum LinkedIn-Profil.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="social_youtube">YouTube</label>
                                <div class="input-icon-wrapper">
                                    <span class="input-icon"><i class="fab fa-youtube"></i></span>
                                    <input type="text" id="social_youtube" name="social_youtube" value="<?php echo htmlspecialchars($current_settings['social_links']['youtube'] ?? ''); ?>">
                                </div>
                                <p class="form-hint">Vollständige URL zum YouTube-Kanal.</p>
                            </div>

                        <?php elseif ($active_tab === 'content'): ?>
                            <div class="form-group">
                                <label for="posts_per_page">Beiträge pro Seite</label>
                                <input type="number" id="posts_per_page" name="posts_per_page" min="1" max="50" value="<?php echo (int)($current_settings['posts_per_page'] ?? 10); ?>">
                                <p class="form-hint">Anzahl der Blogbeiträge, die pro Seite angezeigt werden.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="excerpt_length">Auszugslänge</label>
                                <input type="number" id="excerpt_length" name="excerpt_length" min="50" max="500" value="<?php echo (int)($current_settings['excerpt_length'] ?? 150); ?>">
                                <p class="form-hint">Maximale Anzahl an Zeichen für Artikelauszüge.</p>
                            </div>

                            <div class="form-group">
                                <label for="blog_url_format">URL-Format:</label>
                                <select name="blog_url_format" id="blog_url_format" class="form-control">
                                    <option value="date_slash" <?php echo ($current_settings['blog_url_format'] ?? '') === 'date_slash' ? 'selected' : ''; ?>>
                                        Standard (blog/YYYY/MM/DD/slug) - z.B. blog/2025/03/15/mein-beitrag
                                    </option>
                                    <option value="date_dash" <?php echo ($current_settings['blog_url_format'] ?? '') === 'date_dash' ? 'selected' : ''; ?>>
                                        Datum mit Bindestrich (blog/YYYY-MM-DD/slug) - z.B. blog/2025-03-15/mein-beitrag
                                    </option>
                                    <option value="year_month" <?php echo ($current_settings['blog_url_format'] ?? '') === 'year_month' ? 'selected' : ''; ?>>
                                        Jahr/Monat (blog/YYYY/MM/slug) - z.B. blog/2025/03/mein-beitrag
                                    </option>
                                    <option value="numeric" <?php echo ($current_settings['blog_url_format'] ?? '') === 'numeric' ? 'selected' : ''; ?>>
                                        ID-basiert (blog/ID) - z.B. blog/123
                                    </option>
                                    <option value="post_name" <?php echo ($current_settings['blog_url_format'] ?? '') === 'post_name' ? 'selected' : ''; ?>>
                                        Nur Slug (blog/slug) - z.B. blog/mein-beitrag
                                    </option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <p class="text-warning">
                                    <strong>Wichtig:</strong> Das Ändern dieses Formats kann dazu führen, dass bestehende Links auf Ihre Blog-Beiträge nicht mehr funktionieren. Stellen Sie sicher, dass Sie Redirects einrichten, wenn Sie dieses Format für eine bestehende Website ändern.
                                </p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="comments_enabled" <?php echo isset($current_settings['comments_enabled']) && $current_settings['comments_enabled'] ? 'checked' : ''; ?>>
                                    Kommentare aktivieren
                                </label>
                                <p class="form-hint">Erlaubt Besuchern, Beiträge zu kommentieren.</p>
                            </div>
                        
                        <?php elseif ($active_tab === 'system'): ?>
                            <div class="form-group">
                                <label for="timezone">Zeitzone</label>
                                <select id="timezone" name="timezone">
                                    <?php foreach ($timezones as $tz): ?>
                                        <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo ($current_settings['timezone'] ?? 'Europe/Berlin') === $tz ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tz); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">Die Zeitzone, die für die Anzeige von Datum und Uhrzeit verwendet wird.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="date_format">Datumsformat</label>
                                <select id="date_format" name="date_format">
                                    <?php foreach ($date_formats as $format => $example): ?>
                                        <option value="<?php echo htmlspecialchars($format); ?>" <?php echo ($current_settings['date_format'] ?? 'd.m.Y') === $format ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($example . ' (' . $format . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">Das Format, in dem Daten angezeigt werden.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="time_format">Zeitformat</label>
                                <select id="time_format" name="time_format">
                                    <?php foreach ($time_formats as $format => $example): ?>
                                        <option value="<?php echo htmlspecialchars($format); ?>" <?php echo ($current_settings['time_format'] ?? 'H:i') === $format ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($example . ' (' . $format . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-hint">Das Format, in dem Uhrzeiten angezeigt werden.</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="debug" <?php echo isset($current_settings['debug']) && $current_settings['debug'] ? 'checked' : ''; ?>>
                                    Debug-Modus aktivieren
                                </label>
                                <p class="form-hint">Zeigt detaillierte Fehlermeldungen an. Im Produktivbetrieb deaktivieren!</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="cache_enabled" <?php echo isset($current_settings['cache_enabled']) && $current_settings['cache_enabled'] ? 'checked' : ''; ?>>
                                    Caching aktivieren
                                </label>
                                <p class="form-hint">Caching verbessert die Leistung Ihrer Website.</p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="maintenance_mode" <?php echo isset($current_settings['maintenance_mode']) && $current_settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                    Wartungsmodus aktivieren
                                </label>
                                <p class="form-hint">Zeigt Besuchern eine Wartungsseite an. Administratoren können weiterhin auf die Website zugreifen.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="maintenance_message">Wartungsnachricht</label>
                                <textarea id="maintenance_message" name="maintenance_message" rows="3"><?php echo htmlspecialchars($current_settings['maintenance_message'] ?? 'Die Website wird aktuell gewartet. Bitte versuchen Sie es später erneut.'); ?></textarea>
                                <p class="form-hint">Diese Nachricht wird angezeigt, wenn der Wartungsmodus aktiv ist.</p>
                            </div>
                        
                        <?php elseif ($active_tab === 'appearance'): 
                                // Theme Manager initialisieren
                                $themeManager = new \Marques\Core\ThemeManager();
                                $themes = $themeManager->getThemes();
                                $activeTheme = $themeManager->getActiveTheme();
                            ?>
                            <div class="form-row">
                                <div class="form-column">
                                    <div class="form-group">
                                        <label for="active_theme" class="form-label">Theme auswählen</label>
                                        <select id="active_theme" name="active_theme" class="form-control">
                                            <?php foreach ($themes as $themeId => $theme): ?>
                                                <option value="<?php echo htmlspecialchars($themeId); ?>" <?php echo $activeTheme === $themeId ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($theme['name'] ?? $themeId); ?> 
                                                    <?php if (isset($theme['version'])): ?>(v<?php echo htmlspecialchars($theme['version']); ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-help">Wählen Sie das Theme für Ihre Website.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-column">
                                    <div class="form-help mt-3">
                                        <p><strong>Hinweis:</strong> Neue Themes können im Verzeichnis <code>/themes/</code> installiert werden. 
                                        Jedes Theme benötigt mindestens die Unterordner <code>/assets/</code> und <code>/templates/</code> sowie eine 
                                        <code>theme.json</code> Datei mit grundlegenden Informationen.</p>
                                        
                                        <?php if (isset($themes[$activeTheme]['author'])): ?>
                                            <p class="mt-2">Aktuelles Theme: <strong><?php echo htmlspecialchars($themes[$activeTheme]['name'] ?? $activeTheme); ?></strong> 
                                            von <?php echo htmlspecialchars($themes[$activeTheme]['author']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ($active_tab === 'seo'): ?>
                            <div class="form-group">
                                <label for="meta_keywords">Meta-Keywords</label>
                                <input type="text" id="meta_keywords" name="meta_keywords" value="<?php echo htmlspecialchars($current_settings['meta_keywords'] ?? ''); ?>">
                                <p class="form-hint">Kommagetrennte Liste von Schlüsselwörtern für Suchmaschinen.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="meta_author">Meta-Author</label>
                                <input type="text" id="meta_author" name="meta_author" value="<?php echo htmlspecialchars($current_settings['meta_author'] ?? ''); ?>">
                                <p class="form-hint">Der Autor der Website für Meta-Tags.</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="google_analytics_id">Google Analytics ID</label>
                                <input type="text" id="google_analytics_id" name="google_analytics_id" value="<?php echo htmlspecialchars($current_settings['google_analytics_id'] ?? ''); ?>">
                                <p class="form-hint">Ihre Google Analytics Tracking-ID (z.B. UA-XXXXX-Y).</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" class="admin-button">
                                <i class="fas fa-save"></i> Einstellungen speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
