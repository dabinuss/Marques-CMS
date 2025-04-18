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
        if ($this->authService->isLoggedIn()) {
            return $this->redirectToRoute('admin.dashboard');
        }
    
        $csrf_token               = $this->authService->generateCsrfToken();
        $showAdminDefaultPassword = $this->checkIfShouldShowDefaultCredentials();
    
        $query    = $request->getQueryParams();
        $redirect = filter_var($query['redirect'] ?? '', FILTER_SANITIZE_URL);
    
        $viewData = [
            'page_title'               => 'Admin Login',
            'csrf_token'               => $csrf_token,
            'username'                 => '',
            'redirect'                 => $redirect,
            'showAdminDefaultPassword' => $showAdminDefaultPassword,
            'error_message'            => ! empty($query['error'])
                ? '<div class="error-message">Fehler bei der Anmeldung. Bitte versuchen Sie es erneut.</div>'
                : '',
            'default_password_message' => $showAdminDefaultPassword
                ? '<div class="info-message">
       <i class="fas fa-info-circle"></i> Standardzugang:
       <ul>
         <li>Benutzername: admin</li>
         <li>Passwort: admin</li>
       </ul>
       <p>Bitte ändern Sie das Passwort nach dem ersten Login!</p>
       </div>'
                : '',
        ];
    
        return $this->view('login', $viewData);
    }

    public function handleLogin(Request $request, array $params): ViewResponse|RedirectResponse
    {
        $postData = $request->getAllPost();
        error_log("Login attempt - POST data: " . print_r($postData, true));
    
        if (! isset($postData['csrf_token'])
            || ! $this->authService->validateCsrfToken($postData['csrf_token'])
        ) {
            return $this->view('login', [
                'username'      => $postData['username'] ?? '',
                'csrf_token'    => $this->authService->generateCsrfToken(),
                'redirect'      => filter_var($postData['redirect'] ?? '', FILTER_SANITIZE_URL),
                'error_message' => '<div class="error-message">Ungültige oder abgelaufene Anfrage. Bitte laden Sie die Seite neu.</div>',
            ]);
        }
    
        $username = trim($postData['username'] ?? '');
        $password = $postData['password'] ?? '';
        $redirect = $postData['redirect'] ?? '';
    
        error_log("Login attempt with username: '$username', redirect: '$redirect'");
        $loginResult = ! empty($username)
                    && ! empty($password)
                    && $this->authService->login($username, $password);
        error_log("Login result: " . ($loginResult ? "SUCCESS" : "FAILED"));
    
        if ($loginResult) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        
            // Prüfe, ob es ein initialer Login ist
            if (isset($_SESSION['marques_user']['initial_login']) 
                && $_SESSION['marques_user']['initial_login'] === true) {
                $setupUrl = $this->adminUrl('admin.settings') . '?initial_setup=true';
                return $this->redirect($setupUrl);
            }
        
            // Bestimme Ziel-URL für Weiterleitung
            $targetUrl = $this->adminUrl('admin.dashboard');
            if (!empty($redirect)) {
                // Verwende sichere Redirect-Validierung
                if ($this->authService->isValidRedirectUrl($redirect)) {
                    $targetUrl = $redirect;
                } else {
                    error_log("Potenziell unsichere Redirect-URL verworfen: " . $redirect);
                }
            }
        
            error_log("Weiterleitung nach erfolgreichem Login zu: " . $targetUrl);
            return $this->redirect($targetUrl);
        }
    
        // Login fehlgeschlagen
        $query = $request->getQueryParams();
        return $this->view('login', [
            'username'      => $username,
            'csrf_token'    => $this->authService->generateCsrfToken(),
            'redirect'      => filter_var($postData['redirect'] ?? $query['redirect'] ?? '', FILTER_SANITIZE_URL),
            'error_message' => '<div class="error-message">Ungültiger Benutzername oder Passwort.</div>',
        ]);
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