<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Basis Exception für das CMS
 */
class Exception extends \Exception {
    protected $context = [];
    
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    public function getContext() {
        return $this->context;
    }
}

/**
 * Not Found Exception
 */
class NotFoundException extends Exception {
    public function __construct($message = "Die angeforderte Ressource wurde nicht gefunden.", $code = 404, $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous, $context);
    }
}

/**
 * Permission Exception
 */
class PermissionException extends Exception {
    public function __construct($message = "Zugriff verweigert.", $code = 403, $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous, $context);
    }
}

/**
 * Configuration Exception
 */
class ConfigurationException extends Exception {
    public function __construct($message = "Konfigurationsfehler.", $code = 500, $previous = null, array $context = []) {
        parent::__construct($message, $code, $previous, $context);
    }
}