<?php
/**
 * marques CMS - Admin-Panel
 * 
 * Haupteinstiegspunkt für das Admin-Panel.
 *
 * @package marques
 * @subpackage admin
 */

// Basispfad definieren
define('MARCES_ROOT_DIR', dirname(__DIR__));
define('IS_ADMIN', true);

// Bootstrap laden
require_once MARCES_ROOT_DIR . '/system/core/bootstrap.inc.php';

// Konfiguration laden
$system_config = require MARCES_CONFIG_DIR . '/system.config.php';

// Admin-Klasse initialisieren
$admin = new \Marques\Core\Admin();
$admin->requireLogin();

// Benutzer-Objekt initialisieren
$user = new \Marques\Core\User();

// Admin-Statistiken holen
$stats = $admin->getStatistics();

// CSRF-Token generieren
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Aktuelle Seite bestimmen
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'pages', 'blog', 'media', 'users', 'settings'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Seitentitel bestimmen
$page_titles = [
    'dashboard' => 'Dashboard',
    'pages' => 'Seiten verwalten',
    'blog' => 'Blog verwalten',
    'media' => 'Medienbibliothek',
    'users' => 'Benutzer verwalten',
    'settings' => 'Einstellungen'
];

$page_title = $page_titles[$page] ?? 'Dashboard';

?>
<!DOCTYPE html>
<html lang="<?php echo $system_config['admin_language'] ?? 'de'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin-Panel - <?php echo htmlspecialchars($system_config['site_name'] ?? 'marques CMS'); ?></title>
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
                
                <div class="admin-actions">
                    <?php if ($page === 'pages'): ?>
                    <a href="page-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neue Seite
                    </a>
                    <?php elseif ($page === 'blog'): ?>
                    <a href="blog-edit.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-plus"></i></span>
                        Neuer Beitrag
                    </a>
                    <?php elseif ($page === 'media'): ?>
                    <a href="media-upload.php" class="admin-button">
                        <span class="admin-button-icon"><i class="fas fa-upload"></i></span>
                        Hochladen
                    </a>
                    <?php endif; ?>
                    
                    <a href="../" class="admin-button" target="_blank">
                        <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                        Website ansehen
                    </a>
                </div>
            </div>
            
            <?php if ($page === 'dashboard'): ?>
                <div class="admin-welcome">
                    <h3>Willkommen im Admin-Panel!</h3>
                    <p>Hier können Sie Ihre Website verwalten. Wählen Sie eine Option aus dem Menü auf der linken Seite.</p>
                </div>
                
                <div class="admin-stats">
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="admin-stat-title">Seiten</div>
                        <div class="admin-stat-value"><?php echo $stats['pages']; ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-blog"></i></div>
                        <div class="admin-stat-title">Blog-Beiträge</div>
                        <div class="admin-stat-value"><?php echo $stats['blog_posts']; ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-images"></i></div>
                        <div class="admin-stat-title">Mediendateien</div>
                        <div class="admin-stat-value"><?php echo $stats['media_files']; ?></div>
                    </div>
                    
                    <div class="admin-stat-card">
                        <div class="admin-stat-icon"><i class="fas fa-hdd"></i></div>
                        <div class="admin-stat-title">Speichernutzung</div>
                        <div class="admin-stat-value"><?php echo $stats['disk_usage']; ?></div>
                    </div>
                </div>
                
                <div class="admin-stat-card">
                    <h3>Systeminformationen</h3>
                    <p><strong>PHP-Version:</strong> <?php echo $stats['php_version']; ?></p>
                    <p><strong>marques CMS-Version:</strong> <?php echo $stats['marques_version']; ?></p>
                </div>
            <?php elseif ($page === 'pages'): ?>

                <script>window.location.href = 'pages.php';</script>
                <p>Sie werden zur Seiten-Verwaltung weitergeleitet...</p>
                <p><a href="pages.php">Klicken Sie hier, wenn Sie nicht automatisch weitergeleitet werden.</a></p>

            <?php elseif ($page === 'blog'): ?>
                <!-- Blog-Verwaltung wird implementiert -->
                <p>Die Blog-Verwaltung wird in Kürze implementiert.</p>
            <?php elseif ($page === 'media'): ?>
                <!-- Medien-Verwaltung wird implementiert -->
                <p>Die Medien-Verwaltung wird in Kürze implementiert.</p>
            <?php elseif ($page === 'users' && $user->isAdmin()): ?>
                <!-- Benutzer-Verwaltung wird implementiert -->
                <p>Die Benutzer-Verwaltung wird in Kürze implementiert.</p>
            <?php elseif ($page === 'settings' && $user->isAdmin()): ?>
                <!-- Einstellungen werden implementiert -->
                <p>Die Einstellungen werden in Kürze implementiert.</p>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>