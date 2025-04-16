<?php
declare(strict_types=1);

namespace Marques\Http\Response;

use Marques\Http\Response;
use Marques\Core\Template;
use Admin\Core\Template as AdminTemplate;

/**
 * Einfache Response-Klasse für Views
 */
class ViewResponse extends Response
{
    private Template $template;
    private string $templateKey;
    private array $viewData;

    /**
     * Konstruktor
     */
    public function __construct(Template $template, string $templateKey, array $viewData = [])
    {
        parent::__construct('', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $this->template = $template;
        $this->templateKey = $templateKey;
        $this->viewData = $viewData;
        
        // Token-basierte Variablen automatisch setzen, wenn die Methode existiert
        if (method_exists($template, 'setVariables')) {
            $tokensData = [];
            foreach ($viewData as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $tokensData[$key] = (string)$value;
                }
            }
            $template->setVariables($tokensData);
        }
    }

    /**
     * Gibt die Template-Instanz zurück, damit Controller Blöcke setzen können
     * 
     * @return Template
     */
    public function getTemplate(): Template
    {
        return $this->template;
    }
    
    /**
     * Fügt zusätzliche View-Daten hinzu und aktualisiert die Tokens
     * 
     * @param array $data Zusätzliche Daten
     * @return self
     */
    public function withAdditionalData(array $data): self
    {
        $this->viewData = array_merge($this->viewData, $data);
        
        // Neue Variablen als Tokens registrieren, wenn die Methode existiert
        if (method_exists($this->template, 'setVariables')) {
            $tokensData = [];
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $tokensData[$key] = (string)$value;
                }
            }
            $this->template->setVariables($tokensData);
        }
        
        return $this;
    }

    /**
     * Führt die Response aus (rendert das Template)
     */
    public function execute(): void
    {
        $this->sendHeaders();
        
        // Prüfen, ob wir ein Admin-Template haben
        if ($this->template instanceof AdminTemplate) {
            // Admin-Template-Render-Methode mit zwei Parametern
            $this->template->render($this->viewData, $this->templateKey);
        } else {
            // Ursprüngliche Template-Render-Methode mit einem Parameter
            $data = $this->viewData;
            $data['template'] = $this->templateKey;
            $this->template->render($data);
        }
    }
}