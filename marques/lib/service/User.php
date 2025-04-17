<?php
declare(strict_types=1);

namespace Marques\Service;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Filesystem\PathRegistry;
use Marques\Filesystem\FileManager;

class User {
    private array $_config;
    private string $_loginAttemptsFile;
    private DatabaseHandler $dbHandler;
    private PathRegistry $paths;

    public function __construct(DatabaseHandler $dbHandler, PathRegistry $paths) {
        $this->dbHandler = $dbHandler;
        $this->paths     = $paths;
        $this->_config = $this->dbHandler->table('settings')->where('id', '=', 1)->first();
        $this->_loginAttemptsFile = $this->paths->combine('logs', 'login_attempts.json');
    }

    /**
     * Prüft, ob ein Benutzer existiert.
     */
    public function exists(string $username): bool {
        $users = $this->_getUsers();
        return isset($users[$username]);
    }

    /**
     * Liefert alle Benutzerdaten als assoziatives Array (ohne Passwort).
     *
     * @return array
     */
    public function getAllUsers(): array {
        $users = $this->_getUsers();
        $result = [];
        foreach ($users as $username => $data) {
            $userData = $data;
            unset($userData['password']);
            $userData['username'] = $username;
            $result[$username] = $userData;
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
    public function createUser(string $username, string $password, string $display_name, string $role = 'editor'): bool {
        if ($this->exists($username)) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return false;
        }
        $hashedPassword = !empty($password) ? $this->hashPassword($password) : '';
        $newUser = [
            'username'     => $username,
            'password'     => $hashedPassword,
            'display_name' => $display_name,
            'role'         => $role,
            'email'        => '',
            'created'      => time(),
            'last_login'   => 0,
            'first_login'  => ($username === 'admin' ? empty($password) : false)
        ];
        // Ermitteln der neuen ID
        $users = $this->_getUsers();
        $newId = 1;
        if (!empty($users)) {
            $ids = array_map(function($user) {
                return $user['id'] ?? 0;
            }, array_values($users));
            $newId = max($ids) + 1;
        }
        $newUser['id'] = $newId;
        $dbHandler = $this->dbHandler->table('user');
        $insertResult = $dbHandler->data($newUser)->insert();
        return $insertResult > 0;
    }

    /**
     * Aktualisiert einen bestehenden Benutzer.
     */
    public function updateUser(string $username, array $userData): bool {
        $users = $this->_getUsers();
        if (!isset($users[$username])) {
            return false;
        }
        $existing = $users[$username];
        if (isset($userData['password']) && !empty($userData['password'])) {
            $userData['password'] = $this->hashPassword($userData['password']);
            if ($username === 'admin') {
                $userData['first_login'] = false;
            }
        } else {
            unset($userData['password']);
        }
        $updatedUser = array_merge($existing, $userData);
        $dbHandler = $this->dbHandler->table('user');
        return $dbHandler->where('id', '=', (int)$existing['id'])->data($updatedUser)->update();
    }

    /**
     * Aktualisiert das Passwort eines Benutzers.
     */
    public function updatePassword(string $username, string $new_password): bool {
        $users = $this->_getUsers();
        if (!isset($users[$username])) {
            return false;
        }
        $users[$username]['password'] = $this->hashPassword($new_password);
        if ($username === 'admin') {
            $users[$username]['first_login'] = false;
        }
        $dbHandler = $this->dbHandler->table('user');
        return $dbHandler->where('id', '=', (int)$users[$username]['id'])->data($users[$username])->update();
    }

    /**
     * Löscht einen Benutzer.
     */
    public function deleteUser(string $username): bool {
        $users = $this->_getUsers();
        if ($username === 'admin' || !isset($users[$username])) {
            return false;
        }
        $userId = (int)$users[$username]['id'];
        $dbHandler = $this->dbHandler->table('user');
        return $dbHandler->where('id', '=', $userId)->delete();
    }

    /**
     * Erzeugt einen Passwort-Hash.
     */
    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Lädt alle Benutzerdatensätze aus der "user"-Tabelle.
     * Jeder Benutzer wird als eigener Datensatz gespeichert, daher:
     * - Es werden alle Datensätze (mittels findAll()) abgerufen.
     * - Anschließend wird ein assoziatives Array aufgebaut, das keyed by "username" ist.
     *
     * @return array
     */
    private function _getUsers(): array {
        $dbHandler = $this->dbHandler->table('user');
        $records = $dbHandler->find();
        $users = [];
        if (!empty($records)) {
            foreach ($records as $record) {
                if (isset($record['username'])) {
                    $users[$record['username']] = $record;
                }
            }
        }
        // Falls kein Admin existiert, wird ein Standard-Admin angelegt
        if (!isset($users['admin'])) {
            $admin = [
                'username'     => 'admin',
                'password'     => '',
                'display_name' => 'Administrator',
                'role'         => 'admin',
                'email'        => '',
                'created'      => time(),
                'last_login'   => 0,
                'first_login'  => true
            ];
            $newId = empty($users) ? 1 : (max(array_map(function($u) { return $u['id'] ?? 0; }, array_values($users))) + 1);
            $admin['id'] = $newId;
            $dbHandler->data($admin)->insert();
            $users['admin'] = $admin;
        }
        return $users;
    }

    /**
     * Speichert einen einzelnen Benutzerdatensatz.
     * Da jeder Benutzer als eigener Datensatz gespeichert wird, erfolgt das Speichern
     * über update() für den entsprechenden Benutzer.
     *
     * @param array $user Benutzer-Daten
     * @return bool
     */
    private function _saveUser(array $user): bool {
        $dbHandler = $this->dbHandler->table('user');
        return $dbHandler->where('id', '=', (int)$user['id'])->data($user)->update();
    }

    public function getCurrentDisplayName(): ?string {
        return $_SESSION['marques_user']['display_name'] ?? null;
    }

    public function isAdmin(): bool {
        return (($_SESSION['marques_user']['role'] ?? '') === 'admin');
    }
}
