<?php
/**
 * marques CMS - User Klasse
 * 
 * Behandelt Benutzerverwaltung und Authentifizierung.
 *
 * @package marques
 * @subpackage core
 */

namespace Marques\Core;

class User {
    private $_config;
    private $_session = null;
    private $_loginAttemptsFile;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->_config = require MARQUES_CONFIG_DIR . '/system.config.php';
        $this->_loginAttemptsFile = MARQUES_ROOT_DIR . '/logs/login_attempts.json';
        $this->_initSession();
    }
    
    /**
     * Initialisiert die Benutzersession
     */
    private function _initSession() {
        if (isset($_SESSION['marques_user']) && !empty($_SESSION['marques_user'])) {
            $this->_session = $_SESSION['marques_user'];
        }
    }

    private function _initLoginAttemptsLog() {
        if (!file_exists($this->_loginAttemptsFile)) {
            // Stellt sicher, dass das Verzeichnis existiert
            $dir = dirname($this->_loginAttemptsFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->_loginAttemptsFile, json_encode([]));
        }
    }
    
    private function _getLoginAttempts($ip) {
        $this->_initLoginAttemptsLog();
        
        if (!file_exists($this->_loginAttemptsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->_loginAttemptsFile);
        if (empty($content)) {
            return [];
        }
        
        $attempts = json_decode($content, true);
        if (!is_array($attempts)) {
            return [];
        }
        
        // Alte Einträge bereinigen
        $attempts = array_filter($attempts, function($attempt) {
            return isset($attempt['timestamp']) && $attempt['timestamp'] > (time() - 3600); // 1 Stunde
        });
        
        return array_filter($attempts, function($attempt) use ($ip) {
            return isset($attempt['ip']) && $attempt['ip'] === $ip;
        });
    }
    
    private function _logLoginAttempt($username, $success) {
        $this->_initLoginAttemptsLog();
        
        $content = file_get_contents($this->_loginAttemptsFile);
        $attempts = !empty($content) ? json_decode($content, true) : [];
        
        if (!is_array($attempts)) {
            $attempts = [];
        }
        
        $attempts[] = [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'username' => $username,
            'timestamp' => time(),
            'success' => $success
        ];
        
        file_put_contents($this->_loginAttemptsFile, json_encode($attempts));
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
        $ip = $_SERVER['REMOTE_ADDR'];
        $loginAttempts = $this->_getLoginAttempts($ip);
        
        // Zu viele Fehlversuche prüfen
        if (count($loginAttempts) >= 5) {
            $failedAttempts = array_filter($loginAttempts, function($attempt) {
                return $attempt['success'] === false;
            });
            
            if (count($failedAttempts) >= 5) {
                $timestamps = array_column($failedAttempts, 'timestamp');
                if (!empty($timestamps)) {
                    $lastFailedAttempt = max($timestamps);
                    if ($lastFailedAttempt > (time() - 3600)) {
                        error_log("Login blocked for IP $ip due to too many attempts");
                        return false;
                    }
                }
            }
        }
        
        $users = $this->_getUsers();
        
        if (!isset($users[$username])) {
            $this->_logLoginAttempt($username, false);
            error_log("Login failed: User '$username' does not exist");
            return false;
        }
        
        $user = $users[$username];
        
        // DEBUG-Logging
        error_log("Login attempt for user '$username'");
        error_log("User data: " . json_encode($user));
        
        // Spezielle Behandlung für Admin bei ersten Login
        if ($username === 'admin' && (empty($user['password']) || $user['first_login'] === true)) {
            error_log("Admin first login case detected");
            
            // Bei Standard-Passwort
            if ($password === 'admin') {
                error_log("Admin using default password - login successful");
                
                // Erfolgreicher Login - Session setzen
                $this->_session = [
                    'username' => $username,
                    'display_name' => $user['display_name'],
                    'role' => $user['role'],
                    'last_login' => time(),
                    'initial_login' => true
                ];
                
                $_SESSION['marques_user'] = $this->_session;
                $this->_logLoginAttempt($username, true);
                $this->_updateLastLogin($username);
                
                return true;
            } else {
                error_log("Admin login failed: incorrect default password");
                $this->_logLoginAttempt($username, false);
                return false;
            }
        }
        
        // Normale Passwortprüfung für alle anderen Fälle
        // Sicherstellen, dass wir einen gültigen Hash haben
        if (empty($user['password'])) {
            error_log("Login failed: Empty password hash for user '$username'");
            $this->_logLoginAttempt($username, false);
            return false;
        }
        
        if (!password_verify($password, $user['password'])) {
            error_log("Login failed: Invalid password for user '$username'");
            $this->_logLoginAttempt($username, false);
            return false;
        }
        
        // Login erfolgreich
        error_log("Login successful for user '$username'");
        $this->_logLoginAttempt($username, true);
        
        $this->_session = [
            'username' => $username,
            'display_name' => $user['display_name'],
            'role' => $user['role'],
            'last_login' => time()
        ];
        
        $_SESSION['marques_user'] = $this->_session;
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
        unset($_SESSION['marques_user']);
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
        
        // Passwort hashen, wenn nicht leer
        $hashedPassword = !empty($password) ? $this->hashPassword($password) : '';
        
        // Benutzerdaten vorbereiten
        $users = $this->_getUsers();
        $users[$username] = [
            'password' => $hashedPassword,
            'display_name' => $display_name,
            'role' => $role,
            'created' => time(),
            'last_login' => 0
        ];
        
        if ($username === 'admin') {
            $users[$username]['first_login'] = empty($password);
        }
        
        // Benutzer speichern
        return $this->_saveUsers($users);
    }

    /**
     * Vereinfachte Methode zum Erstellen des Admin-Accounts
     */
    public function setupAdminAccount($password) {
        // Prüfen und Passwort hashen
        $hashedPassword = $this->hashPassword($password);
        
        // Benutzerdaten aktualisieren
        $users = $this->_getUsers();
        $users['admin']['password'] = $hashedPassword;
        $users['admin']['first_login'] = false;
        
        // Speichern
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
            
            // Wenn Admin-Passwort geändert wird, first_login auf false setzen
            if ($username === 'admin') {
                $userData['first_login'] = false;
            }
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
        
        if ($username === 'admin') {
            $users[$username]['first_login'] = false;
        }
        
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
        $userFile = MARQUES_CONFIG_DIR . '/users.config.php';
        
        if (!file_exists($userFile)) {
            // Standard-Admin mit leerem Passwort erstellen
            $users = [
                'admin' => [
                    'password' => '',  // Leeres Passwort für Standardzugang
                    'display_name' => 'Administrator',
                    'role' => 'admin',
                    'created' => time(),
                    'last_login' => 0,
                    'first_login' => true  // Flag für ersten Login
                ]
            ];
            
            $this->_saveUsers($users);
            return $users;
        }
        
        $users = require $userFile;
        
        // Sicherstellen, dass der Admin-Benutzer existiert
        if (!isset($users['admin'])) {
            $users['admin'] = [
                'password' => '',
                'display_name' => 'Administrator',
                'role' => 'admin',
                'created' => time(),
                'last_login' => 0,
                'first_login' => true
            ];
            $this->_saveUsers($users);
        }
        
        // Sicherstellen, dass first_login Flag existiert
        if (!isset($users['admin']['first_login'])) {
            $users['admin']['first_login'] = empty($users['admin']['password']);
            $this->_saveUsers($users);
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
        $userFile = MARQUES_CONFIG_DIR . '/users.config.php';
        
        $content = "<?php\n// marques CMS - Benutzerkonfiguration\n// NICHT DIREKT BEARBEITEN!\n\nreturn " . var_export($users, true) . ";\n";
        
        if (file_put_contents($userFile, $content) === false) {
            error_log("Failed to write user file: $userFile");
            return false;
        }
        
        return true;
    }
}