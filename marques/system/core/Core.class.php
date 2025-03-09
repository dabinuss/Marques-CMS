<?php
declare(strict_types=1);

namespace Marques\Core;

abstract class Core {
    protected Docker $docker;

    public function __construct(Docker $docker) {
        $this->docker = $docker;
    }

    /**
     * Ruft einen Service aus dem Docker ab.
     *
     * @param string $service Name des Services
     * @return mixed Service-Instanz
     */
    protected function resolve(string $service) {
        return $this->docker->resolve($service);
    }

}