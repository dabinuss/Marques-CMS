<?php
declare(strict_types=1);

namespace Admin\Controller;

use Admin\Core\Controller as AdminController;
use Admin\Auth\Service;
use Marques\Http\Request;
use Marques\Http\Response\ViewResponse;
use Marques\Http\Response\RedirectResponse;
use Marques\Core\Node;

class AuthController extends AdminController
{
    private Service $authService;

    public function __construct(Node $container) 
    {
        parent::__construct($container);
        $this->authService = $container->get(Service::class);
    }

    /**
     * Zeigt das Login-Formular an.
     */
    public function showLoginForm(Request $request, array $params): ViewResponse|RedirectResponse
    {
        // Falls bereits eingeloggt, direkt zum Dashboard weiterleiten
        if ($this->authService->isLoggedIn()) {
            return $this->redirectToRoute('admin.dashboard');
        }

        $csrf_token = $this->authService->generateCsrfToken();
        
        $showAdminDefaultPassword = $this->checkIfShouldShowDefaultCredentials();

        // Greife sicher auf den 'redirect' GET-Parameter zu
        $redirect = isset($_GET['redirect']) ? filter_var($_GET['redirect'], FILTER_SANITIZE_URL) : '';
        if (!empty($redirect) && (strpos($redirect, '/') !== 0 || strpos($redirect, '//') === 0)) {
             $redirect = ''; // Ungültigen Redirect verwerfen
        }

        // View-Daten zusammenstellen
        $viewData = [
            'page_title' => 'Admin Login',
            'csrf_token' => $csrf_token,
            'error' => '',
            'username' => '',
            'redirect' => $redirect,
            'showAdminDefaultPassword' => $showAdminDefaultPassword
        ];

        return $this->view('login', $viewData);
    }

    public function handleLogin(Request $request, array $params): ViewResponse|RedirectResponse
    {
        // CSRF-Token überprüfen
        $postData = $request->getAllPost();
        if (!isset($postData['csrf_token']) || !$this->authService->validateCsrfToken($postData['csrf_token'])) {
            return $this->view('login', [
                'error' => 'Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu.',
                'username' => $postData['username'] ?? '',
                'csrf_token' => $this->authService->generateCsrfToken(),
                'redirect' => $_POST['redirect'] ?? ''
            ]);
        }

        $username = trim($postData['username'] ?? '');
        $password = $postData['password'] ?? '';
        $redirect = isset($postData['redirect']) ? $postData['redirect'] : '';

        // Login-Versuch
        if (!empty($username) && !empty($password) && $this->authService->login($username, $password)) {
            // Leere alle Output-Buffer für sauberes Redirect
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Für initiale Einrichtung
            if (isset($_SESSION['marques_user']['initial_login']) && $_SESSION['marques_user']['initial_login'] === true) {
                $setupUrl = $this->adminUrl('admin.settings') . '?initial_setup=true';
                return $this->redirect($setupUrl);
            }

            // Redirect URL verarbeiten
            $targetUrl = $this->adminUrl('admin.dashboard');
            
            if (!empty($redirect)) {
                $loginUrlPath = rtrim(parse_url($this->adminUrl('admin.login'), PHP_URL_PATH), '/');
                $redirectUrlPath = rtrim(parse_url($redirect, PHP_URL_PATH), '/');

                if ($redirectUrlPath !== $loginUrlPath && strpos($redirect, '/admin/') === 0 && substr($redirect, 0, 2) !== '//') {
                    $targetUrl = $redirect;
                }
            }

            return $this->redirect($targetUrl);
        } else {
            // Login fehlgeschlagen
            return $this->view('login', [
                'error' => 'Ungültiger Benutzername oder Passwort.',
                'username' => $username,
                'csrf_token' => $this->authService->generateCsrfToken(),
                'redirect' => $_POST['redirect'] ?? $_GET['redirect'] ?? ''
            ]);
        }
    }

    /**
     * Prüft, ob die Standard-Anmeldedaten angezeigt werden sollen.
     */
    private function checkIfShouldShowDefaultCredentials(): bool {
        return false; // Vereinfachte Version
    }

    /**
     * Loggt den Benutzer aus und leitet zur Login-Seite weiter.
     */
    public function logout(Request $request, array $params): RedirectResponse
    {
        // Leere alle Output-Buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        $this->authService->logout();
        
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
    
        return $this->redirect($this->adminUrl('admin.login'));
    }
}