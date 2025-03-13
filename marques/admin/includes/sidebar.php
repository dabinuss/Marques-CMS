<?php
// Aktuelle Seite ermitteln
$current_page = basename($_SERVER['SCRIPT_NAME']);

// AppConfig für das Logo verwenden
$configManager = \Marques\Core\AppConfig::getInstance();
$systemConfig = $configManager->load('system') ?: [];
?>

<aside class="admin-sidebar">

    <div class="admin-sidebar-header">
        <h1 class="admin-brand">
            <a href="index.php">
                <img src="<?php echo marques_site_url('assets/images/logo-text-bg.jpg'); ?>" alt="marques CMS" />
            </a>
            <!-- marques CMS -->
        </h1>
        <div class="admin-actions sidebar">
            <p class="admin-button">
                Angemeldet als: <?php echo htmlspecialchars($user->getCurrentUsername()); ?>
            </p>
            <a href="../" class="admin-button" target="_blank">
                <span class="admin-button-icon"><i class="fas fa-external-link-alt"></i></span>
                Website ansehen
            </a>
        </div>
    </div>

    <nav>
        <ul class="admin-menu">
            <li class="admin-menu-item">
                <a href="index.php" class="admin-menu-link <?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                    Dashboard
                </a>
            </li>
            <li class="admin-menu-item">
                <a href="pages.php" class="admin-menu-link <?php echo $current_page === 'pages.php' || $current_page === 'page-edit.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-file-alt"></i></span>
                    Seiten
                </a>
            </li>
            <li class="admin-menu-item">
                <a href="blog.php" class="admin-menu-link <?php echo $current_page === 'blog.php' || $current_page === 'blog-categories.php' || $current_page === 'blog-tags.php' || $current_page === 'blog-edit.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-blog"></i></span>
                    Blog
                </a>
                <ul class="admin-submenu">
                    <li><a href="blog.php" class="admin-submenu-link <?php echo $current_page === 'blog.php' ? 'active' : ''; ?>">Alle Beiträge</a></li>
                    <li><a href="blog-edit.php" class="admin-submenu-link <?php echo $current_page === 'blog-edit.php' ? 'active' : ''; ?>">Neuer Beitrag</a></li>
                    <li><a href="blog-categories.php" class="admin-submenu-link <?php echo $current_page === 'blog-categories.php' ? 'active' : ''; ?>">Kategorien</a></li>
                    <li><a href="blog-tags.php" class="admin-submenu-link <?php echo $current_page === 'blog-tags.php' ? 'active' : ''; ?>">Tags</a></li>
                </ul>
            </li>
            <li class="admin-menu-item">
                <a href="media.php" class="admin-menu-link <?php echo $current_page === 'media.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-images"></i></span>
                    Medien
                </a>
            </li>
            <li class="admin-menu-item">
                <a href="navigation.php" class="admin-menu-link <?php echo $current_page === 'navigation.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-bars"></i></span>
                    Menüs
                </a>
            </li>
            <?php if (isset($user) && $user->isAdmin()): ?>
            <li class="admin-menu-item">
                <a href="users.php" class="admin-menu-link <?php echo $current_page === 'users.php' || $current_page === 'user-edit.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-users"></i></span>
                    Benutzer
                </a>
            </li>
            <li class="admin-menu-item">
                <a href="settings.php" class="admin-menu-link <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                    <span class="admin-menu-icon"><i class="fas fa-cog"></i></span>
                    Einstellungen
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="admin-logout">
        <a href="logout.php" class="admin-logout-link">
            <i class="fas fa-sign-out-alt"></i> Abmelden
        </a>
        <div class="admin-version">
            marques CMS v<?php echo MARQUES_VERSION; ?>
        </div>
    </div>

</aside>