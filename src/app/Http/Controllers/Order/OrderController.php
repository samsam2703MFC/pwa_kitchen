<?php

namespace App\Kitchen\app\Http\Controllers\Order;

use App\Kitchen\app\Models\Order\OrderModel;
use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Client\ClientService;
use App\Kitchen\app\Services\Knowledge\Product\ProductService;
use App\Kitchen\app\Services\Order\OrderService;
use App\Kitchen\core\Support\GlobalRegistry;
use App\Kitchen\core\Support\Route;
use Symfony\Component\HttpFoundation\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private ClientService $clientService,
        private ProductService $productService
    ) {}

    /**
     * Lista zamówień dla wybranego dnia.
     * GET /orders[?date=Y-m-d&client_name=...&pending_only=0]
     */
    #[Route('GET', '/orders')]
    public function index(): void
    {
        $date        = $_GET['date']        ?? date('Y-m-d');
        $clientName  = trim($_GET['client_name'] ?? '');
        $pendingOnly = !isset($_GET['pending_only']) || (int)$_GET['pending_only'] !== 0;

        // Walidacja formatu daty
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $orders = $this->safeFetch(
            fn() => $this->orderService->getOrders($date, $clientName ?: null, $pendingOnly),
            $this->errors,
            null,
            []
        );

        // Mode de remise : all | collect | delivery. Trié en PHP faute d'un
        // paramètre d'API (voir docs/ENDPOINTS_COMMANDES_WEB.md).
        $fulfilment = $_GET['fulfilment'] ?? 'all';
        if (!in_array($fulfilment, ['all', 'collect', 'delivery'], true)) {
            $fulfilment = 'all';
        }

        $hasFulfilmentData = $this->orderService->hasFulfilmentData($orders);
        $orders = $this->orderService->filterByFulfilment($orders, $fulfilment);

        $this->view('orders/overview', [
            'orders'              => $orders,
            'fulfilment'          => $fulfilment,
            'has_fulfilment_data' => $hasFulfilmentData,
            'date'                => $date,
            'client_name'         => $clientName,
            'pending_only' => $pendingOnly,
            'today'       => date('Y-m-d'),
        ]);
    }

    /**
     * Compteur des commandes en attente, pour le sondage du front.
     *
     * GET /ajax/orders/pending-count
     *
     * Volontairement minimal : appelé toutes les dix secondes sur chaque
     * tablette de chaque magasin, il ne doit renvoyer que des nombres. Le
     * détail par mode de remise reste à zéro tant que l'API ne sert pas le
     * champ correspondant (voir docs/ENDPOINTS_COMMANDES_WEB.md).
     */
    #[Route('GET', '/ajax/orders/pending-count')]
    public function pendingCount(): void
    {
        $orders = $this->orderService->getOrders(date('Y-m-d'), null, true);

        $collect = count($this->orderService->filterByFulfilment($orders, OrderModel::MODE_COLLECT));
        $delivery = count($this->orderService->filterByFulfilment($orders, OrderModel::MODE_DELIVERY));

        $json = $this->json([
            'total'    => count($orders),
            'collect'  => $collect,
            'delivery' => $delivery,
        ], 200);
        $json->send();
    }

    #[Route('GET', '/orders/new')]
    public function newOrder(): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $productOptions = ['period' => date('Y-m-d')];

        $b2bClients = $this->safeFetch(
            fn() => $this->clientService->getB2BClients(),
            $this->warnings,
            null,
            []
        );
        $b2cClients = $this->safeFetch(
            fn() => $this->clientService->getB2CClients(),
            $this->warnings,
            null,
            []
        );
        $products = $this->safeFetch(
            fn() => $this->productService->getAvailableToSale($productOptions),
            $this->warnings,
            null,
            []
        );

        $this->view('client/order/new', [
            'form_mode'   => 'create',
            'b2b_clients' => $b2bClients,
            'b2c_clients' => $b2cClients,
            'products'    => $products,
            'shop_id'     => $shopId,
            'order'       => null,
            'order_data'  => null,
        ]);
    }

    #[Route('GET', '/orders/{id:\d+}/edit')]
    public function edit(int $id): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $productOptions = ['period' => date('Y-m-d')];

        $order = $this->safeFetch(
            fn() => $this->orderService->getById($id),
            $this->errors,
            null,
            null
        );

        if ($order === null) {
            $this->errors[] = 'error_order_not_found';
            $this->view('errors/404', []);
            return;
        }

        $b2bClients = $this->safeFetch(
            fn() => $this->clientService->getB2BClients(),
            $this->warnings,
            null,
            []
        );
        $b2cClients = $this->safeFetch(
            fn() => $this->clientService->getB2CClients(),
            $this->warnings,
            null,
            []
        );
        $products = $this->safeFetch(
            fn() => $this->productService->getAvailableToSale($productOptions),
            $this->warnings,
            null,
            []
        );

        $this->view('client/order/new', [
            'form_mode'   => 'edit',
            'b2b_clients' => $b2bClients,
            'b2c_clients' => $b2cClients,
            'products'    => $products,
            'shop_id'     => $shopId,
            'order'       => $order,
            'order_data'  => $this->buildOrderFormData($order),
        ]);
    }

    /**
     * Szczegóły zamówienia.
     * GET /orders/{id}
     */
    #[Route('GET', '/orders/{id:\d+}')]
    public function show(int $id): void
    {
        $order = $this->safeFetch(
            fn() => $this->orderService->getById($id),
            $this->errors,
            null,
            null
        );

        if ($order === null) {
            $this->errors[] = 'error_order_not_found';
            $this->view('errors/404', []);
            return;
        }

        $this->view('orders/detail', [
            'order' => $order,
        ]);
    }

    #[Route('POST', '/ajax/client-orders')]
    public function ajaxInsert(): void
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            $response = $this->json(['status' => 'error', 'description' => 'Données de commande absentes.'], 400);
            $response->send();
            exit;
        }

        $response = $this->orderService->insert($data);
        $status = (int)($response['code'] ?? 400);

        $json = $this->json($response, $status);
        $json->send();
        exit;
    }

    #[Route('PATCH', '/ajax/client-orders/{id:\d+}')]
    public function ajaxUpdate(int $id): void
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);

        if (empty($data)) {
            $response = $this->json(['status' => 'error', 'description' => 'Données de commande absentes.'], 400);
            $response->send();
            exit;
        }

        $response = $this->orderService->update($id, $data);
        $status = (int)($response['code'] ?? 400);

        $json = $this->json($response, $status);
        $json->send();
        exit;
    }

    #[Route('DELETE', '/ajax/client-orders/{id:\d+}')]
    public function ajaxDelete(int $id): void
    {
        $response = $this->orderService->delete($id);
        $status = (int)($response['code'] ?? 200);

        $json = $this->json($response, $status);
        $json->send();
        exit;
    }

    private function buildOrderFormData($order): array
    {
        $products = [];

        foreach ($order->getProducts() as $product) {
            $products[] = [
                'id_product' => $product->getIdProduct(),
                'name' => $product->getName(),
                'weight' => $product->getWeight() ?? 0,
                'item_discount_value' => $product->getItemDiscountValue() ?? 0,
                'quantity' => $product->getQuantity() ?? 0,
                'unit_gross_price' => $product->getUnitGrossPrice() ?? 0,
                'total_gross_value_after_discount' => $product->getTotalGrossValueAfterDiscount() ?? 0,
            ];
        }

        return [
            'id' => $order->getId(),
            'id_client' => $order->getIdClient(),
            'pick_up_datetime' => $order->getPickUpDatetime(),
            'comment' => $order->getComment() ?? '',
            'products' => $products,
        ];
    }
}

