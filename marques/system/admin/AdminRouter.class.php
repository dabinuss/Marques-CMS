<?php
namespace Marques\Admin;

class AdminRouter
{
    /**
     * Bestimmt die entsprechende Admin-Seite basierend auf einem GET-Parameter und liefert den Pfad zur Template-Datei.
     *
     * @return string Pfad zur Admin-Template-Datei.
     */
    public function route(): string
    {
        // Definiere erlaubte Seiten
        $allowedPages = ['dashboard', 'pages', 'blog', 'media', 'users', 'settings', 'statistics'];
        $page = $_GET['page'] ?? 'dashboard';
        if (!in_array($page, $allowedPages, true)) {
            $page = 'dashboard';
        }

        /*
        // Ermittel den Pfad zur entsprechenden Template-Datei, z.B. in /admin/pages/
        $pageFile = MARQUES_ROOT_DIR . '/admin/pages/' . $page . '.php';
        if (!file_exists($pageFile)) {
            throw new \Exception("Seite nicht gefunden.");
        }
        */
        
        // Rückgabe des ermittelten Template-Pfads
        return $page;
    }
}
