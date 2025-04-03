<?php
declare(strict_types=1);

namespace Marques\Core;

class Logger {
    private string $logDir;

    public function __construct() {
        $this->logDir = MARQUES_ROOT_DIR . '/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    // Entferne die statische getInstance()-Methode.

    public function log(string $level, string $message, array $context = []): void {
        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');
        $formattedEntry = $this->formatLogEntry($timestamp, $level, $message, $context);
        $logFile = $this->logDir . '/marques_' . (new \DateTime())->format('Y-m-d') . '.log';
        file_put_contents($logFile, $formattedEntry, FILE_APPEND);
    }
    
    private function formatLogEntry(string $timestamp, string $level, string $message, array $context = []): string {
        $entry = "[{$timestamp}] [{$level}] {$message}";
        if (!empty($context)) {
            $entry .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
        }
        return $entry . PHP_EOL;
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }    
    
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }    
}
