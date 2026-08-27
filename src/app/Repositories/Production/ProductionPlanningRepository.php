<?php

namespace App\Kitchen\app\Repositories\Production;

use App\Kitchen\core\Http\ApiClient;

/**
 * Les ventes par produit d'une journée — la source du pilotage de production.
 *
 * `GET /shops/{id}/statistics/sales/product-category-groups?date_from=D&date_to=D`
 * Forme RÉELLE, relevée sur la prod (27/08/2026) : une liste plate, une entrée
 * par produit, avec identifiant, secteur, quantité vendue ET délai de
 * préparation :
 *   { product_id, product_name, group_name, category_name,
 *     sector_id, sector_name, preparation_lead_time_hours,
 *     sold_qty, full_product_equivalent, total_earning, margin_value, … }
 *
 * ── Pourquoi celle-ci, et pas production-planning ──
 * production-planning agrège en groupe→catégorie→produit SANS identifiant, et
 * reste « journée entière » quel que soit le paramètre. product-category-groups
 * porte le vrai `product_id`, le secteur (pour le « par section ») et le
 * lead-time (pour l'ETA) — tout ce dont l'écran a besoin.
 *
 * ── Le découpage par créneau n'existe pas encore ──
 * Aucun paramètre ne filtre par tranche horaire (vérifié). Cet écran travaille
 * donc en JOURNÉE ENTIÈRE. Le jour où l'ERP ajoute `sales_daypart_id` (voir la
 * demande transmise), seule la date d'appel devient (date, créneau) — le reste
 * ne bouge pas.
 *
 * ── Aucun repli ──
 * Route muette → null. On ne fabrique pas de ventes.
 */
class ProductionPlanningRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * Les ventes par produit d'un jour, normalisées.
     *
     * @return array<int, array{id: int, name: string, sector: string, qty: float, lead_hours: ?float}>|null
     *         null = journée non servie (à distinguer d'un jour sans vente, []).
     */
    public function productSales(int $shopId, string $date): ?array
    {
        if ($shopId <= 0) {
            return null;
        }

        $res = $this->apiClient->where(
            "/shops/{$shopId}/statistics/sales/product-category-groups",
            ['date_from' => $date, 'date_to' => $date]
        );
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        return self::rowsOf($data);
    }

    /**
     * Les drapeaux de pilotage du catalogue vendable.
     *
     * `GET /shops/{id}/products/available` — le catalogue complet de la
     * boutique. On n'en retient que ce que le flux de production consomme :
     * qui se prépare la veille (`is_pdm`, `is_prepared_before_sales`), combien
     * de temps le produit tient en vitrine (`shelf_life_minutes`), et les
     * paramètres de recuisson. Relevé réel du 27/08/2026 : les champs existent
     * pour les 583 produits, la plupart restent à remplir au back-office —
     * l'écran doit donc traiter « drapeau absent » comme « non coché », jamais
     * inventer.
     *
     * @return array<int, array{pdm: bool, shelf_minutes: ?int,
     *                          reheat_minutes: ?int, reheat_celsius: ?int}>|null
     *         null = route non servie.
     */
    public function productFlags(int $shopId): ?array
    {
        if ($shopId <= 0) {
            return null;
        }

        $res = $this->apiClient->get("/shops/{$shopId}/products/available");
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        return self::flagsOf($data);
    }

    /**
     * Extrait les drapeaux d'une réponse du catalogue — pur.
     *
     * « PDM » au sens du flux : un produit qui se prépare AVANT le service —
     * `is_pdm` OU `is_prepared_before_sales`. Les deux drapeaux existent au
     * catalogue réel et disent la même chose pour l'atelier : ça ne se lance
     * pas le matin même.
     *
     * @param array<mixed> $data
     * @return array<int, array{pdm: bool, shelf_minutes: ?int,
     *                          reheat_minutes: ?int, reheat_celsius: ?int}>
     */
    public static function flagsOf(array $data): array
    {
        foreach (['data', 'items', 'products'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $data = $data[$k];
                break;
            }
        }
        if (!array_is_list($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? $row['product_id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                continue;
            }
            $shelf  = $row['shelf_life_minutes'] ?? null;
            $rmin   = $row['reheating_time_minutes'] ?? null;
            $rcel   = $row['reheating_temperature_celsius'] ?? null;
            $stock = $row['in_stock'] ?? null;
            $out[(int)$id] = [
                'name'           => (string)($row['name'] ?? ('#' . $id)),
                'pdm'            => !empty($row['is_pdm']) || !empty($row['is_prepared_before_sales']),
                // Le stock catalogue, tel que servi. Relevé réel : la valeur
                // n'est pas encore maintenue (999 presque partout) — l'écran
                // l'affiche telle quelle et dit d'où viendra la vraie tenue.
                'stock'          => is_numeric($stock) ? (float)$stock : null,
                'shelf_minutes'  => is_numeric($shelf) && (int)$shelf > 0 ? (int)$shelf : null,
                // 0 = « pas de recuisson definie », pas « recuisson instantanee ».
                'reheat_minutes' => is_numeric($rmin) && (int)$rmin > 0 ? (int)$rmin : null,
                'reheat_celsius' => is_numeric($rcel) && (int)$rcel > 0 ? (int)$rcel : null,
            ];
        }

        return $out;
    }

    /**
     * Déclare une démarque : ces pièces partent à la poubelle.
     *
     * `POST /shops/{shopId}/products/{productId}/waste` — demandé par le
     * métier (fin de journée : jeter avec photo et quantité). Le Swagger du
     * 26/08 ne documentait que le GET sur ce chemin : si le back refuse le
     * POST, l'écran affiche l'erreur en nommant la route — on n'invente pas
     * de succès.
     *
     * @return array{success: bool, error: ?string}
     */
    public function wasteOut(int $shopId, int $productId, float $qty, string $reason, ?string $photoBase64): array
    {
        if ($shopId <= 0 || $productId <= 0 || $qty <= 0) {
            return ['success' => false, 'error' => 'invalid_input'];
        }

        $body = ['quantity' => $qty, 'reason' => $reason];
        if ($photoBase64 !== null && $photoBase64 !== '') {
            $body['photo_base64'] = $photoBase64;
        }

        $res = $this->apiClient->post("/shops/{$shopId}/products/{$productId}/waste", $body);

        return ($res['success'] ?? false)
            ? ['success' => true, 'error' => null]
            : ['success' => false, 'error' => 'POST /shops/{id}/products/{id}/waste'];
    }

    /**
     * Reporte des pièces au stock de demain : un mouvement d'entrée.
     *
     * `POST /product-movements` — la route existe au Swagger, son corps n'est
     * pas documenté. Le corps envoyé ici est explicite et nommé ; si le back
     * le refuse, l'écran affiche l'erreur en nommant la route.
     *
     * @return array{success: bool, error: ?string}
     */
    public function stockIn(int $shopId, int $productId, float $qty): array
    {
        if ($shopId <= 0 || $productId <= 0 || $qty <= 0) {
            return ['success' => false, 'error' => 'invalid_input'];
        }

        $res = $this->apiClient->post('/product-movements', [
            'id_shop'    => $shopId,
            'id_product' => $productId,
            'quantity'   => $qty,
            'type'       => 'IN',
            'source'     => 'kitchen_end_of_day',
        ]);

        return ($res['success'] ?? false)
            ? ['success' => true, 'error' => null]
            : ['success' => false, 'error' => 'POST /product-movements'];
    }

    /**
     * Les quantités commandées par les clients pour un jour de retrait.
     *
     * `GET /shops/{id}/client-orders?date_from=&date_to=&include=products` —
     * forme relevée le 27/08/2026 : une liste de commandes, chacune avec
     * `order_status` et ses lignes `products[{ id_product, name, quantity }]`.
     * La mise en rayon doit RÉSERVER ces pièces : on agrège par produit, en
     * écartant les commandes déjà retirées (`picked_up`) — elles ne sont plus
     * à réserver.
     *
     * @return array{orders: int, lines: array<int, array{id: int, name: string, qty: float}>}|null
     *         null = route non servie.
     */
    public function orderedForDay(int $shopId, string $date): ?array
    {
        if ($shopId <= 0) {
            return null;
        }

        $res = $this->apiClient->where(
            "/shops/{$shopId}/client-orders",
            ['date_from' => $date, 'date_to' => $date, 'include' => 'products']
        );
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        return self::orderedRowsOf($data);
    }

    /**
     * Agrège les lignes produit des commandes d'un jour — pur.
     *
     * @param array<mixed> $data
     * @return array{orders: int, lines: array<int, array{id: int, name: string, qty: float}>}
     */
    public static function orderedRowsOf(array $data): array
    {
        foreach (['data', 'items', 'orders'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $data = $data[$k];
                break;
            }
        }
        if (!array_is_list($data)) {
            return ['orders' => 0, 'lines' => []];
        }

        $agg = [];
        $orders = 0;
        foreach ($data as $order) {
            if (!is_array($order)) {
                continue;
            }
            // Une commande retirée n'est plus à réserver.
            if (($order['order_status'] ?? null) === 'picked_up') {
                continue;
            }
            $lines = $order['products'] ?? null;
            if (!is_array($lines)) {
                continue;
            }
            $orders++;
            foreach ($lines as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $id = $l['id_product'] ?? null;
                $q  = $l['quantity'] ?? 0;
                if ($id === null || !is_numeric($id) || !is_numeric($q)) {
                    continue;
                }
                $id = (int)$id;
                $agg[$id]['id']   = $id;
                $agg[$id]['name'] = (string)($l['name'] ?? ('#' . $id));
                $agg[$id]['qty']  = ($agg[$id]['qty'] ?? 0.0) + (float)$q;
            }
        }

        $lines = array_values($agg);
        usort($lines, static fn(array $a, array $b): int =>
            [-$a['qty'], mb_strtolower($a['name'])] <=> [-$b['qty'], mb_strtolower($b['name'])]);

        return ['orders' => $orders, 'lines' => $lines];
    }

    /**
     * Les réductions programmées actives de la boutique.
     *
     * `GET /shops/{id}/scheduled-product-discounts/active` — forme relevée le
     * 27/08/2026 : `{ items: [...] }`. Une liste vide est une réponse (« aucune
     * réduction en cours »), pas une panne.
     *
     * @return array<int, array<string, mixed>>|null null = route non servie.
     */
    public function activeDiscounts(int $shopId): ?array
    {
        if ($shopId <= 0) {
            return null;
        }

        $res = $this->apiClient->get("/shops/{$shopId}/scheduled-product-discounts/active");
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        $items = $data['items'] ?? $data;

        return is_array($items) && array_is_list($items) ? $items : null;
    }

    /**
     * La démarque produits d'une plage de dates, normalisée.
     *
     * `GET /shops/{id}/products/waste?date_from=&date_to=` — forme relevée le
     * 27/08/2026 : `{ shop, products: [{ id_product, product_name, waste_qty,
     * top_reason, … }], grouped_products, period_summary }`.
     *
     * @return array<int, array{id: int, name: string, qty: float, reason: string}>|null
     *         null = route non servie.
     */
    public function waste(int $shopId, string $dateFrom, string $dateTo): ?array
    {
        if ($shopId <= 0) {
            return null;
        }

        $res = $this->apiClient->where(
            "/shops/{$shopId}/products/waste",
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        return self::wasteRowsOf($data);
    }

    /**
     * Extrait les lignes de démarque d'une réponse — pur.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{id: int, name: string, qty: float, reason: string}>
     */
    public static function wasteRowsOf(array $data): array
    {
        $rows = $data['products'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id_product'] ?? $row['product_id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                continue;
            }
            $qty = $row['waste_qty'] ?? 0;
            $out[] = [
                'id'     => (int)$id,
                'name'   => (string)($row['product_name'] ?? ('#' . $id)),
                'qty'    => is_numeric($qty) ? (float)$qty : 0.0,
                'reason' => (string)($row['top_reason'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Extrait les lignes produit d'une réponse — pur, vérifié sans réseau.
     *
     * La liste peut être nue ou sous `data`/`items`. Une entrée sans
     * identifiant est ignorée : on ne peut pas la prévoir ni la croiser.
     *
     * @param array<mixed> $data
     * @return array<int, array{id: int, name: string, sector: string, qty: float, lead_hours: ?float}>
     */
    public static function rowsOf(array $data): array
    {
        foreach (['data', 'items', 'products'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $data = $data[$k];
                break;
            }
        }
        if (!array_is_list($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['product_id'] ?? $row['id_product'] ?? $row['id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                continue;
            }
            $qty = $row['sold_qty'] ?? $row['sold_quantity'] ?? $row['quantity'] ?? 0;
            $lead = $row['preparation_lead_time_hours'] ?? null;

            $out[] = [
                'id'         => (int)$id,
                'name'       => (string)($row['product_name'] ?? $row['name'] ?? ('#' . $id)),
                // La structure de production réelle vient de `group_name`
                // (Boulangerie, Viennoiserie, Traiteur, Tartes…) : c'est le
                // champ que l'API peuple. `sector_name` reste souvent null en
                // prod — on l'accepte en repli, mais on n'attend pas après lui.
                // Un produit sans aucun des deux tombe sous « Autres ».
                'sector'     => (string)($row['group_name'] ?? $row['sector_name'] ?? $row['sector'] ?? ''),
                'qty'        => is_numeric($qty) ? (float)$qty : 0.0,
                'lead_hours' => is_numeric($lead) ? (float)$lead : null,
            ];
        }

        return $out;
    }
}
