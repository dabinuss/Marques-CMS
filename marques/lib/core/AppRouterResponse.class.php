<?php
declare(strict_types=1);

namespace Marques\Core;

use Marques\Core\SafetyXSS;

class AppRouterResponse {
    private int $status;
    private string $content;
    private array $headers;

    public function __construct(int $status = 200, string $content = '', array $headers = []) {
        $this->status  = $status;
        $this->content = $content;
        $this->headers = $headers;
    }

    public function withJson(array $data, int $status = 200): self {
        $new = clone $this;
        $new->status = $status;
        $new->content = json_encode($data);
        $new->headers['Content-Type'] = 'application/json';
        return $new;
    }

    public function withXml(array $data, int $status = 200): self {
        $new = clone $this;
        $new->status = $status;
        $xml = new \SimpleXMLElement('<response/>');
        foreach ($data as $key => $value) {
            $xml->addChild($key, SafetyXSS::escapeOutput((string)$value, 'html'));
        }
        $new->content = $xml->asXML();
        $new->headers['Content-Type'] = 'application/xml';
        return $new;
    }

    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->content;
    }
}
