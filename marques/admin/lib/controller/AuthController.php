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
        if ($this->service->isLoggedIn()) {
            header('Location: ' . $this->adminRouter->getAdminUrl('admin.dashboard'));
            exit;
        }

        // CSRF-Token generieren
        $csrf_token = $this->service->generateCsrfToken();

        // Daten für das Login-Template zusammenstellen
        $viewData = [
            'page_title' => 'Admin Login',
            'csrf_token' => $csrf_token,
            'error'      => '',
            'username'   => ''
        ];

        // Rendern des Login-Views (z.B. admin/lib/templates/login.phtml)
        $this->template->render($viewData, 'login');
    }

    /**
     * Verarbeitet die Login-Daten.
     */
    public function handleLogin(Request $request, array $params): void {
        // CSRF-Token überprüfen
        if (!isset($_POST['csrf_token']) || !$this->service->validateCsrfToken($_POST['csrf_token'])) {
            $error = 'Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
            $viewData = [
                'page_title' => 'Admin Login',
                'csrf_token' => $this->service->generateCsrfToken(),
                'error'      => $error,
                'username'   => htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8')
            ];
            $this->template->render($viewData, 'login');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Login-Versuch
        if (!empty($username) && !empty($password) && $this->service->login($username, $password)) {
            session_regenerate_id(true);
            // Neuer CSRF-Token nach erfolgreichem Login
            $this->service->generateCsrfToken();

            // Falls initialer Setup-Flow notwendig ist
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                header('Location: ' . $this->adminRouter->getAdminUrl('admin.user.edit') . '?username=admin&initial_setup=true');
                exit;
            }

            header('Location: ' . $this->adminRouter->getAdminUrl('admin.dashboard'));
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort.';
            $viewData = [
                'page_title' => 'Admin Login',
                'csrf_token' => $this->service->generateCsrfToken(),
                'error'      => $error,
                'username'   => htmlspecialchars($username, ENT_QUOTES, 'UTF-8')
            ];
            $this->template->render($viewData, 'login');
        }
    }

    /**
     * Loggt den Benutzer aus und leitet zur Login-Seite weiter.
     */
    public function logout(Request $request, array $params): void {
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
