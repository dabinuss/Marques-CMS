<?php
declare(strict_types=1);

namespace Marques\Http\Response;

use Marques\Http\Response;

/**
 * Einfache Response-Klasse für Weiterleitungen
 */
class RedirectResponse extends Response
{
    private string $url;

    /**
     * Konstruktor
     */
    public function __construct(string $url)
    {
        parent::__construct('', 302);
        $this->url = $url;
    }

    /**
     * Führt die Response aus (leitet weiter)
     */
    public function execute(): void
    {
        if (headers_sent()) {
            echo '<script>window.location.href = "' . 
                 htmlspecialchars($this->url, ENT_QUOTES, 'UTF-8') . 
                 '";</script>';
            echo '<noscript>Bitte <a href="' . 
                 htmlspecialchars($this->url, ENT_QUOTES, 'UTF-8') . 
                 '">hier klicken</a>, um fortzufahren.</noscript>';
        } else {
            $this->addHeader('Location', $this->url);
            $this->sendHeaders();
        }
        exit;
    }
}