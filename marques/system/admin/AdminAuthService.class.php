<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\User;
use Marques\Core\AppConfig;

class AdminAuthService
{
    /**
     * @var User
     */
    private User $userModel;

    /**
     * @var array
     */
    private array $systemConfig;

    /**
     * Konstruktor.
     * Übergibt als Abhängigkeit ein User-Modell, das ausschließlich für Datenmanagement zuständig ist.
     */
    public function __construct(User $userModel)
    {
        // Stelle sicher, dass eine Session läuft.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->userModel = $userModel;
        $configManager = AppConfig::getInstance();
        $this->systemConfig = $configManager->load('system') ?: [];
    }

    /**
     * Prüft, ob der Benutzer in der aktuellen Session eingeloggt ist.
     *
     * @return bool True, wenn eingeloggt
     */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['marques_user']);
    }

    /**
     * Erzwingt, dass der Benutzer eingeloggt ist.
     * Leitet bei fehlendem Login zur Login-Seite weiter, außer wenn wir uns bereits auf dieser befinden.
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
     * Bei Erfolg wird die Session gesetzt.
     *
     * @param string $username
     * @param string $password
     * @return bool True bei erfolgreichem Login
     */
    public function login(string $username, string $password): bool
    {
        // Hole alle Benutzer – die User-Klasse liefert hier alle Daten als Array.
        $allUsers = $this->userModel->getAllUsers(); // Siehe neue User-Klasse unten
        if (!isset($allUsers[$username])) {
            return false;
        }
        $user = $allUsers[$username];

        // Behandlung für den Admin bei erstem Login (Standardpasswort)
        if ($username === 'admin' && (empty($user['password']) || ($user['first_login'] ?? false))) {
            if ($password === 'admin') {
                $_SESSION['marques_user'] = [
                    'username'     => $username,
                    'display_name' => $user['display_name'],
                    'role'         => $user['role'],
                    'last_login'   => time(),
                    'initial_login'=> true
                ];
                return true;
            }
            return false;
        }

        // Normale Passwortprüfung
        if (empty($user['password']) || !password_verify($password, $user['password'])) {
            return false;
        }

        $_SESSION['marques_user'] = [
            'username'     => $username,
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
            'last_login'   => time()
        ];
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
     *
     * @return array|null
     */
    public function getUser(): ?array
    {
        return $_SESSION['marques_user'] ?? null;
    }
}
