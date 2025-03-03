<?php
/**
 * marces CMS - User Klasse
 * 
 * Behandelt Benutzerverwaltung und Authentifizierung.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

class User {
    /**
     * @var array Benutzereinstellungen
     */
    private $_config;
    
    /**
     * @var array Aktive Benutzer-Session
     */
    private $_session = null;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_config = require MARCES_CONFIG_DIR . '/system.config.php';
        $this->_initSession();
    }
    
    /**
     * Initialisiert die Benutzersession
     */
    private function _initSession() {
        if (isset($_SESSION['marces_user']) && !empty($_SESSION['marces_user'])) {
            $this->_session = $_SESSION['marces_user'];
        }
    }
    
    /**
     * Prüft, ob ein Benutzer existiert
     *
     * @param string $username Benutzername
     * @return bool True wenn der Benutzer existiert
     */
    public function exists($username) {
        $users = $this->_getUsers();
        return isset($users[$username]);
    }
    
    /**
     * Prüft, ob der aktuelle Benutzer eingeloggt ist
     *
     * @return bool True wenn eingeloggt
     */
    public function isLoggedIn() {
        return $this->_session !== null;
    }
    
    /**
     * Versucht, einen Benutzer anzumelden
     *
     * @param string $username Benutzername
     * @param string $password Passwort
     * @return bool True bei erfolgreicher Anmeldung
     */
    public function login($username, $password) {
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            error_log("Login fehlgeschlagen: Benutzer '$username' existiert nicht.");
            return false;
        }
        
        $user = $users[$username];
        
        // Prüfen, ob das Passwort leer ist (Standardfall beim ersten Start)
        if (empty($user['password'])) {
            if ($username === 'admin' && $password === 'admin') {
                // Erstes Login mit Standardpasswort
                $user['password'] = $this->hashPassword('admin');
                $users[$username] = $user;
                $this->_saveUsers($users);
            } else {
                error_log("Login fehlgeschlagen: Passwort für Benutzer '$username' ist leer.");
                return false;
            }
        } else {
            // Passwort verifizieren
            if (!password_verify($password, $user['password'])) {
                error_log("Login fehlgeschlagen: Falsches Passwort für Benutzer '$username'.");
                return false;
            }
        }
        
        // Session setzen
        $this->_session = [
            'username' => $username,
            'display_name' => $user['display_name'],
            'role' => $user['role'],
            'last_login' => time()
        ];
        
        $_SESSION['marces_user'] = $this->_session;
        
        // Login-Zeit aktualisieren
        $this->_updateLastLogin($username);
        
        return true;
    }
    
    /**
     * Loggt den aktuellen Benutzer aus
     *
     * @return void
     */
    public function logout() {
        $this->_session = null;
        unset($_SESSION['marces_user']);
    }
    
    /**
     * Gibt den Benutzernamen des aktuell eingeloggten Benutzers zurück
     *
     * @return string|null Benutzername oder null wenn nicht eingeloggt
     */
    public function getCurrentUsername() {
        return $this->_session['username'] ?? null;
    }
    
    /**
     * Gibt die Rolle des aktuell eingeloggten Benutzers zurück
     *
     * @return string|null Benutzerrolle oder null wenn nicht eingeloggt
     */
    public function getCurrentRole() {
        return $this->_session['role'] ?? null;
    }
    
    /**
     * Gibt den Anzeigenamen des aktuell eingeloggten Benutzers zurück
     *
     * @return string|null Anzeigename oder null wenn nicht eingeloggt
     */
    public function getCurrentDisplayName() {
        return $this->_session['display_name'] ?? null;
    }
    
    /**
     * Prüft, ob der aktuelle Benutzer ein Administrator ist
     *
     * @return bool True wenn Administrator
     */
    public function isAdmin() {
        return $this->getCurrentRole() === 'admin';
    }
    
    /**
     * Erzeugt einen Hash für ein Passwort
     *
     * @param string $password Klartextpasswort
     * @return string Passwort-Hash
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Erstellt einen neuen Benutzer
     *
     * @param string $username Benutzername
     * @param string $password Passwort
     * @param string $display_name Anzeigename
     * @param string $role Benutzerrolle (admin, editor, author)
     * @return bool True bei Erfolg
     */
    public function createUser($username, $password, $display_name, $role = 'editor') {
        if ($this->exists($username)) {
            return false;
        }
        
        // Benutzernamen validieren
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return false;
        }
        
        // Benutzerdaten vorbereiten
        $users = $this->_getUsers();
        $users[$username] = [
            'password' => $this->hashPassword($password),
            'display_name' => $display_name,
            'role' => $role,
            'created' => time(),
            'last_login' => 0
        ];
        
        // Benutzer speichern
        return $this->_saveUsers($users);
    }
    
    /**
     * Aktualisiert einen bestehenden Benutzer
     *
     * @param string $username Benutzername
     * @param array $userData Zu aktualisierende Benutzerdaten
     * @return bool True bei Erfolg
     */
    public function updateUser($username, $userData) {
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        // Passwort nur aktualisieren, wenn eines angegeben wurde
        if (isset($userData['password']) && !empty($userData['password'])) {
            $userData['password'] = $this->hashPassword($userData['password']);
        } else {
            // Altes Passwort beibehalten
            unset($userData['password']);
        }
        
        // Benutzerdaten aktualisieren
        $users[$username] = array_merge($users[$username], $userData);
        
        return $this->_saveUsers($users);
    }
    
    /**
     * Aktualisiert ein Benutzerpasswort
     *
     * @param string $username Benutzername
     * @param string $new_password Neues Passwort
     * @return bool True bei Erfolg
     */
    public function updatePassword($username, $new_password) {
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        $users[$username]['password'] = $this->hashPassword($new_password);
        
        return $this->_saveUsers($users);
    }
    
    /**
     * Löscht einen Benutzer
     *
     * @param string $username Zu löschender Benutzername
     * @return bool True bei Erfolg
     */
    public function deleteUser($username) {
        $users = $this->_getUsers();
        
        // Admin-Benutzer kann nicht gelöscht werden
        if ($username === 'admin') {
            return false;
        }
        
        if (!isset($users[$username])) {
            return false;
        }
        
        unset($users[$username]);
        
        return $this->_saveUsers($users);
    }
    
    /**
     * Holt Informationen zu einem bestimmten Benutzer
     *
     * @param string $username Benutzername
     * @return array|null Benutzerdaten oder null wenn nicht gefunden
     */
    public function getUserInfo($username) {
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            return null;
        }
        
        $userInfo = $users[$username];
        // Passwort aus der Rückgabe entfernen
        unset($userInfo['password']);
        
        // Username hinzufügen
        $userInfo['username'] = $username;
        
        return $userInfo;
    }
    
    /**
     * Gibt alle Benutzer zurück
     *
     * @return array Benutzer mit Informationen
     */
    public function getAllUsers() {
        $users = $this->_getUsers();
        $result = [];
        
        foreach ($users as $username => $data) {
            // Passwort aus der Rückgabe entfernen
            unset($data['password']);
            
            // Username hinzufügen
            $data['username'] = $username;
            
            $result[$username] = $data;
        }
        
        return $result;
    }
    
    /**
     * Aktualisiert die letzte Login-Zeit eines Benutzers
     *
     * @param string $username Benutzername
     * @return bool True bei Erfolg
     */
    private function _updateLastLogin($username) {
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        $users[$username]['last_login'] = time();
        
        return $this->_saveUsers($users);
    }
    
    /**
     * Gibt alle Benutzer zurück
     *
     * @return array Benutzerdaten
     */
    private function _getUsers() {
        $userFile = MARCES_CONFIG_DIR . '/users.config.php';
        
        if (!file_exists($userFile)) {
            // Standard-Admin erstellen
            $users = [
                'admin' => [
                    'password' => $this->hashPassword('admin'),
                    'display_name' => 'Administrator',
                    'role' => 'admin',
                    'created' => time(),
                    'last_login' => 0
                ]
            ];
            
            $this->_saveUsers($users);
            return $users;
        }
        
        $users = require $userFile;
        
        // Prüfen, ob Admin-Benutzer ein leeres Passwort hat
        if (isset($users['admin']) && empty($users['admin']['password'])) {
            // Admin-Passwort bleibt leer, wird beim ersten Login gesetzt
            // $users['admin']['password'] = $this->hashPassword('admin');
            // $this->_saveUsers($users);
        }
        
        return $users;
    }
    
    /**
     * Speichert Benutzerdaten
     *
     * @param array $users Benutzerdaten
     * @return bool True bei Erfolg
     */
    private function _saveUsers($users) {
        $userFile = MARCES_CONFIG_DIR . '/users.config.php';
        
        $content = "<?php\n// marces CMS - Benutzerkonfiguration\n// NICHT DIREKT BEARBEITEN!\n\nreturn " . var_export($users, true) . ";\n";
        
        if (file_put_contents($userFile, $content) === false) {
            return false;
        }
        
        return true;
    }
}