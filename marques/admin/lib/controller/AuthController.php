<?php
declare(strict_types=1);

namespace Admin\Controller;

use Admin\Auth\Service;
use Admin\Core\Template;
use Admin\Http\Router as AdminRouter;
use Marques\Util\Helper;
use Marques\Http\Request;

class AuthController
{
    private Template $template;
    private Service $service;
    private AdminRouter $adminRouter;
    private Helper $helper;

    public function __construct(Template $template, Service $service, Helper $helper, AdminRouter $adminRouter) {
        $this->template    = $template;
        $this->service     = $service;
        $this->helper      = $helper;
        $this->adminRouter = $adminRouter;
    }

    /**
     * Zeigt das Login-Formular an.
     */
    public function showLoginForm(Request $request, array $params): void {
        // Falls bereits eingeloggt, direkt zum Dashboard weiterleiten
        if ($this->service->isLoggedIn()) { // Prüft jetzt OHNE Regeneration
            // ... (Redirect-Logik wie vorher) ...
            $this->performRedirect($this->adminRouter->getAdminUrl('admin.dashboard'), headers_sent());
        }

        $csrf_token = $this->service->generateCsrfToken();
        $showAdminDefaultPassword = $this->checkIfShouldShowDefaultCredentials();

        // --- START KORREKTUR ---
        // Greife sicher auf den 'redirect' GET-Parameter zu
        $redirect = isset($_GET['redirect']) ? filter_var($_GET['redirect'], FILTER_SANITIZE_URL) : '';
        // Optional: Zusätzliche Validierung, ob es ein relativer Pfad ist etc.
        if (!empty($redirect) && (strpos($redirect, '/') !== 0 || strpos($redirect, '//') === 0)) {
             $redirect = ''; // Ungültigen Redirect verwerfen
        }
        // --- ENDE KORREKTUR ---

        $viewData = [
            'page_title' => 'Admin Login',
            'csrf_token' => $csrf_token,
            'error' => '',
            'username' => '',
            'redirect' => $redirect, // Bereinigten Wert verwenden
            'showAdminDefaultPassword' => $showAdminDefaultPassword
        ];

        $this->template->render($viewData, 'login');
    }

    public function handleLogin(Request $request, array $params): void {
        // CSRF-Token überprüfen
        $postData = $request->getAllPost(); // Besser Request-Objekt nutzen
        if (!isset($postData['csrf_token']) || !$this->service->validateCsrfToken($postData['csrf_token'])) {
            // ... (Fehlerbehandlung für CSRF) ...
            $this->renderLoginWithError(
                'Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu.',
                $postData['username'] ?? ''
            );
            return;
        }

        $username = trim($postData['username'] ?? '');
        $password = $postData['password'] ?? '';
        $redirect = isset($postData['redirect']) ? $postData['redirect'] : '';

        // Login-Versuch über den Service
        if (!empty($username) && !empty($password) && $this->service->login($username, $password)) {

            // Leere alle Output-Buffer, bevor Header gesendet werden
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $headersSent = headers_sent();

            // Falls initialer Setup-Flow notwendig ist (Flag aus Service::login())
            // Das Flag muss im Service::login korrekt gesetzt werden
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                // Annahme: Es gibt eine Route für die Benutzerbearbeitung
                // $setupUrl = $this->adminRouter->getAdminUrl('admin.user.edit', ['id' => $userId]); // Besser ID verwenden
                 $setupUrl = $this->adminRouter->getAdminUrl('admin.settings') . '?initial_setup=true'; // Beispiel: Zu Settings leiten
                $this->performRedirect($setupUrl, $headersSent);
            }

            // Redirect URL verarbeiten
            $targetUrl = $this->adminRouter->getAdminUrl('admin.dashboard'); // Standard: Dashboard
            if (!empty($redirect)) {
                // Sicherheitsprüfung und Loop-Verhinderung für Redirect
                $loginUrlPath = rtrim(parse_url($this->adminRouter->getAdminUrl('admin.login'), PHP_URL_PATH), '/');
                $redirectUrlPath = rtrim(parse_url($redirect, PHP_URL_PATH), '/');

                // Nur relative Pfade innerhalb von /admin/ erlauben (oder anpassen) und nicht /admin/login
                if ($redirectUrlPath !== $loginUrlPath && strpos($redirect, '/admin/') === 0 && substr($redirect, 0, 2) !== '//') {
                    $targetUrl = $redirect;
                }
            }

            // Finalen Redirect durchführen
            $this->performRedirect($targetUrl, $headersSent);

        } else {
            // Login fehlgeschlagen
            $this->renderLoginWithError(
                'Ungültiger Benutzername oder Passwort.',
                $username
            );
        }
    }

    // Hilfsmethode zum Rendern des Login-Formulars mit Fehlern
    private function renderLoginWithError(string $error, string $username): void {
         $viewData = [
            'page_title' => 'Admin Login',
            'csrf_token' => $this->service->generateCsrfToken(), // Neuen Token für erneuten Versuch
            'error' => $error,
            'username' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
            'redirect' => $_POST['redirect'] ?? $_GET['redirect'] ?? '', // Redirect beibehalten
            'showAdminDefaultPassword' => $this->checkIfShouldShowDefaultCredentials()
         ];
         // Status Code 401 für fehlgeschlagenen Login setzen (optional aber gut für APIs/JS)
         // http_response_code(401);
         $this->template->render($viewData, 'login');
    }

     // Hilfsmethode für Redirects
     private function performRedirect(string $url, bool $headersAlreadySent): void {
         if ($headersAlreadySent) {
             echo '<script>window.location.href = "' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
             echo '<noscript>Weiterleitung zu <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>.</noscript>';
         } else {
             header('Location: ' . $url, true, 302); // Explizit 302 setzen
         }
         exit;
     }

    private function isValidRedirectUrl(string $url): bool {
        // Akzeptiere nur relative URLs, die mit / beginnen
        if (substr($url, 0, 1) !== '/') {
            return false;
        }
        
        // Verhindere Protocol-Relative URLs (//example.com)
        if (substr($url, 0, 2) === '//') {
            return false;
        }
        
        // Optional: Beschränke auf bestimmte Pfade
        if (substr($url, 0, 7) === '/admin/') {
            return true;
        }
        
        return false;
    }

    /**
     * Prüft, ob die Standard-Anmeldedaten angezeigt werden sollen.
     */
    private function checkIfShouldShowDefaultCredentials(): bool {
        // Beispielimplementierung: Prüfe, ob es sich um eine neue Installation handelt 
        // oder ob der Standard-Admin-Account existiert und noch das Standard-Passwort hat
        
        // Du könntest hier eine Konfigurationsdatei prüfen
        // oder den Datenbankstatus überprüfen (z.B. existieren Benutzer)
        
        // Beispiel: Angenommen, es gibt eine Konfigurationsdatei oder eine Datenbanktabelle
        // die anzeigt, ob das CMS frisch installiert wurde
       // $isNewInstallation = $this->helper->isNewInstallation() ?? false;
        $isNewInstallation = false;
        
        return $isNewInstallation;
        
        // Alternativ, wenn diese Methode derzeit nicht implementiert werden kann:
        // return false; // standardmäßig ausblenden
    }

    /**
     * Loggt den Benutzer aus und leitet zur Login-Seite weiter.
     */
    public function logout(Request $request, array $params): void {
        // Leere alle Output-Buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        $this->service->logout();
    
        // Session-Daten leeren und Session zerstören
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    
        header('Location: ' . $this->adminRouter->getAdminUrl('admin.login'));
        exit;
    }
}