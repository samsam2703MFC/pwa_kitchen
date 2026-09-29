<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\MepLineModel;
use App\Kitchen\app\Models\Production\OrderLineModel;
use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Repositories\Production\ProductionRepository;
use App\Kitchen\core\Support\GlobalRegistry;

/**
 * Orchestration du module Production : périodes, MEP, stock, recuissons.
 *
 * Le calcul de proposition vit dans ForecastService, qui est pur. Ce
 * service-ci est celui qui parle au réseau et assemble.
 */
class ProductionService
{
    /** @var array{periods: PeriodModel[], forecast_hours: float, history_weeks: int, safety_margin: float}|null */
    private ?array $configCache = null;
    private bool $configLoaded = false;

    /**
     * Mémoire de la requête.
     *
     * Un même écran demande la MEP, le catalogue et le stock depuis plusieurs
     * endroits — le bandeau de flux, la vue de période, le tableau. Sans ça,
     * une page vaut trois fois le même appel réseau, sur une tablette de
     * magasin et une connexion de magasin.
     */
    private array $mepCache = [];
    private array $ordersCache = [];
    private array $profileCache = [];
    private bool $productsLoaded = false;
    private ?array $productsCache = null;
    private bool $stockLoaded = false;
    private ?array $stockCache = null;

    public function __construct(
        private ProductionRepository $productionRepository,
        private ForecastService $forecastService,
        private PosSalesProviderInterface $salesProvider,
        private SectorService $sectorService
    ) {}

    private function getShopId(): int
    {
        return (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
    }

    // ── Réglages ─────────────────────────────────────────────────────────

    /**
     * Réglages du magasin, avec repli sur les constantes de config/app.php.
     *
     * Contrairement aux données, un réglage absent n'appelle pas de message :
     * des bornes horaires par défaut restent utilisables, une MEP absente non.
     */
    private function config(): array
    {
        if (!$this->configLoaded) {
            $this->configLoaded = true;
            $shopId = $this->getShopId();
            $this->configCache = $shopId > 0 ? $this->productionRepository->getConfig($shopId) : null;
        }

        if ($this->configCache !== null) {
            return $this->configCache;
        }

        return [
            'periods'        => array_map(fn($p) => new PeriodModel($p), PRODUCTION_PERIODS),
            'forecast_hours' => (float)PRODUCTION_FORECAST_HOURS,
            'history_weeks'  => (int)PRODUCTION_HISTORY_WEEKS,
            'safety_margin'  => (float)PRODUCTION_SAFETY_MARGIN,
        ];
    }

    /** @return PeriodModel[] */
    public function getPeriods(): array
    {
        return $this->config()['periods'];
    }

    public function getParams(): array
    {
        $c = $this->config();
        return [
            'forecast_hours' => $c['forecast_hours'],
            'history_weeks'  => $c['history_weeks'],
            'safety_margin'  => $c['safety_margin'],
        ];
    }

    /**
     * Les périodes qu'il reste à faire — voir StockOutlookService::upcoming(),
     * où vit la règle, testable hors serveur.
     *
     * @return PeriodModel[]
     */
    public function upcomingPeriods(StockOutlookService $outlook, ?string $activeKey = null, ?string $time = null): array
    {
        return $outlook->upcoming(
            $this->getPeriods(),
            ForecastService::minutesOf($time ?? date('H:i')),
            $activeKey
        );
    }

    /** La période qui contient l'heure donnée, sinon la première. */
    public function currentPeriodKey(?string $time = null): string
    {
        $time    = $time ?? date('H:i');
        $periods = $this->getPeriods();

        foreach ($periods as $p) {
            if ($p->contains($time)) {
                return $p->getKey();
            }
        }
        // Avant l'ouverture ou après la fermeture : le matin est le point de
        // départ naturel de la journée d'atelier.
        return $periods[0]->getKey() ?? 'morning';
    }

    // ── Catalogue ────────────────────────────────────────────────────────

    /** @return ProductionProductModel[]|null null = catalogue non servi */
    public function getProducts(): ?array
    {
        if (!$this->productsLoaded) {
            $this->productsLoaded = true;
            $shopId = $this->getShopId();
            $this->productsCache = $shopId > 0 ? $this->productionRepository->getProducts($shopId) : null;
        }
        return $this->productsCache;
    }

    // ── Secteurs ─────────────────────────────────────────────────────────

    /**
     * Les secteurs déclarés au catalogue — boulangerie, traiteur, la suite.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     *         vide quand le magasin n'a qu'un atelier : le sélecteur ne
     *         s'affiche alors pas du tout.
     */
    public function sectors(): array
    {
        return $this->sectorService->sectors($this->getProducts() ?? []);
    }

    /** Le secteur retenu, ou null pour tout le catalogue. */
    public function resolveSector(?string $requested): ?string
    {
        return $this->sectorService->resolve($this->sectors(), $requested);
    }

    /**
     * Le catalogue du secteur.
     *
     * @return ProductionProductModel[]|null null = catalogue non servi ; le
     *         filtre ne transforme pas une absence en liste vide.
     */
    public function productsInSector(?string $sector): ?array
    {
        $products = $this->getProducts();
        return $products === null ? null : $this->sectorService->filter($products, $sector);
    }

    /** @return array<int, true> les id_product du secteur */
    public function sectorIds(?string $sector): array
    {
        return $this->sectorService->idsOf($this->getProducts() ?? [], $sector);
    }

    /**
     * Ne garde d'une liste que ce qui appartient au secteur — lignes de MEP,
     * fournées, stock, commandes, tout ce qui ne porte pas la fiche produit.
     *
     * @template T
     * @param  T[] $items
     * @param  callable(T): ?int $idOf
     * @param  array<int, true>  $ids  rendu par sectorIds()
     * @return T[]
     */
    public function filterToSector(array $items, callable $idOf, ?string $sector, array $ids): array
    {
        return $this->sectorService->keepIn($items, $idOf, $sector, $ids);
    }

    // ── Profil de ventes ─────────────────────────────────────────────────

    /**
     * Le profil de ventes du jour, une seule fois par requête.
     *
     * Trois calculs le demandent — la tension du tableau de travail, les
     * propositions de recuisson, la perspective de stock — et depuis que le
     * compteur des onglets se calcule sur toutes les vues, il est demandé même
     * quand on regarde l'atelier. Un agrégat sur six semaines n'est pas une
     * requête qu'on refait trois fois sur une connexion de magasin.
     */
    private function profile(?string $date = null): ?SalesProfileModel
    {
        $date = $date ?? date('Y-m-d');

        if (!array_key_exists($date, $this->profileCache)) {
            $shopId = $this->getShopId();
            $this->profileCache[$date] = $shopId > 0
                ? $this->salesProvider->getProfile($shopId, $date, (int)$this->getParams()['history_weeks'], true, 30)
                : null;
        }
        return $this->profileCache[$date];
    }

    // ── Commandes ────────────────────────────────────────────────────────

    /**
     * Les commandes fermes du jour — magasin, click & collect, livraison.
     *
     * @return OrderLineModel[]|null null = carnet non servi
     */
    public function getOrders(string $date): ?array
    {
        if (!array_key_exists($date, $this->ordersCache)) {
            $shopId = $this->getShopId();
            $this->ordersCache[$date] = $shopId > 0
                ? $this->productionRepository->getOrders($shopId, $date)
                : null;
        }
        return $this->ordersCache[$date];
    }

    /**
     * Les produits d'une période, regroupés par catégorie et triés par nom.
     *
     * @param ProductionProductModel[] $products
     * @return array<string, ProductionProductModel[]>
     */
    public function groupByCategory(array $products, string $periodKey): array
    {
        $groups = [];
        foreach ($products as $p) {
            if (!$p->isActive() || !$p->belongsTo($periodKey)) {
                continue;
            }
            $groups[$p->getCategoryName() ?? '—'][] = $p;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$g) {
            usort($g, fn($a, $b) => strcasecmp((string)$a->getName(), (string)$b->getName()));
        }
        unset($g);

        return $groups;
    }

    // ── MEP ──────────────────────────────────────────────────────────────

    /** @return array{date: string, status: string, prepared_at: ?string, lines: MepLineModel[]}|null */
    public function getMep(string $date): ?array
    {
        if (!array_key_exists($date, $this->mepCache)) {
            $shopId = $this->getShopId();
            $this->mepCache[$date] = $shopId > 0 ? $this->productionRepository->getMep($shopId, $date) : null;
        }
        return $this->mepCache[$date];
    }

    /**
     * Lignes de MEP d'une période. Les lignes sans période déclarée sont
     * montrées partout plutôt que nulle part : mieux vaut une ligne vue deux
     * fois qu'une ligne oubliée à la cuisson.
     *
     * @param MepLineModel[] $lines
     * @return MepLineModel[]
     */
    public function mepLinesForPeriod(array $lines, string $periodKey): array
    {
        return array_values(array_filter(
            $lines,
            fn(MepLineModel $l) => $l->getPeriod() === null || $l->getPeriod() === strtolower($periodKey)
        ));
    }

    /**
     * Enregistre la MEP d'une date à venir — l'encodage de l'après-midi.
     *
     * @param array<int, array{id_product: int, quantity: float}> $lines
     */
    public function saveMep(string $date, array $lines): array
    {
        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return ['success' => false, 'description' => 'shop_unknown'];
        }
        return $this->productionRepository->saveMep($shopId, $date, $lines);
    }

    /**
     * Tout le catalogue actif, groupé par catégorie.
     *
     * Sert à l'encodage de la MEP de demain : on y saisit ce qu'on prépare,
     * sans filtre de période — c'est le serveur qui rattachera chaque ligne à
     * sa période depuis la fiche produit.
     *
     * @param ProductionProductModel[] $products
     * @return array<string, ProductionProductModel[]>
     */
    public function groupAllByCategory(array $products): array
    {
        $groups = [];
        foreach ($products as $p) {
            if ($p->isActive()) {
                $groups[$p->getCategoryName() ?? '—'][] = $p;
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$g) {
            usort($g, fn($a, $b) => strcasecmp((string)$a->getName(), (string)$b->getName()));
        }
        unset($g);

        return $groups;
    }

    /**
     * Lignes de MEP groupées par catégorie, chaque groupe trié par nom.
     *
     * Une MEP arrive dans l'ordre où elle a été saisie la veille ; on la lit
     * dans l'ordre du magasin — viennoiserie ensemble, boulangerie ensemble.
     *
     * @param MepLineModel[] $lines
     * @return array<string, MepLineModel[]>
     */
    public function mepLinesByCategory(array $lines): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l->getCategoryName() ?: '—'][] = $l;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$g) {
            usort($g, fn($a, $b) => strcasecmp((string)$a->getName(), (string)$b->getName()));
        }
        unset($g);
        return $groups;
    }

    /**
     * Les produits qui se préparent la veille, groupés par catégorie.
     *
     * Ce sont les seuls candidats de la MEP du lendemain : proposer les deux
     * cents références du catalogue pour en retenir dix ferait chercher au
     * lieu de choisir.
     *
     * @param ProductionProductModel[] $products
     * @return array<string, ProductionProductModel[]>
     */
    public function pdbProductsByCategory(array $products): array
    {
        return $this->groupAllByCategory(array_values(array_filter(
            $products,
            fn(ProductionProductModel $p) => $p->isPdb()
        )));
    }

    /** @param array<int, array{id: int, quantity: float, skipped?: bool}> $lines */
    public function validateMep(string $date, array $lines): array
    {
        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return ['success' => false, 'description' => 'shop_unknown'];
        }
        return $this->productionRepository->validateMep($shopId, $date, $lines);
    }

    // ── Stock ────────────────────────────────────────────────────────────

    /**
     * Stock live, trié du plus bas au plus haut : les produits en tension
     * d'abord, c'est l'ordre dans lequel on veut le lire en service.
     *
     * @return StockLineModel[]|null
     */
    public function getStock(): ?array
    {
        if ($this->stockLoaded) {
            return $this->stockCache;
        }
        $this->stockLoaded = true;

        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return $this->stockCache = null;
        }

        $stock = $this->productionRepository->getStock($shopId);
        if ($stock === null) {
            return $this->stockCache = null;
        }

        usort($stock, function (StockLineModel $a, StockLineModel $b) {
            $c = $a->getQuantity() <=> $b->getQuantity();
            return $c !== 0 ? $c : strcasecmp((string)$a->getName(), (string)$b->getName());
        });

        return $this->stockCache = $stock;
    }

    // ── Recuissons ───────────────────────────────────────────────────────

    /**
     * Propositions de recuisson pour l'instant présent.
     *
     * @param ProductionProductModel[]|null $products
     * @param StockLineModel[]|null         $stock
     * @param array<int, float>             $orders  commandes dues sur la fenêtre
     *
     * @return array{available: bool, samples: ?int, suggestions: array}
     *         available = false quand le profil de ventes n'est pas servi ;
     *         l'écran l'écrit au lieu de laisser croire qu'il n'y a rien à
     *         recuire.
     */
    public function getRebakeSuggestions(?array $products, ?array $stock, ?string $date = null, ?string $time = null, array $orders = []): array
    {
        if ($products === null || $stock === null) {
            return ['available' => false, 'samples' => null, 'suggestions' => []];
        }

        $params  = $this->getParams();
        $profile = $this->profile($date);

        if ($profile === null) {
            return ['available' => false, 'samples' => null, 'suggestions' => []];
        }

        $nowMinutes = ForecastService::minutesOf($time ?? date('H:i'));

        return [
            'available'   => true,
            'samples'     => $profile->getSamples(),
            'suggestions' => $this->forecastService->suggest($products, $stock, $profile, $nowMinutes, $params, $orders),
        ];
    }

    /**
     * Stock et ventes prévues par produit, pour classer une liste de travail.
     *
     * @param ProductionProductModel[] $products
     * @param StockLineModel[]|null    $stock
     * @param array<int, float>        $orders  commandes dues sur la fenêtre
     *
     * @return array{available: bool, map: array<int, array{stock: float, expected: ?float, ordered: float, projected: ?float}>}
     *         available = false quand le profil de ventes n'est pas servi : la
     *         liste se classe alors sur le stock seul, et l'écran l'écrit.
     */
    public function tension(array $products, ?array $stock, ?string $date = null, ?string $time = null, array $orders = []): array
    {
        $params  = $this->getParams();
        $profile = $this->profile($date);

        return [
            'available' => $profile !== null,
            'map'       => $this->forecastService->tension(
                $products,
                $stock ?? [],
                $profile,
                ForecastService::minutesOf($time ?? date('H:i')),
                $params,
                $orders
            ),
        ];
    }

    /**
     * Le stock lu vers l'avant, pour le tableau de l'écran Stock.
     *
     * @param StockLineModel[]|null         $stock
     * @param ProductionProductModel[]|null $products
     * @param array<int, float>             $orders1  commandes dues sur P1
     * @param array<int, float>             $orders2  commandes dues sur P2
     *
     * @return array{available: bool, rows: array, counts: array, horizons: array}
     */
    public function stockOutlook(
        ?array $stock,
        ?array $products,
        StockOutlookService $outlook,
        ?string $date = null,
        ?string $time = null,
        array $orders1 = [],
        array $orders2 = []
    ): array {
        $now       = ForecastService::minutesOf($time ?? date('H:i'));
        $horizons  = $outlook->horizons($this->getPeriods(), $now);

        $params  = $this->getParams();
        $profile = $this->profile($date);

        $rows = $outlook->rows($stock ?? [], $products ?? [], $profile, $horizons, $orders1, $orders2);

        return [
            'available' => $profile !== null,
            'rows'      => $rows,
            'counts'     => $outlook->counts($rows),
            'categories' => $outlook->categories($rows),
            'horizons'   => $horizons,
        ];
    }

    // ── Mise en rayon ────────────────────────────────────────────────────

    /**
     * Porte en rayon ce qui a été produit : c'est CE geste qui met en vente.
     *
     * La validation de MEP constate ce qui est sorti du four ; elle ne décide
     * pas de ce que la caisse peut vendre. Entre les deux il y a un plateau à
     * porter, et parfois une demi-fournée qui reste au chaud en réserve.
     * Voir docs/ENDPOINTS_PRODUCTION.md.
     */
    public function shelve(int $idProduct, float $quantity, ?int $idMepLine = null, ?int $employeeId = null): array
    {
        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return ['success' => false, 'description' => 'shop_unknown'];
        }

        $payload = [
            'id_product' => $idProduct,
            'quantity'   => $quantity,
            'source'     => 'SHELF',
        ];
        // Rattacher la ligne permet au serveur de décompter le reste à porter
        // plutôt que de recouper sur le produit et la date.
        if ($idMepLine !== null) {
            $payload['id_mep_line'] = $idMepLine;
        }
        if ($employeeId !== null) {
            $payload['id_employee'] = $employeeId;
        }

        return $this->productionRepository->createBatch($shopId, $payload);
    }

    /** Valide une recuisson : le lot entre en production, donc en stock. */
    public function createRebake(int $idProduct, float $quantity, ?int $employeeId = null): array
    {
        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return ['success' => false, 'description' => 'shop_unknown'];
        }

        $payload = [
            'id_product' => $idProduct,
            'quantity'   => $quantity,
            'source'     => 'REBAKE',
        ];
        if ($employeeId !== null) {
            $payload['id_employee'] = $employeeId;
        }

        return $this->productionRepository->createBatch($shopId, $payload);
    }
}
