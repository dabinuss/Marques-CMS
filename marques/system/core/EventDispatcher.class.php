<?php
declare(strict_types=1);
namespace Marques\Core;

class EventDispatcher extends Core {
    private $listeners = [];

    public function __construct(Docker $docker) {
      parent::__construct($docker);
    }
    
    public function on(string $event, callable $callback): self {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $callback;
        return $this;
    }
    
    public function dispatch(string $event, $data = null) {
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
