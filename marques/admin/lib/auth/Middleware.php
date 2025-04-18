<?php
declare(strict_types=1);

namespace Admin\Auth;

use Marques\Http\Request;
use Admin\Auth\Service;
use Admin\Http\Router as AdminRouter;

class Middleware {
    private Service $Service;
    private AdminRouter $router;

    public function __construct(Service $Service, AdminRouter $router) {
        $this->Service = $Service;
        $this->router = $router;
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
        // Hole den vollständigen Request URI (Pfad + Query) aus der Server-Variable
        // Dies ist die zuverlässigste Methode, wenn das Request-Objekt keine eigene Methode dafür hat.
        $fullRequestUri = $_SERVER['REQUEST_URI'] ?? '/';
        // Extrahiere nur den Pfad für Vergleiche und sauberes Logging
        $currentPath = parse_url($fullRequestUri, PHP_URL_PATH) ?? '/';

        // Logge den Start der Middleware-Ausführung mit relevanten Infos
        error_log("[Middleware] Running for path: " . $currentPath . " | Full URI: " . $fullRequestUri . " | Session ID: " . session_id() . " | Session User: ".(isset($_SESSION['marques_user']['username']) ? $_SESSION['marques_user']['username'] : 'NONE'));

        // Prüfe mithilfe des Auth-Service, ob der Benutzer eingeloggt ist
        if (!$this->Service->isLoggedIn()) {
            // Benutzer ist NICHT eingeloggt
            error_log("[Middleware] isLoggedIn check FAILED for path: " . $currentPath);

            // Leere vorsichtshalber alle Output-Buffer, bevor Header gesendet werden
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Spezielle Behandlung für AJAX-Anfragen: Sende JSON-Fehler und Status 401
            if ($request->isAjax()) { // Annahme: isAjax() Methode existiert in deiner Request-Klasse
                 error_log("[Middleware] AJAX request denied (401).");
                 http_response_code(401); // Setze HTTP-Status auf "Nicht autorisiert"
                 // Stelle sicher, dass der Content-Type gesetzt ist, bevor JSON ausgegeben wird
                 if (!headers_sent()) {
                    header('Content-Type: application/json');
                 }
                 echo json_encode(['error' => 'Nicht autorisiert']); // Sende Fehler als JSON
                 exit; // Beende die Skriptausführung
            }

            // Für normale (nicht-AJAX) Anfragen: Leite zur Login-Seite um
            // Generiere die korrekte URL zur Login-Seite über den Router
            $loginUrl = $this->router->getAdminUrl('admin.login'); // z.B. /admin/login

            // Bereite die ursprünglich angeforderte URI als 'redirect'-Parameter vor
            $returnPathAndQuery = $fullRequestUri;

            // Prüfe, ob die Redirect-URL sicher ist
            if (!$this->Service->isValidRedirectUrl($returnPathAndQuery)) {
                error_log("[Middleware] Unsichere Redirect-URL erkannt: " . $returnPathAndQuery);
                $returnPathAndQuery = $this->router->getAdminUrl('admin.dashboard');
            }

            // Verhindere eine Endlosschleife: Füge den 'redirect'-Parameter NICHT hinzu,
            // wenn die aktuelle Seite bereits die Login-Seite ist.
            $loginPathOnly = parse_url($loginUrl, PHP_URL_PATH);
            // Vergleiche nur die Pfade (ohne abschließenden Slash)
            if (rtrim($currentPath,'/') !== rtrim($loginPathOnly,'/')) {
                // Hänge den 'redirect'-Parameter an, Wert muss URL-kodiert sein
                $loginUrl .= '?redirect=' . urlencode($returnPathAndQuery);
                error_log("[Middleware] Added redirect parameter: " . urlencode($returnPathAndQuery));
            } else {
                error_log("[Middleware] Current path is login path, not adding redirect parameter.");
            }

            // Logge die finale URL, zu der umgeleitet wird
            error_log("[Middleware] Preparing redirect to: " . $loginUrl);

            // Prüfe, ob bereits Header gesendet wurden (z.B. durch vorherige Fehler, Leerzeichen vor <?php)
            if (headers_sent($file, $line)) {
                // Fallback: Wenn Header gesendet wurden, nutze JavaScript für die Umleitung
                 error_log("[Middleware] HEADERS ALREADY SENT by $file:$line. Using JS redirect.");
                echo '<script>window.location.href = "' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '";</script>';
                // Füge einen Fallback für Benutzer ohne JavaScript hinzu
                echo '<noscript>Bitte <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">hier klicken</a>, um sich anzumelden.</noscript>';
                exit; // Beende die Skriptausführung
            }

            // Standardfall: Sende einen HTTP 302 Found Redirect Header
            header('Location: ' . $loginUrl, true, 302); // true ersetzt evtl. vorhandenen Location-Header, 302 ist Standard für temporäre Umleitung nach Login
            error_log("[Middleware] Redirect header sent.");
            exit; // Beende die Skriptausführung
        }

        // --- BENUTZER IST EINGELOGGT ---

        error_log("[Middleware] isLoggedIn check PASSED for path: " . $currentPath . ". User: " . ($_SESSION['marques_user']['username'] ?? 'UNKNOWN'));

        if (isset($_SESSION['marques_user'])) {
            $_SESSION['marques_user']['last_activity'] = time();
            error_log("[Middleware] Updated last_activity for user: " . $_SESSION['marques_user']['username']);
        } else {
             error_log("[Middleware] WARNING: Session data lost after regeneration!");
        }
        error_log("[Middleware] Calling next handler for path: " . $currentPath);
        return $next($request, $params); // Führe den nächsten Schritt in der Request-Kette aus
    }
}