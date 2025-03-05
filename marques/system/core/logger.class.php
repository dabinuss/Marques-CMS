<?php
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
    public function log($level, $message, array $context = []) {
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
    public function error($message, array $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Protokolliert eine Info-Nachricht
     */
    public function info($message, array $context = []) {
        $this->log('INFO', $message, $context);
    }
}