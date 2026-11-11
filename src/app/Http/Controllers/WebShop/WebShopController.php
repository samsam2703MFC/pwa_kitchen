<?php

namespace App\Kitchen\app\Http\Controllers\WebShop;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Repositories\WebShop\WebShopRepository;
use App\Kitchen\app\Services\WebShop\WebShopService;
use App\Kitchen\core\Support\DeviceMode;
use App\Kitchen\core\Support\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le mode WebShop : trois écrans de comptoir, rendus par Kitchen.
 *
 * ── Pourquoi ici et pas dans le back-office ──
 * Le back-office franchisé compte 37 sections pensées pour une souris et un
 * grand écran. Un comptoir en utilise trois, avec les doigts, souvent farinés.
 * On ne reconstruit pas le back-office : on écrit LES TROIS écrans qui servent
 * tous les jours, dans le langage tactile déjà éprouvé du reste de Kitchen. Le
 * cadre reste accessible pour le reste.
 *
 * ── Aucune connexion personnelle ──
 * La tablette est partagée par l'équipe. Son identité est le jeton d'appareil,
 * lu depuis un cookie HttpOnly et posé en en-tête côté serveur : il ne traverse
 * jamais le navigateur, ni une URL, ni un log.
 */
class WebShopController extends Controller
{
    private const VIEWS = ['prep', 'stock', 'board'];

    public function __construct(
        private WebShopRepository $webshop,
        private WebShopService $service
    ) {}

    #[Route('GET', '/webshop')]
    public function index(): void
    {
        $rules   = DeviceMode::rules();
        $base    = DeviceMode::webshopBase();
        $token   = DeviceMode::webshopToken();
        $blocker = $rules->webshopBlocker($base, $token);

        // Sans URL ou sans jeton, on dit lequel des deux manque et on renvoie
        // aux réglages. Un écran vide laisserait croire à une panne du webshop.
        if ($blocker !== null) {
            $this->view('webshop/index', $this->shell(null, ['webshop_blocker' => $blocker]));
            return;
        }

        $view = $_GET['view'] ?? 'prep';
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'prep';
        }

        $api = (string) $rules->webshopApiBase($base);

        // L'identité d'abord : c'est elle qui dit si le jeton vit encore, et
        // quels écrans cette tablette a le droit d'ouvrir. Sans ce premier
        // appel, on afficherait trois onglets dont deux répondraient 403.
        $me = $this->webshop->get($api, $token, '/franchisee/me');
        if (!$me['ok']) {
            $this->view('webshop/index', $this->shell(null, [
                'webshop_blocker' => $me['state'] === WebShopRepository::NETWORK ? null : 'revoked',
                'net_down'        => $me['state'] === WebShopRepository::NETWORK,
            ]));
            return;
        }

        $sections = (array) ($me['data']['sections'] ?? []);
        $data     = $this->shell($me['data'], ['active_view' => $view, 'sections' => $sections]);

        $data += match ($view) {
            'stock' => $this->stockData($api, $token, $sections),
            'board' => $this->boardData($api, $token, $sections),
            default => $this->prepData($api, $token, $sections),
        };

        // La pastille « à préparer » vit sur l'onglet, donc sur les trois
        // écrans : sans elle, on ne saurait qu'il y a du travail qu'en ouvrant
        // l'onglet où il est.
        if ($view !== 'prep' && in_array('commandes', $sections, true)) {
            $r = $this->webshop->get($api, $token, '/franchisee/fr-orders', ['date' => date('Y-m-d')]);
            $data['todo_count'] = $r['ok'] ? $this->service->countTodo($this->service->orders($r['data'])) : 0;
        }

        $this->view('webshop/index', $data);
    }

    /**
     * Faire avancer une commande : à préparer → prête → remise.
     *
     * C'est le seul geste d'écriture d'un comptoir, et le serveur le borne aussi
     * de son côté (trois statuts, et la boutique du jeton). On ne se repose pas
     * sur ce contrôle-ci : il est là pour que l'écran réponde vite, pas pour
     * autoriser.
     */
    #[Route('POST', '/ajax/webshop/order-status')]
    public function ajaxOrderStatus(Request $request): JsonResponse
    {
        $body   = $this->getJson($request);
        $id     = (string) ($body['id'] ?? '');
        $status = (string) ($body['status'] ?? '');

        if ($id === '' || !in_array($status, ['preparing', 'ready', 'delivered'], true)) {
            return new JsonResponse(['success' => false, 'description' => 'bad_request'], 400);
        }

        $rules = DeviceMode::rules();
        $base  = DeviceMode::webshopBase();
        $token = DeviceMode::webshopToken();
        if ($rules->webshopBlocker($base, $token) !== null) {
            return new JsonResponse(['success' => false, 'description' => 'not_configured'], 409);
        }

        $res = $this->webshop->post(
            (string) $rules->webshopApiBase($base),
            $token,
            '/franchisee/order-status',
            ['id' => $id, 'status' => $status]
        );

        return new JsonResponse(
            ['success' => $res['ok'], 'description' => $res['ok'] ? null : $res['state']],
            $res['ok'] ? 200 : 502
        );
    }

    /** Les données communes aux trois écrans. */
    private function shell(?array $me, array $extra): array
    {
        return $extra + [
            'active_view'     => $extra['active_view'] ?? 'prep',
            'shop_name'       => $me['shop']['name'] ?? null,
            'shop_city'       => $me['shop']['city'] ?? null,
            'sections'        => $extra['sections'] ?? [],
            'webshop_blocker' => $extra['webshop_blocker'] ?? null,
            'net_down'        => $extra['net_down'] ?? false,
            'today'           => date('Y-m-d'),
        ];
    }

    private function prepData(string $api, string $token, array $sections): array
    {
        if (!in_array('commandes', $sections, true)) {
            return ['closed_section' => 'commandes'];
        }

        $r = $this->webshop->get($api, $token, '/franchisee/fr-orders', ['date' => date('Y-m-d')]);
        if (!$r['ok']) {
            return ['fetch_state' => $r['state'], 'closed_section' => $r['section'] ?? null];
        }

        $orders = $this->service->orders($r['data']);

        return ['orders' => $orders, 'todo_count' => $this->service->countTodo($orders)];
    }

    private function stockData(string $api, string $token, array $sections): array
    {
        if (!in_array('stockJour', $sections, true)) {
            return ['closed_section' => 'stockJour'];
        }

        $r = $this->webshop->get($api, $token, '/franchisee/fr-stock-catalog');
        if (!$r['ok']) {
            return ['fetch_state' => $r['state'], 'closed_section' => $r['section'] ?? null];
        }

        $stock = $this->service->stock($r['data']);

        return ['stock' => $stock, 'out_count' => $this->service->countOut($stock)];
    }

    private function boardData(string $api, string $token, array $sections): array
    {
        if (!in_array('tdb', $sections, true)) {
            return ['closed_section' => 'tdb'];
        }

        $r = $this->webshop->get($api, $token, '/franchisee/kpis');
        if (!$r['ok']) {
            return ['fetch_state' => $r['state'], 'closed_section' => $r['section'] ?? null];
        }

        return ['kpis' => is_array($r['data']) ? $r['data'] : []];
    }
}
