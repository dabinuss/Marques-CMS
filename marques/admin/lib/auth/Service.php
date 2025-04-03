<?php
declare(strict_types=1);

namespace Admin\Auth;

use Marques\Service\User;

class Service
{
    private User $userModel;
    private array $systemConfig;
    private string $loginAttemptsFile;
    private const DEFAULT_ADMIN_PASSWORD = '$2y$10$W6J5z7b8c9d0e1f2g3h4i.5j6k7l8m9n0o1p2q3r4s5t6u7v8w9x0y1z'; // Gehashtes "admin"

    public function __construct(User $userModel, array $systemConfig)
    {
        $this->userModel = $userModel;
        $this->systemConfig = $systemConfig;
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
        if ($content === false) return [];
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
    private function logLoginAttempt(string $username, bool $success): void {
        $cleanUsername = preg_replace('/[^\w-]/', '', $username);
        $attempt = [
            'ip' => $this->getClientIp(),
            'username' => $cleanUsername,
            'timestamp' => time(),
            'success' => $success
        ];
    
        try {
            $file = fopen($this->loginAttemptsFile, 'c+');
            if (!$file) {
                throw new \RuntimeException("Could not open login attempts file");
            }
            
            if (flock($file, LOCK_EX)) {
                $content = @file_get_contents($this->loginAttemptsFile) ?: '[]';
                $attempts = json_decode($content, true) ?: [];
                $attempts[] = $attempt;
                ftruncate($file, 0);
                rewind($file);
                fwrite($file, json_encode($attempts));
                flock($file, LOCK_UN);
            }
        } finally {
            if (isset($file)) {
                fclose($file);
            }
        }
    }

    /**
     * Überprüft, ob die Anzahl der fehlgeschlagenen Versuche für die aktuelle IP unter dem Limit liegt.
     */
    public function checkRateLimit(): bool
    {
        $ip = $this->getClientIp();
        $attempts = $this->getLoginAttempts($ip);
        $failedAttempts = array_filter($attempts, fn($a) => !$a['success']);
        return count($failedAttempts) < $this->systemConfig['max_login_attempts'] ?? 5;
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
        $oldData = $_SESSION;
        session_regenerate_id(true);
        $_SESSION = $oldData; // Daten migrieren
    }

    /**
     * Prüft, ob ein Benutzer in der aktuellen Session eingeloggt ist.
     */
    public function isLoggedIn(): bool
    {
        if (isset($_SESSION['marques_user'])) {
            $this->regenerateSession(); // Session-ID bei jedem Check neu generieren
            return true;
        }
        return false;
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
        $ip = $this->getClientIp();
        if (!$this->checkRateLimit()) {
            $this->logLoginAttempt($username, false);
            return false;
        }
    
        $user = $this->userModel->getRawUserData($username);
        $validUser = $user !== null;
        $validPassword = $validUser && (
            ($username === 'admin' && password_verify($password, self::DEFAULT_ADMIN_PASSWORD)) ||
            (!empty($user['password']) && password_verify($password, $user['password']))
        );
        if (!$validPassword) {
            $this->logLoginAttempt($username, false);
            return false;
        }

        // Behandlung für Admin beim ersten Login (Standardpasswort)
        if ($username === 'admin' && (empty($user['password']) || ($user['first_login'] ?? false))) {
            if (password_verify($password, self::DEFAULT_ADMIN_PASSWORD)) { // Sicher
                $_SESSION['marques_user'] = [
                    'username'     => $username,
                    'display_name' => $user['display_name'],
                    'role'         => $user['role'],
                    'last_login'   => time(),
                    'initial_login'=> true
                ];
                $this->regenerateSession();
                $this->generateCsrfToken();
                $this->logLoginAttempt($username, true);
                return true;
            }
            $this->logLoginAttempt($username, false);
            return false;
        }

        if (!empty($user['password']) && !preg_match('/^\$2[ayb]\$.{56}$/', $user['password'])) {
            error_log("Security: Unhashed password for user {$username}");
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
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Gibt das aktuell in der Session gespeicherte Benutzer-Array zurück.
     */
    public function getUser(): ?array
    {
        return $_SESSION['marques_user'] ?? null;
    }

    private function getClientIp(): string {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var(explode(',', $ip)[0], FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
}
