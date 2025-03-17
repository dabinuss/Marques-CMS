<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Erweitertes Event-Management-System
 */
class AppEvents
{
    /** @var array<string, array<callable>> */
    private array $listeners = [];
    
    /** @var array<string, bool> */
    private array $eventCache = [];
    
    /**
     * Event-Listener registrieren
     * 
     * @param string $event Name des Events
     * @param callable $callback Callback-Funktion
     * @param int $priority Priorität des Listeners (höhere Zahlen werden zuerst ausgeführt)
     * @return self
     */
    public function on(string $event, callable $callback, int $priority = 0): self
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        
        // Speichern des Callbacks mit seiner Priorität
        $this->listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority
        ];
        
        // Event-Cache ungültig machen, da sich die Listener geändert haben
        $this->eventCache[$event] = false;
        
        return $this;
    }
    
    /**
     * Event-Listener einmalig registrieren
     * 
     * @param string $event Name des Events
     * @param callable $callback Callback-Funktion
     * @param int $priority Priorität des Listeners
     * @return self
     */
    public function once(string $event, callable $callback, int $priority = 0): self
    {
        $onceCallback = function ($data) use (&$onceCallback, $event, $callback) {
            $this->off($event, $onceCallback);
            return $callback($data);
        };
        
        return $this->on($event, $onceCallback, $priority);
    }
    
    /**
     * Event-Listener entfernen
     * 
     * @param string $event Name des Events
     * @param callable|null $callback Spezifischer Callback oder null für alle
     * @return self
     */
    public function off(string $event, ?callable $callback = null): self
    {
        // Wenn kein Event existiert, nichts tun
        if (!isset($this->listeners[$event])) {
            return $this;
        }
        
        // Wenn kein Callback angegeben, alle Listener für dieses Event entfernen
        if ($callback === null) {
            unset($this->listeners[$event]);
            unset($this->eventCache[$event]);
            return $this;
        }
        
        // Spezifischen Callback entfernen
        foreach ($this->listeners[$event] as $key => $listener) {
            if ($listener['callback'] === $callback) {
                unset($this->listeners[$event][$key]);
            }
        }
        
        // Array neu indizieren
        if (empty($this->listeners[$event])) {
            unset($this->listeners[$event]);
            unset($this->eventCache[$event]);
        } else {
            $this->listeners[$event] = array_values($this->listeners[$event]);
            $this->eventCache[$event] = false;
        }
        
        return $this;
    }
    
    /**
     * Event auslösen
     * 
     * @param string $event Name des Events
     * @param mixed $data Daten, die an die Callbacks übergeben werden
     * @return mixed
     */
    public function trigger(string $event, $data = null)
    {
        if (!isset($this->listeners[$event]) || empty($this->listeners[$event])) {
            return $data;
        }
        
        // Sortiere Listener nach Priorität, wenn nicht bereits im Cache
        if (!isset($this->eventCache[$event]) || $this->eventCache[$event] === false) {
            usort($this->listeners[$event], function ($a, $b) {
                return $b['priority'] <=> $a['priority'];
            });
            $this->eventCache[$event] = true;
        }
        
        // Ausführen der Callbacks
        foreach ($this->listeners[$event] as $listener) {
            $result = ($listener['callback'])($data);
            if ($result !== null) {
                $data = $result;
            }
        }
        
        return $data;
    }
    
    /**
     * Prüfen, ob für ein Event Listener registriert sind
     * 
     * @param string $event Name des Events
     * @return bool
     */
    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && !empty($this->listeners[$event]);
    }
    
    /**
     * Alle Event-Listener entfernen
     * 
     * @return self
     */
    public function removeAllListeners(): self
    {
        $this->listeners = [];
        $this->eventCache = [];
        return $this;
    }
    
    /**
     * Anzahl der Listener für ein Event abrufen
     * 
     * @param string $event Name des Events
     * @return int
     */
    public function countListeners(string $event): int
    {
        return isset($this->listeners[$event]) ? count($this->listeners[$event]) : 0;
    }
}