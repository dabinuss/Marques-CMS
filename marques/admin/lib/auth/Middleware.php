<?php
declare(strict_types=1);

namespace Admin\Auth;

use Marques\Http\Request;
use Admin\Auth\Service;

class Middleware {
    private Service $Service;

    public function __construct(Service $Service) {
        $this->Service = $Service;
    }

    /**
     * Middleware-Funktion für Authentifizierung
     *
     * @param Request $request Die aktuelle Anfrage
     * @param array $params Route-Parameter
     * @param callable $next Die nächste Middleware oder der Controller
     * @return mixed Das Ergebnis der Middleware-Kette
     * @throws \RuntimeException Wenn der Zugriff verweigert wird
     */
    public function __invoke(Request $request, array $params, callable $next)
    {
        // Prüfe, ob Benutzer eingeloggt ist
        if (!$this->Service->isLoggedIn()) {
            // Beim AJAX-Anfragen: Fehler zurückgeben
            if ($request->isAjax()) {
                throw new \RuntimeException('Nicht autorisiert', 401);
            }
            
            // Bei normalen Anfragen: Auf Login-Seite umleiten
            $loginUrl = MARQUES_ADMIN_DIR . '/login';
            
            // Optional: Ursprüngliche URL als Redirect-Parameter mitgeben
            $returnUrl = urlencode($request->getPath());
            if (!empty($returnUrl)) {
                $loginUrl .= '?redirect=' . $returnUrl;
            }
            
            header('Location: ' . $loginUrl, true, 302);
            exit;
        }

        // Falls eingeloggt, weiter zur nächsten Middleware/Controller
        return $next($request, $params);
    }
}