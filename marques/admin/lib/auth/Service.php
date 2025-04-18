<?php
declare(strict_types=1);

namespace Admin\Auth;

use Marques\Service\User;
use Marques\Filesystem\PathRegistry;

class Service
{
    private User $userModel;
    private array $systemConfig;
    private string $loginAttemptsFile;
    private const DEFAULT_ADMIN_PASSWORD = 'admin';
    private string $defaultAdminPasswordHash;

    private PathRegistry $pathRegistry; 

    public function __construct(
        User $userModel, 
        array $systemConfig,
        PathRegistry $pathRegistry
    ) {
        $this->defaultAdminPasswordHash = password_hash(self::DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $this->userModel = $userModel;
        $this->systemConfig = $systemConfig;
        $this->pathRegistry = $pathRegistry;
        $this->loginAttemptsFile = $this->pathRegistry->combine('logs', 'login_attempts.json');
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
    
        // Default‑Limit sauber holen (5, falls nicht gesetzt)
        $maxAttempts = $this->systemConfig['max_login_attempts'] ?? 5;
    
        // Jetzt richtig vergleichen
        return count($failedAttempts) < $maxAttempts;
    }

    /**
     * Generiert einen neuen CSRF-Token und speichert ihn in der Session.
     */
    public function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validiert den übergebenen CSRF-Token anhand des in der Session gespeicherten.
     */
    public function validateCsrfToken(string $token): bool
    {
        // Leere Tokens niemals akzeptieren
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        // Sichere Vergleichsmethode für kryptografische Strings
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Regeneriert die Session-ID, um Session Fixation zu verhindern.
     */
    public function regenerateSession(): void
    {
        // Prüfen, ob eine Session aktiv ist, um Fehler zu vermeiden
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Explizit das CSRF-Token sichern
            $csrfToken = $_SESSION['csrf_token'] ?? null;
            $oldData = $_SESSION; // Backup der Session-Daten
            
            if (session_regenerate_id(true)) { // true = alte Session-Datei löschen
                $_SESSION = $oldData; // Daten in die neue Session migrieren
                
                // Explizit das CSRF-Token wiederherstellen
                if ($csrfToken) {
                    $_SESSION['csrf_token'] = $csrfToken;
                }
            }
        }
    }

    /**
     * Prüft, ob ein Benutzer in der aktuellen Session eingeloggt ist.
     */
    public function isLoggedIn(): bool
    {
        // Prüfe zunächst, ob die Session-Variable überhaupt gesetzt ist.
        if (!isset($_SESSION['marques_user']['username']) || empty($_SESSION['marques_user']['username'])) {
            return false;
        }
    
        // Prüfe Session-Ablauf
        $lastActivity = $_SESSION['marques_user']['last_activity'] ?? 0;
        $timeout = $this->systemConfig['session_timeout'] ?? 3600; // 1 Stunde Standard
        if (time() - $lastActivity > $timeout) {
            error_log("Session timeout für Benutzer: " . $_SESSION['marques_user']['username']);
            return false;
        }
        
        // Optional: IP-Adresse prüfen (kann bei Nutzern mit wechselnden IPs Probleme verursachen)
        if (isset($this->systemConfig['strict_session_security']) && $this->systemConfig['strict_session_security']) {
            $currentIp = $this->getClientIp();
            if (isset($_SESSION['marques_user']['ip_address']) && $_SESSION['marques_user']['ip_address'] !== $currentIp) {
                error_log("IP-Adresse in Session stimmt nicht überein: " . 
                        $_SESSION['marques_user']['ip_address'] . " vs aktuell " . $currentIp);
                return false;
            }
        }
    
        return true; // Der Benutzer gilt als eingeloggt.
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
        error_log("Service::login called for user: $username");

        $ip = $this->getClientIp();
        if (!$this->checkRateLimit()) {
            $this->logLoginAttempt($username, false);
            error_log("Login rate limit exceeded for IP: " . $ip); // Logging
            return false;
        }

        $user = $this->userModel->getRawUserData($username);
        error_log("User data from DB: " . ($user ? "Found" : "Not found"));

        $validUser = $user !== null;

        // Prüfe Standardpasswort oder gehashtes Passwort
        $validPassword = false;
        if ($validUser) {
            error_log("Validating password for user: $username");

            // Ist es der Admin mit Standardpasswort (oder erstem Login)?
            if ($username === 'admin' && password_verify($password, $this->defaultAdminPasswordHash)) {
                 // Prüfe, ob das DB-Passwort leer ist ODER first_login gesetzt ist
                 if (empty($user['password']) || ($user['first_login'] ?? false)) {
                      $validPassword = true;
                      $_SESSION['marques_user_initial_login'] = true; // Flag für AuthController
                 } else {
                      // Admin hat schon ein eigenes Passwort, Standardpasswort ist falsch
                      $validPassword = password_verify($password, $user['password']);
                 }

            } elseif (!empty($user['password']) && password_verify($password, $user['password'])) {
                 // Normaler User oder Admin mit eigenem Passwort
                 $validPassword = true;
                 unset($_SESSION['marques_user_initial_login']); // Sicherstellen, dass Flag weg ist
            }
        }


        if (!$validPassword) {
            $this->logLoginAttempt($username, false);
            error_log("Invalid password attempt for user '{$username}' from IP: " . $ip); // Logging
            return false;
        }

        // --- Login erfolgreich ---
        $_SESSION['marques_user'] = [
            'username'     => $username,
            'display_name' => $user['display_name'] ?? $username,
            'role'         => $user['role'] ?? 'user', // Standardrolle falls nicht gesetzt
            'last_login'   => time(),
            'last_activity'=> time(), // Für Session-Timeout
            'ip_address'   => $ip, // Für strenge Sicherheitsprüfungen
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '' // Für strenge Sicherheitsprüfungen
        ];
        
        // Flag für initialen Login korrekt setzen
        if ($username === 'admin' && password_verify($password, $this->defaultAdminPasswordHash) && 
            (empty($user['password']) || ($user['first_login'] ?? false))) {
            $_SESSION['marques_user']['initial_login'] = true;
        } else {
            unset($_SESSION['marques_user']['initial_login']);
        }
        
        // CSRF-Token vor Session-Regenerierung sichern
        $csrfToken = $_SESSION['csrf_token'] ?? null;
        $this->regenerateSession();
        
        // CSRF-Token wiederherstellen oder neues generieren
        if ($csrfToken) {
            $_SESSION['csrf_token'] = $csrfToken;
        } else {
            $this->generateCsrfToken();
        }

        $this->logLoginAttempt($username, true);
        error_log("User '{$username}' logged in successfully from IP: " . $ip); // Logging
        return true;
    } 

    /**
     * Loggt den aktuell angemeldeten Benutzer aus.
     */
    public function logout(): void
    {
        // Logge Ausloggen für Audit-Zwecke
        if (isset($_SESSION['marques_user']['username'])) {
            error_log("Benutzer ausgeloggt: " . $_SESSION['marques_user']['username']);
        }
        
        // Session-Daten leeren
        $_SESSION = [];
        
        // Session-Cookie löschen
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Session zerstören
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

    /**
     * Prüft, ob eine Redirect-URL sicher ist.
     * 
     * @param string $url Die zu prüfende URL
     * @return bool True, wenn die URL sicher ist
     */
    public function isValidRedirectUrl(string $url): bool
    {
        // Grundlegende Prüfungen
        if (empty($url) || $url[0] !== '/') {
            return false;
        }
        
        // Protocol-relative URLs verhindern (//example.com)
        if (isset($url[1]) && $url[1] === '/') {
            return false;
        }
        
        // Nur URLs im Admin-Bereich erlauben
        if (strpos($url, '/admin/') !== 0) {
            return false;
        }
        
        // Whitelist erlaubter Admin-Pfade
        $allowedPrefixes = [
            '/admin/dashboard',
            '/admin/pages',
            '/admin/settings',
            '/admin/blog',
            '/admin/media',
            '/admin/users'
        ];
        
        foreach ($allowedPrefixes as $prefix) {
            if (strpos($url, $prefix) === 0) {
                return true;
            }
        }
        
        // Fallback für weitere Admin-URLs
        $adminLoginPath = '/admin/login';
        $adminPath = '/admin/';
        
        // Vermeidung von Redirect-Loops
        if (rtrim($url, '/') === rtrim($adminLoginPath, '/')) {
            return false;
        }
        
        // Allgemeine /admin/ URLs erlauben
        return strpos($url, $adminPath) === 0;
    }
}
