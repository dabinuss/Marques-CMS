<?php
/**
 * marces CMS - Benutzerdefinierte Exceptions
 * 
 * Benutzerdefinierte Exception-Klassen für das marces CMS.
 *
 * @package marces
 * @subpackage core
 */

namespace Marces\Core;

/**
 * Not Found Exception
 * 
 * Wird geworfen, wenn eine angeforderte Ressource nicht gefunden wird.
 */
class NotFoundException extends \Exception {
    public function __construct($message = "Die angeforderte Ressource wurde nicht gefunden.", $code = 404, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Configuration Exception
 * 
 * Wird geworfen, wenn ein Problem mit der Konfiguration besteht.
 */
class ConfigurationException extends \Exception {
    public function __construct($message = "Konfigurationsfehler.", $code = 500, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Permission Exception
 * 
 * Wird geworfen, wenn ein Berechtigungsproblem besteht.
 */
class PermissionException extends \Exception {
    public function __construct($message = "Zugriff verweigert.", $code = 403, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}