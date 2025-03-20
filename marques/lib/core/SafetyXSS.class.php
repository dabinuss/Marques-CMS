<?php
declare(strict_types=1);
namespace Marques\Core;

/**
 * Class SafetyXSS
 *
 * Diese Klasse kapselt zentrale Sicherheitsfunktionen zum Schutz vor XSS-Angriffen.
 * Sie bietet Funktionen zur Sanitization von Eingaben, kontextsensitives Escaping von Ausgaben,
 * das Setzen von CSP-Headern und weitere Sicherheitsmaßnahmen.
 */
class SafetyXSS
{
    /**
     * Voreingestellte CSP-Direktiven
     */
    private static array $defaultCSPDirectives = [
        'default-src'    => "'self'",
        'script-src'     => "'self'",
        'style-src'      => "'self'",
        'img-src'        => "'self'",
        'font-src'       => "'self'",
        'connect-src'    => "'self'",
        'media-src'      => "'self'",
        'object-src'     => "'none'",
        'child-src'      => "'self'",
        'frame-src'      => "'self'",
        'worker-src'     => "'self'",
        'form-action'    => "'self'",
        'base-uri'       => "'self'",
        'frame-ancestors' => "'none'",
        'upgrade-insecure-requests' => ""
    ];

    /**
     * Sanitiert Eingabedaten durch Entfernen von HTML-Tags, Trimmen von Leerzeichen 
     * und optional durch zusätzliche Filter.
     *
     * @param string $data Eingabedaten, z. B. von Formularen.
     * @param bool $stripHTML Optional: Ob HTML-Tags entfernt werden sollen (Standard: true).
     * @param bool $allowLineBreaks Optional: Ob Zeilenumbrüche erhalten bleiben sollen (Standard: false).
     * @return string Gesäuberte Eingabe.
     */
    public static function sanitizeInput(string $data, bool $stripHTML = true, bool $allowLineBreaks = false): string
    {
        // Entferne nicht druckbare Zeichen außer Zeilenumbrüche, wenn erlaubt
        if ($allowLineBreaks) {
            $data = preg_replace('/[^\P{C}\r\n]/u', '', $data);
        } else {
            $data = preg_replace('/\p{C}/u', '', $data);
        }
        
        // Entferne HTML-Tags, wenn gewünscht
        if ($stripHTML) {
            $data = strip_tags($data);
        }
        
        return trim($data);
    }

    /**
     * Sanitiert ein Array von Eingabedaten rekursiv.
     *
     * @param array $dataArray Ein Array mit Eingabedaten.
     * @param bool $stripHTML Optional: Ob HTML-Tags entfernt werden sollen (Standard: true).
     * @param bool $allowLineBreaks Optional: Ob Zeilenumbrüche erhalten bleiben sollen (Standard: false).
     * @return array Das Array mit gesäuberten Werten.
     */
    public static function sanitizeInputArray(array $dataArray, bool $stripHTML = true, bool $allowLineBreaks = false): array
    {
        $result = [];
        
        foreach ($dataArray as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::sanitizeInputArray($value, $stripHTML, $allowLineBreaks);
            } elseif (is_string($value)) {
                $result[$key] = self::sanitizeInput($value, $stripHTML, $allowLineBreaks);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Escape output based on the given context.
     *
     * Mögliche Kontexte sind:
     * - 'html': Für normalen HTML-Inhalt (nutzt htmlspecialchars)
     * - 'js': Für JavaScript-Kontext (nutzt json_encode)
     * - 'css': Für CSS-Kontext (escapt alle nicht-alphanumerischen Zeichen)
     * - 'url': Für URLs (nutzt rawurlencode)
     * - 'attr': Für HTML-Attribute (nutzt htmlspecialchars mit zusätzlichen Flags)
     * - 'json': Für JSON-Ausgabe (nutzt json_encode mit Flags)
     *
     * @param string $data Die auszugebenden Daten.
     * @param string $context Der Kontext, in dem die Daten verwendet werden sollen.
     * @return string Der entsprechend escaped String.
     * @throws \InvalidArgumentException Wenn ein ungültiger Kontext angegeben wird.
     */
    public static function escapeOutput(string $data, string $context = 'html'): string
    {
        switch ($context) {
            case 'html':
                return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
            case 'js':
                // json_encode gibt einen sicheren JavaScript-String zurück.
                // Entferne die umschließenden Anführungszeichen.
                $result = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                if ($result === false) {
                    throw new \InvalidArgumentException('Ungültige Daten für JavaScript-Escaping');
                }
                return substr($result, 1, -1);
                
            case 'css':
                return preg_replace_callback('/[^\w\s-]/u', function ($matches) {
                    $char = $matches[0];
                    $ord = function_exists('mb_ord') ? mb_ord($char, 'UTF-8') : ord($char);
                    return '\\' . strtoupper(dechex($ord)) . ' ';
                }, $data);
                
            case 'url':
                return rawurlencode($data);
                
            case 'attr':
                // Spezielles Escaping für HTML-Attribute
                return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
            case 'json':
                // Für direkte JSON-Ausgabe (ohne umschließende Anführungszeichen zu entfernen)
                $result = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                if ($result === false) {
                    throw new \InvalidArgumentException('Ungültige Daten für JSON-Ausgabe');
                }
                return $result;
                
            default:
                throw new \InvalidArgumentException("Ungültiger Escaping-Kontext: {$context}");
        }
    }

    /**
     * Escapt ein Array von Ausgabedaten rekursiv für den angegebenen Kontext.
     *
     * @param array $dataArray Ein Array mit auszugebenden Daten.
     * @param string $context Der Kontext, in dem die Daten verwendet werden sollen.
     * @return array Das Array mit escaped Werten.
     */
    public static function escapeOutputArray(array $dataArray, string $context = 'html'): array
    {
        $result = [];
        
        foreach ($dataArray as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::escapeOutputArray($value, $context);
            } elseif (is_string($value)) {
                $result[$key] = self::escapeOutput($value, $context);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Setzt den Content Security Policy (CSP) Header.
     *
     * Falls keine Direktiven angegeben sind, wird eine Standard-CSP gesetzt,
     * die Skripte, Styles und Bilder nur von der eigenen Domain zulässt und andere
     * Quellen explizit blockiert.
     *
     * @param array|null $directives Assoziatives Array mit CSP-Direktiven.
     * @param bool $reportOnly Ob der Header als Report-Only gesetzt werden soll.
     * @param string|null $reportUri URI für CSP-Verletzungsberichte.
     * @return bool True bei Erfolg, False wenn Header bereits gesendet wurden.
     */
    public static function setCSPHeader(?array $directives = null, bool $reportOnly = false, ?string $reportUri = null): bool
    {
        if (headers_sent()) {
            return false;
        }

        $finalDirectives = $directives ?? self::$defaultCSPDirectives;
        
        // Füge Report-URI hinzu, wenn angegeben
        if ($reportUri !== null) {
            $finalDirectives['report-uri'] = $reportUri;
        }
        
        // Baue den Header-String aus den Direktiven
        $csp = [];
        foreach ($finalDirectives as $directive => $value) {
            if ($value === "") {
                $csp[] = $directive;
            } else {
                $csp[] = "{$directive} {$value}";
            }
        }
        
        $headerName = $reportOnly ? "Content-Security-Policy-Report-Only" : "Content-Security-Policy";
        header("{$headerName}: " . implode('; ', $csp));
        
        return true;
    }

    /**
     * Setzt weitere wichtige Sicherheitsheader.
     *
     * @param bool $includeHSTS Ob der Strict-Transport-Security-Header gesetzt werden soll.
     * @param int $hstsMaxAge Max-Age für HSTS in Sekunden (Standard: 1 Jahr).
     * @param bool $hstsIncludeSubDomains Ob HSTS auch für Subdomains gelten soll.
     * @param bool $hstsPreload Ob die Domain für HSTS-Preloading markiert werden soll.
     * @return bool True bei Erfolg, False wenn Header bereits gesendet wurden.
     */
    public static function setSecurityHeaders(
        bool $includeHSTS = true,
        int $hstsMaxAge = 31536000,
        bool $hstsIncludeSubDomains = true,
        bool $hstsPreload = false
    ): bool {
        if (headers_sent()) {
            return false;
        }
        
        // X-Content-Type-Options gegen MIME-Sniffing
        header('X-Content-Type-Options: nosniff');
        
        // X-Frame-Options gegen Clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // X-XSS-Protection (veraltet, aber noch nützlich für ältere Browser)
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer-Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions-Policy (ehemals Feature-Policy)
        header('Permissions-Policy: geolocation=self, microphone=(), camera=(), payment=()');
        
        // HTTP Strict Transport Security (HSTS)
        if ($includeHSTS && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $hstsHeader = "max-age={$hstsMaxAge}";
            
            if ($hstsIncludeSubDomains) {
                $hstsHeader .= '; includeSubDomains';
            }
            
            if ($hstsPreload) {
                $hstsHeader .= '; preload';
            }
            
            header("Strict-Transport-Security: {$hstsHeader}");
        }
        
        return true;
    }
    
    /**
     * Validiert eine URL auf Sicherheit.
     * Überprüft, ob die URL einem erlaubten Schema folgt und optional,
     * ob sie zu einer erlaubten Domain gehört.
     *
     * @param string $url Die zu prüfende URL.
     * @param array $allowedSchemes Erlaubte URL-Schemata (Standard: http, https).
     * @param array|null $allowedDomains Erlaubte Domains (null = keine Einschränkung).
     * @return bool True, wenn die URL als sicher eingestuft wird.
     */
    public static function validateUrl(
        string $url,
        array $allowedSchemes = ['http', 'https'],
        ?array $allowedDomains = null
    ): bool {
        // Parse die URL
        $parsedUrl = parse_url($url);
        
        // Prüfe, ob die URL gültig ist und ein Schema hat
        if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
            return false;
        }
        
        // Prüfe, ob das Schema erlaubt ist
        if (!in_array(strtolower($parsedUrl['scheme']), array_map('strtolower', $allowedSchemes), true)) {
            return false;
        }
        
        // Falls Domains eingeschränkt sind, prüfe die Domain
        if ($allowedDomains !== null && isset($parsedUrl['host'])) {
            $host = strtolower($parsedUrl['host']);
            
            $isAllowed = false;
            foreach ($allowedDomains as $domain) {
                // Prüfe exakte Übereinstimmung oder Subdomain
                $domain = strtolower($domain);
                if ($host === $domain || substr($host, -(strlen($domain) + 1)) === ".$domain") {
                    $isAllowed = true;
                    break;
                }
            }
            
            if (!$isAllowed) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Generiert ein sicheres Nonce für die Verwendung in CSP.
     * 
     * @param int $length Länge des Nonce (Standard: 16 Bytes).
     * @return string Das generierte Nonce im Base64-Format.
     */
    public static function generateCSPNonce(int $length = 16): string
    {
        return base64_encode(random_bytes($length));
    }
    
    /**
     * Aktualisiert CSP-Direktiven mit einem Nonce für Inline-Skripte oder -Styles.
     * 
     * @param array $directives Bestehende CSP-Direktiven.
     * @param string $nonce Das zu verwendende Nonce.
     * @param bool $includeScripts Ob das Nonce für Skripte hinzugefügt werden soll.
     * @param bool $includeStyles Ob das Nonce für Styles hinzugefügt werden soll.
     * @return array Aktualisierte CSP-Direktiven.
     */
    public static function addCSPNonce(
        array $directives,
        string $nonce,
        bool $includeScripts = true,
        bool $includeStyles = true
    ): array {
        $nonceValue = "'nonce-{$nonce}'";
        
        if ($includeScripts && isset($directives['script-src'])) {
            $directives['script-src'] .= " {$nonceValue}";
        } elseif ($includeScripts) {
            $directives['script-src'] = "'{$nonceValue}'";
        }
        
        if ($includeStyles && isset($directives['style-src'])) {
            $directives['style-src'] .= " {$nonceValue}";
        } elseif ($includeStyles) {
            $directives['style-src'] = "'{$nonceValue}'";
        }
        
        return $directives;
    }
}