<?php
namespace Marques\Core;

/**
 * Einfaches Event-Management-System
 */
class EventManager {
    private $listeners = [];
    
    /**
     * Event-Listener registrieren
     */
    public function on($event, callable $callback) {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        
        $this->listeners[$event][] = $callback;
        return $this;
    }
    
    /**
     * Event auslösen
     */
    public function trigger($event, $data = null) {
        if (!isset($this->listeners[$event])) {
            return $data;
        }
        
        foreach ($this->listeners[$event] as $callback) {
            $result = call_user_func($callback, $data);
            if ($result !== null) {
                $data = $result;
            }
        }
        
        return $data;
    }
}