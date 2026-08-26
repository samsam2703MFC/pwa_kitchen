<?php
namespace App\Kitchen\core\Bootstrap;

use App\Kitchen\app\Http\Controllers\Auth\AuthController;

//require_once 'vendor/autoload.php';
use App\Kitchen\app\Http\Middleware\AuthMiddleware;
use Exception;
use FastRoute;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use function FastRoute\simpleDispatcher;


class App {
    private $namespace = '';
    private $controller = 'Auth';
    private $method = 'index';
    private $middleware;

    private array $publicControllers = [
        AuthController::class,
    ];

    public function __construct(AuthMiddleware $middleware) {
        $this->middleware = $middleware;
    }

    public function loadController()
    {
        $container = require __DIR__ . '/../Container/Container.php';

        $uri    = '/' . trim($_GET['url'] ?? 'auth', '/');
        $method = $_SERVER['REQUEST_METHOD'];

        // 1) Ustaw dispatcher
        $dispatcher = simpleDispatcher(
            require __DIR__ . '/routes.php'
        );

        // 2) Spróbuj dopasować przez router
        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                // Ce « break » sortait de la méthode sans rien écrire : toute URL
                // inconnue rendait une page blanche, en HTTP 200 par-dessus le
                // marché — donc invisible dans les logs, et mise en cache comme
                // une réponse valide. Le gabarit d'erreur existait déjà ; il
                // n'était simplement jamais appelé.
                $this->notFound($container, $uri);
                return;
            case Dispatcher::METHOD_NOT_ALLOWED:
                header('HTTP/1.1 405 Method Not Allowed');
                exit;
            case Dispatcher::FOUND:
                [$_, $handler, $vars] = $routeInfo;
                // $handler to ['controller'=>..., 'method'=>...]
                $controllerFQCN = $handler['controller'];
                $action         = $handler['method'];
                // Wciąż możesz wstrzyknąć ServiceFactory

                try {
                    // $container zawiera zarówno ServiceFactory jak i wszystkie nowe serwisy
                    $controller = $container->get($controllerFQCN);
                } catch (Exception $e) {
                    http_response_code(500);
                    echo "Erreur d'injection de dépendances : " . $e->getMessage();
                    exit;
                }

                // jeżeli potrzebujesz middleware:
                if (!in_array($controllerFQCN, $this->publicControllers, true)) {
                    $this->middleware->handle();

                    // Ce que chaque mode de tablette affiche vient de la table
                    // pwa_kitchen_param, via GET /devices/{id}/configuration. On la
                    // rafraîchit ici, après l'authentification — l'appel a
                    // besoin du jeton — et au plus une fois par tranche de dix
                    // minutes, le cache vivant dans un cookie.
                    //
                    // Le fetch est passé en closure : DeviceMode est un support
                    // statique et n'a pas de conteneur. Lui donner le dépôt
                    // plutôt que le conteneur garde la dépendance visible.
                    \App\Kitchen\core\Support\DeviceMode::refreshConfig(
                        static fn() => $container
                            ->get(\App\Kitchen\app\Repositories\Me\DeviceConfigRepository::class)
                            ->get()
                    );
                }
                // wywołaj z parametrami z {shopId}, {supplierId}
                $result = call_user_func_array([$controller, $action], $vars);

                if ($result instanceof Response) {
                    $result->send();
                    exit;
                }

                return;
        }
    }

    /**
     * Ce qu'on montre quand aucune route ne correspond.
     *
     * Deux réponses, parce qu'il y a deux appelants. Un écran attend du HTML et
     * quelqu'un le lit : il reçoit le gabarit d'erreur, avec un chemin de
     * retour. Un appel `/ajax/…` attend du JSON et personne ne le lit : lui
     * renvoyer une page HTML ferait échouer son `r.json()` sur une erreur de
     * syntaxe, ce qui masquerait la vraie cause.
     *
     * Le code HTTP est 404 dans les deux cas. C'était un 200 auparavant, ce qui
     * rendait les liens morts invisibles dans les logs du serveur.
     */
    private function notFound(ContainerInterface $container, string $uri): void
    {
        http_response_code(404);

        if (str_starts_with($uri, '/ajax/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'description' => 'not_found']);
            return;
        }

        try {
            // La langue de la tablette vit dans le jeton, et le middleware
            // d'authentification — qui la pose d'habitude — ne tourne pas ici :
            // le routage a échoué avant lui. Sans ce rattrapage, la page
            // d'erreur sortirait dans la langue du navigateur, pas dans celle
            // que le magasin a réglée. On lit la revendication sans vérifier la
            // signature : elle ne sert qu'à choisir un fichier de traduction,
            // aucune autorisation n'en dépend.
            $access = $_COOKIE['kitchen_access_token'] ?? null;
            if ($access) {
                $claims = $container->get(\App\Kitchen\app\Services\Auth\JwtService::class)
                                    ->getClaimsUnsafe($access);
                $lang = strtolower((string)($claims['dv_lncd'] ?? ''));
                if (in_array($lang, APP_SUPPORTED_LANGUAGES, true)) {
                    \App\Kitchen\core\Support\GlobalRegistry::set('lang_code', $lang);
                }
            }

            $container->get(\App\Kitchen\app\Http\Controllers\Controller::class)
                      ->view('errors/404', []);
        } catch (\Throwable $e) {
            // Le gabarit d'erreur qui échoue n'a pas le droit de rendre une page
            // blanche à son tour : c'est exactement le défaut qu'on corrige.
            echo '404';
        }
    }
}