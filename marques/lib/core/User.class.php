<?php
declare(strict_types=1);

namespace Marques\Core;

class User {
    /**
     * Enthält ausschließlich benutzerspezifische Konfigurationsdaten.
     */
    private $_config;
    private $_loginAttemptsFile;

    public function __construct() {
        // Wir gehen davon aus, dass hier keine Session-Initialisierung nötig ist.
        $configManager = AppConfig::getInstance();
        $this->_config = $configManager->load('system') ?: [];
        $this->_loginAttemptsFile = MARQUES_ROOT_DIR . '/logs/login_attempts.json';
    }

    // --- Methoden für Benutzerverwaltung ---

    /**
     * Prüft, ob ein Benutzer existiert.
     */
    public function exists(string $username): bool {
        $users = $this->_getUsers();
        return isset($users[$username]);
    }

    /**
     * Liefert alle Benutzerdaten als assoziatives Array.
     * Dabei werden sensible Daten (z.B. das Passwort) entfernt.
     *
     * @return array
     */
    public function getAllUsers(): array {
        $users = $this->_getUsers();
        $result = [];
        foreach ($users as $username => $data) {
            unset($data['password']); // Entferne das Passwort
            $data['username'] = $username;
            $result[$username] = $data;
        }
        return $result;
    }

    /**
     * Liefert die rohen Benutzerdaten (inklusive Passwort-Hash) für einen bestimmten Benutzer.
     *
     * @param string $username
     * @return array|null
     */
    public function getRawUserData(string $username): ?array {
        $users = $this->_getUsers();
        return $users[$username] ?? null;
    }

    /**
     * Gibt Benutzerdaten ohne Passwort zurück.
     */
    public function getUserData(string $username): ?array {
        $data = $this->getRawUserData($username);
        if ($data !== null) {
            unset($data['password']);
            $data['username'] = $username;
        }
        return $data;
    }

    /**
     * Erstellt einen neuen Benutzer.
     */
    public function createUser($username, $password, $display_name, $role = 'editor'): bool {
        if ($this->exists($username)) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return false;
        }
        $hashedPassword = !empty($password) ? $this->hashPassword($password) : '';
        $users = $this->_getUsers();
        $users[$username] = [
            'password'      => $hashedPassword,
            'display_name'  => $display_name,
            'role'          => $role,
            'created'       => time(),
            'last_login'    => 0,
            'first_login'   => $username === 'admin' ? empty($password) : false
        ];
        return $this->_saveUsers($users);
    }

    /**
     * Aktualisiert einen bestehenden Benutzer.
     */
    public function updateUser($username, $userData): bool {
        $users = $this->_getUsers();
        if (!isset($users[$username])) {
            return false;
        }
        if (isset($userData['password']) && !empty($userData['password'])) {
            $userData['password'] = $this->hashPassword($userData['password']);
            if ($username === 'admin') {
                $userData['first_login'] = false;
            }
        } else {
            unset($userData['password']);
        }
        $users[$username] = array_merge($users[$username], $userData);
        return $this->_saveUsers($users);
    }

    /**
     * Aktualisiert das Passwort eines Benutzers.
     */
    public function updatePassword($username, $new_password): bool {
        $users = $this->_getUsers();
        if (!isset($users[$username])) {
            return false;
        }
        $users[$username]['password'] = $this->hashPassword($new_password);
        if ($username === 'admin') {
            $users[$username]['first_login'] = false;
        }
        return $this->_saveUsers($users);
    }

    /**
     * Löscht einen Benutzer.
     */
    public function deleteUser($username): bool {
        $users = $this->_getUsers();
        if ($username === 'admin' || !isset($users[$username])) {
            return false;
        }
        unset($users[$username]);
        return $this->_saveUsers($users);
    }

    /**
     * Erzeugt einen Passwort-Hash.
     */
    public function hashPassword($password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    // --- Interne Methoden zum Laden/Speichern der Benutzer ---

    private function _getUsers(): array {
        $configManager = AppConfig::getInstance();
        $users = $configManager->load('users');

        $modified = false;
        if (empty($users)) {
            $users = [
                'admin' => [
                    'password' => '',
                    'display_name' => 'Administrator',
                    'role' => 'admin',
                    'created' => time(),
                    'last_login' => 0,
                    'first_login' => true
                ]
            ];
            $modified = true;
        }
        if (!isset($users['admin'])) {
            $users['admin'] = [
                'password' => '',
                'display_name' => 'Administrator',
                'role' => 'admin',
                'created' => time(),
                'last_login' => 0,
                'first_login' => true
            ];
            $modified = true;
        }
        if (!isset($users['admin']['first_login'])) {
            $users['admin']['first_login'] = empty($users['admin']['password']);
            $modified = true;
        }
        if ($modified) {
            $this->_saveUsers($users);
        }

        return $users;
    }

    private function _saveUsers($users): bool {
        $configManager = AppConfig::getInstance();
        return $configManager->save('users', $users);
    }

    public function getCurrentDisplayName(): ?string
    {
        return $_SESSION['marques_user']['display_name'] ?? null;
    }

    public function isAdmin(): bool
    {
        return (($_SESSION['marques_user']['role'] ?? '') === 'admin');
    }
}
