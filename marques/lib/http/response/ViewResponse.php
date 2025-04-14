<?php
declare(strict_types=1);

namespace Marques\Http\Response;

use Marques\Http\Response;
use Marques\Core\Template;

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
    }

    /**
     * Führt die Response aus (rendert das Template)
     */
    public function execute(): void
    {
        $this->sendHeaders();
        
        // Template-Key zum viewData-Array hinzufügen, wie von Template::render() erwartet
        $data = $this->viewData;
        $data['template'] = $this->templateKey;
        
        $this->template->render($data);
    }
}