<?php

namespace App\Kitchen\app\Repositories\Production;

use App\Kitchen\app\Models\Production\MepLineModel;
use App\Kitchen\app\Models\Production\OrderLineModel;
use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\core\Http\ApiClient;

/**
 * Accès au module Production.
 *
 * Aucun de ces endpoints n'existe encore — voir docs/ENDPOINTS_PRODUCTION.md.
 * Toutes les lectures renvoient null quand l'API ne répond pas, jamais un
 * tableau vide : l'écran doit pouvoir distinguer « rien à produire » de « pas
 * encore servi ». Un tableau vide affiché comme une journée sans MEP ferait
 * ouvrir le magasin sans rien en vitrine.
 */
class ProductionRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * Réglages du magasin : bornes de périodes et paramètres de prévision.
     *
     * @return array{periods: PeriodModel[], forecast_hours: float, history_weeks: int, safety_margin: float}|null
     */
    public function getConfig(int $shopId): ?array
    {
        $response = $this->apiClient->get("/shops/{$shopId}/production/config");
        if (!($response['success'] ?? false)) {
            return null;
        }

        $data = $response['data'] ?? [];
        $periods = [];
        foreach ($data['periods'] ?? [] as $row) {
            if (is_array($row) && !empty($row['key'])) {
                $periods[] = new PeriodModel($row);
            }
        }
        if ($periods === []) {
            return null;
        }

        return [
            'periods'        => $periods,
            'forecast_hours' => (float)($data['forecast_hours'] ?? PRODUCTION_FORECAST_HOURS),
            'history_weeks'  => (int)($data['history_weeks'] ?? PRODUCTION_HISTORY_WEEKS),
            'safety_margin'  => (float)($data['safety_margin'] ?? PRODUCTION_SAFETY_MARGIN),
        ];
    }

    /**
     * Catalogue de production : catégorie, périodes, taille de fournée.
     *
     * @return ProductionProductModel[]|null
     */
    public function getProducts(int $shopId): ?array
    {
        $response = $this->apiClient->get("/shops/{$shopId}/production/products");
        if (!($response['success'] ?? false)) {
            return null;
        }

        $rows = $response['data'] ?? [];
        $rows = $rows['items'] ?? $rows;

        $products = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $products[] = new ProductionProductModel($row);
            }
        }
        return $products;
    }

    /**
     * MEP préparée la veille pour la journée demandée.
     *
     * @return array{date: string, status: string, prepared_at: ?string, lines: MepLineModel[]}|null
     */
    public function getMep(int $shopId, string $date): ?array
    {
        $response = $this->apiClient->where("/shops/{$shopId}/mep", ['date' => $date]);
        if (!($response['success'] ?? false)) {
            return null;
        }

        $data  = $response['data'] ?? [];
        $rows  = $data['lines'] ?? (is_array($data) ? $data : []);

        $lines = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $lines[] = new MepLineModel($row);
            }
        }

        return [
            'date'        => $data['date'] ?? $date,
            'status'      => strtoupper((string)($data['status'] ?? MepLineModel::STATUS_PREPARED)),
            'prepared_at' => $data['prepared_at'] ?? null,
            'lines'       => $lines,
        ];
    }

    /**
     * Enregistre (ou remplace) la MEP préparée pour une date à venir.
     *
     * C'est l'encodage de l'après-midi : ce qu'on met en place aujourd'hui
     * pour demain. Les lignes portent `id_product`, pas `id` — elles n'existent
     * pas encore côté serveur.
     *
     * @param array<int, array{id_product: int, quantity: float}> $lines
     */
    public function saveMep(int $shopId, string $date, array $lines): array
    {
        return $this->apiClient->post("/shops/{$shopId}/mep", [
            'date'  => $date,
            'lines' => $lines,
        ]);
    }

    /**
     * Valide la MEP, ligne par ligne, avec la quantité réellement obtenue.
     *
     * @param array<int, array{id: int, quantity: float, skipped?: bool}> $lines
     */
    public function validateMep(int $shopId, string $date, array $lines): array
    {
        return $this->apiClient->post("/shops/{$shopId}/mep/validate", [
            'date'  => $date,
            'lines' => $lines,
        ]);
    }

    /**
     * Stock live, tel que consolidé par le back-office.
     *
     * @return StockLineModel[]|null
     */
    public function getStock(int $shopId): ?array
    {
        $response = $this->apiClient->get("/shops/{$shopId}/stock");
        if (!($response['success'] ?? false)) {
            return null;
        }

        $data = $response['data'] ?? [];
        $rows = $data['items'] ?? (is_array($data) ? $data : []);

        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $items[] = new StockLineModel($row);
            }
        }
        return $items;
    }

    /**
     * Les commandes fermes à honorer sur la journée — magasin, click &
     * collect, livraison.
     *
     * Une seule route pour les trois canaux : ce sont les mêmes produits, les
     * mêmes fournées et le même four. Le canal ne sert qu'à savoir à qui on
     * devra s'excuser, et il voyage sur la ligne.
     *
     * @return OrderLineModel[]|null null = commandes non servies. La distinction
     *         compte plus ici qu'ailleurs : un carnet vide affiché à la place
     *         d'un endpoint muet ferait produire pile la vitrine, et repartir
     *         les clients qui avaient commandé.
     */
    public function getOrders(int $shopId, string $date): ?array
    {
        $response = $this->apiClient->where("/shops/{$shopId}/orders", ['date' => $date]);
        if (!($response['success'] ?? false)) {
            return null;
        }

        $data = $response['data'] ?? [];
        $rows = $data['items'] ?? (is_array($data) ? $data : []);

        $lines = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $lines[] = new OrderLineModel($row);
            }
        }
        return $lines;
    }

    /** Profil de ventes agrégé — la matière première de la prévision. */
    public function getSalesProfile(
        int $shopId,
        string $date,
        int $weeks,
        bool $weekdayOnly,
        int $granularityMinutes
    ): ?SalesProfileModel {
        $response = $this->apiClient->where("/shops/{$shopId}/sales/profile", [
            'date'         => $date,
            'weeks'        => $weeks,
            'weekday_only' => $weekdayOnly ? 1 : 0,
            'granularity'  => $granularityMinutes,
        ]);

        if (!($response['success'] ?? false)) {
            return null;
        }
        return new SalesProfileModel($response['data'] ?? []);
    }

    /** Enregistre un lot produit — MEP validée ou recuisson. */
    public function createBatch(int $shopId, array $payload): array
    {
        return $this->apiClient->post("/shops/{$shopId}/production/batches", $payload);
    }

    /** Compteurs pour la pastille de l'onglet. */
    public function getPendingCount(int $shopId, string $date): ?array
    {
        $response = $this->apiClient->where("/shops/{$shopId}/production/pending-count", ['date' => $date]);
        if (!($response['success'] ?? false)) {
            return null;
        }
        return $response['data'] ?? null;
    }
}
