<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\User;
use Marques\Core\AppConfig;

class AdminAuthService
{
    private User $userModel;
    private array $systemConfig;
    private string $loginAttemptsFile;

    public function __construct(User $userModel)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->userModel = $userModel;
        $configManager = AppConfig::getInstance();
        $this->systemConfig = $configManager->load('system') ?: [];
        $this->loginAttemptsFile = MARQUES_ROOT_DIR . '/logs/login_attempts.json';
        $this->initLoginAttemptsLog();
    }

    /**
     * Initialisiert die Log-Datei für Login-Versuche.
     */
    private function initLoginAttemptsLog(): void
    {
        if (!file_exists($this->loginAttemptsFile)) {
            $dir = dirname($this->loginAttemptsFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->loginAttemptsFile, json_encode([]));
        }
    }

    /**
     * Liefert alle Login-Versuche der aktuellen IP in den letzten 3600 Sekunden.
     */
    private function getLoginAttempts(string $ip): array
    {
        $this->initLoginAttemptsLog();
        $content = file_get_contents($this->loginAttemptsFile);
        $attempts = json_decode($content, true);
        if (!is_array($attempts)) {
            return [];
        }
        return array_filter($attempts, function($attempt) use ($ip) {
            return isset($attempt['timestamp']) 
                && $attempt['timestamp'] > (time() - 3600)
                && isset($attempt['ip']) 
                && $attempt['ip'] === $ip;
        });
    }

    /**
     * Loggt einen Login-Versuch.
     */
    private function logLoginAttempt(string $username, bool $success): void
    {
        $this->initLoginAttemptsLog();
        $content = file_get_contents($this->loginAttemptsFile);
        $attempts = !empty($content) ? json_decode($content, true) : [];
        if (!is_array($attempts)) {
            $attempts = [];
        }
        $attempts[] = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'username' => $username,
            'timestamp' => time(),
            'success' => $success
        ];
        file_put_contents($this->loginAttemptsFile, json_encode($attempts));
    }

    /**
     * Überprüft, ob die Anzahl der fehlgeschlagenen Versuche für die aktuelle IP unter dem Limit liegt.
     */
    public function checkRateLimit(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $attempts = $this->getLoginAttempts($ip);
        $failed = array_filter($attempts, function($attempt) {
            return $attempt['success'] === false;
        });
        return count($failed) < 5;
    }

    /**
     * Generiert einen neuen CSRF-Token und speichert ihn in der Session.
     */
    public function generateCsrfToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validiert den übergebenen CSRF-Token anhand des in der Session gespeicherten.
     */
    public function validateCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Regeneriert die Session-ID, um Session Fixation zu verhindern.
     */
    public function regenerateSession(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Prüft, ob ein Benutzer in der aktuellen Session eingeloggt ist.
     */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['marques_user']);
    }

    /**
     * Erzwingt den Login: Falls kein Benutzer eingeloggt ist, wird zur Login-Seite umgeleitet.
     */
    public function requireLogin(): void
    {
        $currentPage = $_GET['page'] ?? '';
        if (!$this->isLoggedIn() && strtolower($currentPage) !== 'login') {
            header('Location: index.php?page=login');
            exit;
        }
    }

    /**
     * Versucht, einen Benutzer anhand von Benutzername und Passwort anzumelden.
     * Zusätzlich wird Rate-Limiting beachtet.
     */
    public function login(string $username, string $password): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->checkRateLimit()) {
            return false; // Login blockiert wegen zu vieler fehlgeschlagener Versuche
        }
    
        // Nutze getRawUserData(), um den Passwort-Hash zu erhalten
        $user = $this->userModel->getRawUserData($username);
        if (!$user) {
            $this->logLoginAttempt($username, false);
            return false;
        }
    
        // Behandlung für Admin beim ersten Login (Standardpasswort)
        if ($username === 'admin' && (empty($user['password']) || ($user['first_login'] ?? false))) {
            if ($password === 'admin') {
                $_SESSION['marques_user'] = [
                    'username'     => $username,
                    'display_name' => $user['display_name'],
                    'role'         => $user['role'],
                    'last_login'   => time(),
                    'initial_login'=> true
                ];
                $this->regenerateSession();
                $this->logLoginAttempt($username, true);
                return true;
            }
            $this->logLoginAttempt($username, false);
            return false;
        }
    
        // Normale Passwortprüfung
        if (empty($user['password']) || !password_verify($password, $user['password'])) {
            $this->logLoginAttempt($username, false);
            return false;
        }
    
        $_SESSION['marques_user'] = [
            'username'     => $username,
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
            'last_login'   => time()
        ];
        $this->regenerateSession();
        $this->logLoginAttempt($username, true);
        return true;
    }    

    /**
     * Loggt den aktuell angemeldeten Benutzer aus.
     */
    public function logout(): void
    {
        unset($_SESSION['marques_user']);
    }

    /**
     * Gibt das aktuell in der Session gespeicherte Benutzer-Array zurück.
     */
    public function getUser(): ?array
    {
        return $_SESSION['marques_user'] ?? null;
    }
}
