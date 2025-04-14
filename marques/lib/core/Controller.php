<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Http\Response\ViewResponse;
use Marques\Http\Response\RedirectResponse;
use Marques\Core\Node;

/**
 * Minimale Controller-Basisklasse
 */
abstract class Controller
{
    protected Template $template;
    protected Node $container;
    
    /**
     * Konstruktor mit DI-Container
     */
    public function __construct(Node $container)
    {
        $this->container = $container;
        $this->template = $container->get(Template::class);
    }
    
    /**
     * Rendert eine View
     */
    protected function view(string $templateKey, array $data = []): ViewResponse
    {
        return new ViewResponse($this->template, $templateKey, $data);
    }
    
    /**
     * Leitet zu einer anderen URL weiter
     */
    protected function redirect(string $url): RedirectResponse
    {
        error_log("Redirecting to: $url");
        return new RedirectResponse($url);
    }
    
    /**
     * Sicherer Zugriff auf Session-Daten mit Punkt-Notation
     *
     * @param string $key Schlüssel (mit Punktnotation für verschachtelte Arrays)
     * @param mixed $default Standardwert, falls Schlüssel nicht existiert
     * @return mixed Wert oder Standardwert
     */
    protected function getSessionData(string $key, $default = null)
    {
        if (strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $value = $_SESSION;
            
            foreach ($parts as $part) {
                if (!isset($value[$part])) {
                    return $default;
                }
                $value = $value[$part];
            }
            
            return $value;
        }
        
        return $_SESSION[$key] ?? $default;
    }
}