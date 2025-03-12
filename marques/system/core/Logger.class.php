<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Einfache Logger-Klasse
 */
class Logger {
    private $logDir;
    
    public function __construct() {
        $this->logDir = MARQUES_ROOT_DIR . '/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * Protokolliert eine Nachricht
     */
    public function log(string $level, string $message, array $context = []): void {
        $date = new \DateTime();
        $logFile = $this->logDir . '/' . $date->format('Y-m-d') . '.log';
        $timestamp = $date->format('Y-m-d H:i:s');
        
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Protokolliert einen Fehler
     */
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }    
    
    /**
     * Protokolliert eine Info-Nachricht
     */
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }    
}