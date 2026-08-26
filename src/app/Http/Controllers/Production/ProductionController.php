<?php

namespace App\Kitchen\app\Http\Controllers\Production;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Models\Production\MepLineModel;
use App\Kitchen\app\Models\Production\OrderLineModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\app\Services\Baking\BakingService;
use App\Kitchen\app\Services\Production\ForecastService;
use App\Kitchen\app\Services\Production\MinimumService;
use App\Kitchen\app\Services\Production\OrderBookService;
use App\Kitchen\app\Services\Production\ProductionBoardService;
use App\Kitchen\app\Services\Production\ProductionService;
use App\Kitchen\app\Services\Production\StockOutlookService;
use App\Kitchen\app\Services\Knowledge\Preparation\PreparationPathService;
use App\Kitchen\app\Services\Staff\StaffService;

class ProductionController extends Controller
{
    public function __construct(
        private ProductionService $productionService,
        private ProductionBoardService $boardService,
        // L'étape d'un produit vient du plan de cuisson, pas d'un second champ
        // qui dériverait : une seule source de vérité pour « où en est ce
        // produit », partagée avec le module Cuisson.
        private BakingService $bakingService,
        private StockOutlookService $outlookService,
        private OrderBookService $orderBook,
        private MinimumService $minimumService,
        private StaffService $staffService,
        private PreparationPathService $preparationPathService
    ) {}

    /**
     * GET /production[?view=mep|morning|noon|afternoon|stock|minimums|planning]
     *                [&mep=morning|afternoon][&sector=<clé>]
     *
     * L'écran travaille toujours pour aujourd'hui : pas de sélecteur de date.
     * Une cuisine ne produit pas pour hier, et un écran qui peut afficher une
     * autre journée finit par en afficher une par erreur au milieu du service.
     * Seul l'encodage de la MEP regarde demain, et il le dit.
     *
     * Le secteur, lui, se choisit : boulangerie et traiteur sont deux ateliers,
     * deux équipes, souvent deux pièces — mais un seul magasin et exactement
     * les mêmes questions. Un filtre en tête, donc, et pas un second module :
     * le boulanger qui passe au traiteur retrouve ses gestes.
     */
    public function index(): void
    {
        $today   = date('Y-m-d');
        $periods = $this->productionService->getPeriods();
        $view    = $this->readView($periods);

        $sectors = $this->productionService->sectors();
        $sector  = $this->productionService->resolveSector(
            isset($_GET['sector']) ? (string)$_GET['sector'] : null
        );

        $data = [
            // Le sélecteur ne montre que ce qui reste à faire ; `periods` garde
            // la journée entière pour les calculs d'horizon.
            'periods'     => $periods,
            'tabs'        => $this->productionService->upcomingPeriods($this->outlookService, $view),
            'active_view' => $view,
            'today'       => $today,
            'params'      => $this->productionService->getParams(),
            'sectors'     => $sectors,
            'sector'      => $sector,
            // Les vues construisent leurs liens : le secteur doit survivre à
            // chaque changement d'onglet, sinon on le reperd à chaque geste.
            'sector_qs'   => $sector !== null ? '&sector=' . rawurlencode($sector) : '',
        ];

        // La bande de flux dit dans quel ordre les choses arrivent, et compte
        // ce qui attend à chaque étape. Elle est sur tous les écrans : c'est
        // elle qui empêche de se perdre, pas le panneau d'ordres — lequel ne
        // concerne que l'atelier et reste donc dans le planning.
        $data['flow'] = $this->flowData($today, $sector);

        // Le compteur de l'onglet « Ce qui manque ». Il vit sur les deux
        // onglets, donc il se calcule aussi quand on regarde l'atelier — c'est
        // pour ça que le profil de ventes est mémorisé le temps de la requête.
        $data['tab_needs'] = $this->needsCount($today, $sector);

        if ($view === 'planning') {
            $data += $this->planningData($today, $sector);
        } elseif ($view === 'stock') {
            $data += $this->stockData($today, $sector);
        } elseif ($view === 'minimums') {
            $data += $this->minimumsData($today, $sector);
        } elseif ($view === 'mep') {
            $data += $this->mepData($today, $sector);
        } else {
            $data += $this->periodData($today, $view, $sector);
            // La route des parcours manque : la coque la nomme en bandeau,
            // comme toute route manquante. L'écran, lui, reste entier.
            if (($data['prep']['missing'] ?? null) !== null) {
                $data['missing_api'] = array_merge(
                    (array)($data['missing_api'] ?? []),
                    [$data['prep']['missing']]
                );
            }
        }

        $this->view('production/index', $data);
    }

    /**
     * Les commandes fermes du jour, ramenées au secteur ouvert.
     *
     * @return array{available: bool, lines: OrderLineModel[]}
     *         available = false quand le carnet n'est pas servi. La distinction
     *         compte : un carnet vide affiché à la place d'un endpoint muet
     *         ferait produire pile la vitrine, et repartir les clients qui
     *         avaient commandé.
     */
    private function orders(string $today, ?string $sector): array
    {
        $lines = $this->productionService->getOrders($today);
        if ($lines === null) {
            return ['available' => false, 'lines' => []];
        }

        $ids = $this->productionService->sectorIds($sector);

        return [
            'available' => true,
            'lines'     => $this->productionService->filterToSector(
                $lines,
                fn(OrderLineModel $o) => $o->getIdProduct(),
                $sector,
                $ids
            ),
        ];
    }

    /**
     * Les quatre compteurs de la bande de flux.
     *
     * Tout vient d'appels déjà faits ailleurs sur la page — la MEP, le plan et
     * le stock sont mémorisés le temps de la requête. Une bande d'orientation
     * ne doit rien coûter, sinon elle finit par sauter au premier arbitrage de
     * performance.
     *
     * @return array{to_validate: ?int, in_progress: ?int, to_shelf: ?int, on_sale: ?int}
     *         null = source non servie ; l'étape n'affiche alors aucun nombre
     *         plutôt qu'un zéro qui voudrait dire « rien à faire ».
     */
    private function flowData(string $today, ?string $sector = null): array
    {
        $mep   = $this->productionService->getMep($today);
        $plan  = $this->bakingService->getPlan($today);
        $stock = $this->productionService->getStock();

        // Les compteurs suivent le secteur ouvert : afficher « 14 à valider »
        // sur un écran qui n'en montre que trois ferait chercher les onze
        // autres jusqu'à comprendre qu'ils sont chez le voisin.
        $ids   = $this->productionService->sectorIds($sector);
        $keep  = fn(array $rows, callable $idOf) =>
            $this->productionService->filterToSector($rows, $idOf, $sector, $ids);

        $lines = $keep($mep['lines'] ?? [], fn(MepLineModel $l) => $l->getIdProduct());
        $stock = $stock === null ? null : $keep($stock, fn($s) => $s->getIdProduct());
        $batches = $plan === null ? [] : $keep(
            $this->bakingService->active($plan['batches']),
            fn(BakingBatchModel $b) => $b->getIdProduct()
        );

        return [
            'to_validate' => $mep === null ? null
                : count(array_filter($lines, fn(MepLineModel $l) => $l->isPending())),
            'in_progress' => $plan === null ? null : count($batches),
            // Produit, constaté, mais pas encore porté : c'est exactement ce
            // que l'étape 3 attend de nous.
            'to_shelf'    => $mep === null ? null
                : count(array_filter($lines, fn(MepLineModel $l) => $l->getRemainingToShelf() > 0)),
            'on_sale'     => $stock === null ? null
                : count(array_filter($stock, fn($s) => $s->getQuantity() > 0)),
        ];
    }

    /**
     * Combien de produits attendent un geste — le compteur de l'onglet.
     *
     * Un produit compte une fois, qu'il manque ET soit à porter en rayon :
     * c'est un produit dont on va s'occuper, pas deux. Le compteur porte sur
     * les périodes qui restent à faire, pas sur celle qui est ouverte — un
     * badge d'onglet décrit l'onglet, pas la pastille qu'on a tapée dedans,
     * sinon il change sous le doigt sans que rien n'ait bougé.
     *
     * @return int|null null = catalogue non servi ; l'onglet n'affiche alors
     *         aucun nombre plutôt qu'un zéro qui voudrait dire « rien à faire ».
     */
    private function needsCount(string $today, ?string $sector): ?int
    {
        $products = $this->productionService->productsInSector($sector);
        if ($products === null) {
            return null;
        }

        $keys = array_map(
            fn($p) => $p->getKey(),
            $this->productionService->upcomingPeriods($this->outlookService)
        );

        $shown = array_values(array_filter(
            $products,
            fn($p) => $p->isActive() && array_intersect($keys, $p->getPeriods()) !== []
        ));

        $orders  = $this->orders($today, $sector);
        $due     = $this->orderBook->dueForProducts(
            $orders['lines'],
            $shown,
            ForecastService::minutesOf(date('H:i')),
            (int)round((float)$this->productionService->getParams()['forecast_hours'] * 60)
        );
        $tension = $this->productionService->tension(
            $shown,
            $this->productionService->getStock(),
            $today,
            null,
            $due
        );

        $ids = [];
        foreach ($shown as $p) {
            $t = $tension['map'][$p->getIdProduct()] ?? null;
            if ($t !== null && $t['projected'] !== null && $t['projected'] < 0) {
                $ids[$p->getIdProduct()] = true;
            }
        }

        // Ce qui est produit mais pas encore porté attend un geste au même
        // titre que ce qui manque : c'est même le plus rapide à rendre.
        $mep = $this->productionService->getMep($today);
        if ($mep !== null) {
            $sectorIds = $this->productionService->sectorIds($sector);
            $lines     = $this->productionService->filterToSector(
                $mep['lines'],
                fn(MepLineModel $l) => $l->getIdProduct(),
                $sector,
                $sectorIds
            );
            foreach ($lines as $l) {
                if ($l->getRemainingToShelf() > 0 && $l->getIdProduct() !== null) {
                    $ids[$l->getIdProduct()] = true;
                }
            }
        }

        return count($ids);
    }

    /**
     * Le planning de l'atelier : frise, ordres, cartes.
     *
     * Le panneau d'ordres vit ici et nulle part ailleurs : c'est le fil de
     * l'atelier, pas celui du magasin. Sur la MEP ou le stock, il proposerait
     * un geste qui n'a rien à voir avec l'écran ouvert.
     */
    private function planningData(string $today, ?string $sector = null): array
    {
        $plan = $this->bakingService->getPlan($today);

        if ($plan === null) {
            return [
                'plan_served'    => false,
                'orders'         => [],
                'plan'           => [],
                'batches'        => [],
                'ovens'          => [],
                'counts'         => ['all' => 0, 'prep' => 0, 'cook' => 0, 'finish' => 0],
                'staff'          => [],
                'staff_available'=> false,
                'now'            => $this->bakingService->nowMinutes(),
                'now_clock'      => date('H:i'),
                'window'         => ['from' => 0, 'to' => 60, 'hours' => []],
                'active_stage'   => 'all',
                'focus_batch'    => null,
            ];
        }

        $stage   = $this->readStage();
        $now     = $this->bakingService->nowMinutes($plan['server_time']);

        // Le secteur filtre aussi l'atelier : le traiteur n'a pas à surveiller
        // les fours de la boulangerie, et une frise qui mélange les deux se lit
        // deux fois plus longtemps pour moitié moins d'information.
        $ids     = $this->productionService->sectorIds($sector);
        $mine    = $this->productionService->filterToSector(
            $plan['batches'],
            fn(BakingBatchModel $b) => $b->getIdProduct(),
            $sector,
            $ids
        );

        $active  = $this->bakingService->active($mine);
        $shown   = $this->bakingService->filterByStage($active, $stage);
        $dayPlan = $this->bakingService->dayPlan($mine, $stage);
        // La cuisson est toujours celle d'aujourd'hui : le planning demandé est
        // donc celui du jour.
        $staff   = $this->staffService->getEmployees(date('Y-m-d'));

        return [
            'plan_served'    => true,
            'orders'         => $this->bakingService->orders($shown),
            'plan'           => $dayPlan,
            'batches'        => $shown,
            'ovens'          => $this->bakingService->ovens($dayPlan),
            'counts'         => $this->bakingService->countByStage($active),
            'staff'          => $this->staffService->onDuty($staff),
            'staff_available'=> $staff !== null,
            // La route manquante, s'il y en a une : la coque la nomme en haut
            // d'ecran. Plus de repli — voir StaffService.
            'missing_api'    => $this->staffService->missingApi(),
            'now'            => $now,
            'now_clock'      => BakingBatchModel::toClock($now),
            'window'         => $this->bakingService->window($dayPlan ?: $active, $now, true),
            'active_stage'   => $stage,
            'focus_batch'    => ($f = (int)($_GET['focus'] ?? 0)) > 0 ? $f : null,
        ];
    }

    private function readStage(): string
    {
        $stage = (string)($_GET['stage'] ?? '');
        return in_array($stage, BakingService::STAGES, true) ? $stage : 'all';
    }

    /**
     * Une période de la journée : le tableau de travail.
     *
     * Quatre sources se rejoignent ici, et aucune n'est facultative pour la
     * même raison : le catalogue dit quoi, la MEP dit ce qui est produit, le
     * plan de cuisson dit où ça en est, le stock et les ventes disent dans
     * quel ordre s'en occuper. Chacune peut manquer sans casser l'écran —
     * elle manque alors *visiblement*.
     */
    private function periodData(string $today, string $view, ?string $sector = null): array
    {
        $products = $this->productionService->productsInSector($sector);
        $mep      = $this->productionService->getMep($today);
        $ids      = $this->productionService->sectorIds($sector);

        $lines = $mep !== null
            ? $this->productionService->filterToSector(
                $this->productionService->mepLinesForPeriod($mep['lines'], $view),
                fn(MepLineModel $l) => $l->getIdProduct(),
                $sector,
                $ids
            )
            : [];

        $orders = $this->orders($today, $sector);

        if ($products === null) {
            return [
                'products_available' => false,
                'mep_available'      => $mep !== null,
                'mep_lines'          => $lines,
                'mep_pending_count'  => count(array_filter($lines, fn(MepLineModel $l) => $l->isPending())),
                'orders_available'   => $orders['available'],
                'orders'             => [],
                'orders_counts'      => $this->orderBook->counts([]),
                'board'              => null,
            ] + $this->moreCounts($today, $sector, null, $this->productionService->getStock(), []);
        }

        $periodProducts = array_values(array_filter(
            $products,
            fn($p) => $p->isActive() && $p->belongsTo($view)
        ));

        // Le plan de cuisson peut ne pas être servi : les tuiles tombent alors
        // toutes dans « rien en cours », et l'écran le dit plutôt que de
        // laisser croire qu'aucune fournée n'est au four.
        $plan   = $this->bakingService->getPlan($today);
        $stages = $plan !== null ? $this->boardService->stagesByProduct($plan['batches']) : [];

        $stock   = $this->productionService->getStock();

        // Les commandes fermes entrent dans le manque, sur la même fenêtre que
        // les ventes prévues : produire pour la vitrine et découvrir ensuite
        // qu'un plateau de quarante pièces était promis pour midi, c'est deux
        // fournées trop tard.
        $due     = $this->orderBook->dueForProducts(
            $orders['lines'],
            $periodProducts,
            ForecastService::minutesOf(date('H:i')),
            (int)round((float)$this->productionService->getParams()['forecast_hours'] * 60)
        );

        $tension = $this->productionService->tension($periodProducts, $stock, $today, null, $due);

        return [
            'products_available' => true,
            'mep_available'      => $mep !== null,
            'mep_lines'          => $lines,
            'mep_pending_count'  => count(array_filter($lines, fn(MepLineModel $l) => $l->isPending())),
            'plan_available'     => $plan !== null,
            'stock_available'    => $stock !== null,
            'forecast_available' => $tension['available'],
            'orders_available'   => $orders['available'],
            'orders'             => $this->orderBook->upcoming(
                $orders['lines'],
                ForecastService::minutesOf(date('H:i'))
            ),
            'orders_counts'      => $this->orderBook->counts($orders['lines']),
            'now_minutes'        => ForecastService::minutesOf(date('H:i')),
            'board'              => $board = $this->boardService->build($periodProducts, $lines, $stages, $tension['map']),
            // ── Le parcours de préparation, en résumé ──
            // Un appel pour la liste des produits configurés, puis un par
            // produit configuré PRÉSENT à l'écran — jamais un par produit
            // affiché : summaries() croise avant d'appeler. Si la route ne
            // répond pas, prep.missing la nomme et le bandeau l'affiche ;
            // l'écran, lui, reste entier.
            'prep'               => $this->safeFetch(
                fn() => $this->preparationPathService->summaries(array_values(array_unique(array_merge([], ...array_map(
                    fn(array $rows) => array_map(
                        fn(array $r) => (int)$r['product']->getIdProduct(),
                        array_values($rows)
                    ),
                    array_values($board['buckets'] ?? [])
                ))))),
                $this->warnings,
                null,
                ['available' => false, 'missing' => null, 'map' => []]
            ),
        ] + $this->moreCounts($today, $sector, $products, $stock, $orders['lines']);
    }

    /**
     * Les trois lignes du pied de page : stock, minimums, MEP de demain.
     *
     * Chacune porte son compte parce que c'est souvent la seule chose qu'on
     * venait y chercher — savoir combien de produits sont sous leur plancher
     * n'exige pas d'ouvrir le tableau qui les liste.
     *
     * @param ProductionProductModel[]|null $products
     * @param StockLineModel[]|null         $stock
     * @param OrderLineModel[]              $orders
     *
     * @return array{stock_ref_count: ?int, min_below_count: ?int, mep_next_count: ?int}
     */
    private function moreCounts(string $today, ?string $sector, ?array $products, ?array $stock, array $orders): array
    {
        $min = null;
        if ($products !== null && $stock !== null) {
            $focus  = $this->productionService->currentPeriodKey();
            $counts = $this->minimumService->counts(
                $this->minimumService->rows($products, $stock, [], $this->productionService->getPeriods(), $focus)
            );
            $min = $counts[MinimumService::SHORT];
        }

        // La MEP de demain : ce qui est déjà encodé. Zéro est une information
        // — « personne n'a encore rien noté pour demain » — donc on l'écrit,
        // contrairement à une source non servie.
        $draft = $this->productionService->getMep(date('Y-m-d', strtotime($today . ' +1 day')));
        $next  = null;
        if ($draft !== null) {
            $next = count($this->productionService->filterToSector(
                $draft['lines'],
                fn(MepLineModel $l) => $l->getIdProduct(),
                $sector,
                $this->productionService->sectorIds($sector)
            ));
        }

        $mine = $stock === null ? null : $this->productionService->filterToSector(
            $stock,
            fn(StockLineModel $l) => $l->getIdProduct(),
            $sector,
            $this->productionService->sectorIds($sector)
        );

        return [
            'stock_ref_count' => $mine === null ? null : count($mine),
            'min_below_count' => $min,
            'mep_next_count'  => $next,
        ];
    }

    /**
     * L'écran Stock : ce qu'il reste, face à ce qui va partir.
     */
    private function stockData(string $today, ?string $sector): array
    {
        $products = $this->productionService->productsInSector($sector);
        $ids      = $this->productionService->sectorIds($sector);

        $stock = $this->productionService->getStock();
        $stock = $stock === null ? null : $this->productionService->filterToSector(
            $stock,
            fn($s) => $s->getIdProduct(),
            $sector,
            $ids
        );

        $orders   = $this->orders($today, $sector);
        $now      = ForecastService::minutesOf(date('H:i'));
        $horizons = $this->outlookService->horizons($this->productionService->getPeriods(), $now);

        // P1 part de zéro, pas de maintenant : une commande de 9 h 30 lue à
        // 10 h 40 n'a pas disparu du stock — soit elle a été remise et le stock
        // le montre déjà, soit elle attend toujours son client, et il faut la
        // réserver. L'ignorer ferait vendre deux fois le même plateau.
        $o1 = $this->orderBook->dueBetween($orders['lines'], 0, $horizons['p1']['to']);
        $o2 = $this->orderBook->dueBetween($orders['lines'], $horizons['p2']['from'], $horizons['p2']['to']);

        $rebakes = $this->productionService->getRebakeSuggestions(
            $products,
            $stock,
            $today,
            null,
            $this->orderBook->dueForProducts(
                $orders['lines'],
                $products ?? [],
                $now,
                (int)round((float)$this->productionService->getParams()['forecast_hours'] * 60)
            )
        );

        // Le stock lu vers l'avant : ce n'est pas « combien il en reste » qui se
        // décide, c'est « est-ce que ça tient jusqu'au bout ».
        $outlook = $this->productionService->stockOutlook(
            $stock,
            $products,
            $this->outlookService,
            $today,
            null,
            $o1,
            $o2
        );

        return [
            'stock_available'   => $stock !== null,
            'stock'             => $stock ?? [],
            'rebakes'           => $rebakes['suggestions'],
            'rebakes_available' => $rebakes['available'],
            'rebakes_samples'   => $rebakes['samples'],
            'outlook'           => $outlook['rows'],
            'outlook_available' => $outlook['available'],
            'outlook_counts'    => $outlook['counts'],
            'outlook_categories'=> $outlook['categories'],
            'horizons'          => $outlook['horizons'],
            'orders_available'  => $orders['available'],
        ];
    }

    /**
     * L'écran Minimums : le plancher de vitrine, période par période.
     *
     * Une autre question que celle du stock, et c'est pourquoi c'est un autre
     * tableau. « Est-ce que ça tient » se répond avec les ventes prévues ;
     * « est-ce que la vitrine est présentable » se répond avec un plancher, qui
     * ne se discute pas avec une moyenne. Les deux vivent côte à côte sous le
     * même onglet, parce qu'on les consulte dans la même minute.
     */
    private function minimumsData(string $today, ?string $sector): array
    {
        $products = $this->productionService->productsInSector($sector);
        $ids      = $this->productionService->sectorIds($sector);
        $periods  = $this->productionService->getPeriods();
        $now      = ForecastService::minutesOf(date('H:i'));

        $stock = $this->productionService->getStock();
        $stock = $stock === null ? null : $this->productionService->filterToSector(
            $stock,
            fn($s) => $s->getIdProduct(),
            $sector,
            $ids
        );

        $orders = $this->orders($today, $sector);

        // La couleur se décide sur la période en cours : c'est la vitrine de
        // maintenant qu'on regarde en passant devant.
        $focus = $this->productionService->currentPeriodKey();
        $bounds = null;
        foreach ($periods as $p) {
            if ($p->getKey() === $focus) {
                $bounds = [ForecastService::minutesOf($p->getStart()), ForecastService::minutesOf($p->getEnd())];
            }
        }

        $due = $bounds === null
            ? []
            : $this->orderBook->dueInPeriod($orders['lines'], $focus, $bounds[0], $bounds[1]);

        $rows = $products === null
            ? []
            : $this->minimumService->rows($products, $stock, $due, $periods, $focus);

        return [
            'products_available' => $products !== null,
            'stock_available'    => $stock !== null,
            'orders_available'   => $orders['available'],
            'min_rows'           => $rows,
            'min_counts'         => $this->minimumService->counts($rows),
            'min_categories'     => $this->minimumService->categories($rows),
            'min_focus'          => $focus,
            'now_minutes'        => $now,
        ];
    }

    /**
     * Les deux temps de la MEP.
     *
     * Le matin, on valide ce qui a été préparé hier : c'est cette validation
     * qui ouvre la vente. L'après-midi, on encode ce qu'on prépare pour
     * demain. Deux gestes opposés — l'un ferme une journée, l'autre en ouvre
     * une — d'où deux écrans plutôt qu'un formulaire à double usage.
     */
    private function mepData(string $today, ?string $sector = null): array
    {
        $sub = ($_GET['mep'] ?? '') === 'afternoon' ? 'afternoon' : 'morning';
        $ids = $this->productionService->sectorIds($sector);

        if ($sub === 'morning') {
            $mep     = $this->productionService->getMep($today);
            $lines   = $this->productionService->filterToSector(
                $mep['lines'] ?? [],
                fn(MepLineModel $l) => $l->getIdProduct(),
                $sector,
                $ids
            );
            $pending = array_values(array_filter($lines, fn(MepLineModel $l) => $l->isPending()));

            return [
                'mep_sub'            => 'morning',
                'mep_available'      => $mep !== null,
                'mep_status'         => $mep['status'] ?? null,
                'mep_prepared_at'    => $mep['prepared_at'] ?? null,
                'mep_lines'          => $lines,
                'mep_pending'        => $pending,
                // Groupées par catégorie : une MEP arrive dans l'ordre de
                // saisie de la veille, on la relit dans l'ordre du magasin.
                'mep_by_category'    => $this->productionService->mepLinesByCategory($lines),
                'mep_pending_by_cat' => $this->productionService->mepLinesByCategory($pending),
                // La ligne de MEP ne porte pas la taille de fournée : elle vient
                // du catalogue, et c'est elle qui fait le pas des boutons.
                'mep_batch'          => $this->batchSizes(),
            ];
        }

        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $products = $this->productionService->productsInSector($sector);
        // Un brouillon peut déjà exister : on reprend l'encodage là où il en
        // était plutôt que de repartir de zéro à chaque ouverture.
        $draft    = $this->productionService->getMep($tomorrow);

        // Le sélecteur ne propose que ce qui se prépare la veille : sur deux
        // cents références, en proposer dix fait choisir au lieu de chercher.
        $pdb = $products !== null
            ? $this->productionService->pdbProductsByCategory($products)
            : [];

        $byId = [];
        foreach ($products ?? [] as $p) {
            if ($p->getIdProduct() !== null) {
                $byId[$p->getIdProduct()] = $p;
            }
        }

        // Les lignes déjà encodées, enrichies de la fiche produit : c'est elle
        // qui porte le pas de fournée (12 pour un croissant, 15 pour une
        // baguette), et la ligne de MEP ne le transporte pas.
        $rows = [];
        $draftLines = $this->productionService->filterToSector(
            $draft['lines'] ?? [],
            fn(MepLineModel $l) => $l->getIdProduct(),
            $sector,
            $ids
        );
        foreach ($draftLines as $line) {
            $id      = $line->getIdProduct();
            $product = $id !== null ? ($byId[$id] ?? null) : null;
            $cat     = $line->getCategoryName() ?: ($product?->getCategoryName() ?? '—');

            $rows[$cat][] = [
                'id_product' => $id,
                'name'       => $line->getName() ?? $product?->getName() ?? '—',
                'unit_name'  => $line->getUnitName() ?? $product?->getUnitName(),
                'batch_size' => $product !== null ? $product->getEffectiveBatchSize() : 1.0,
                'quantity'   => $line->getQuantityPlanned(),
            ];
        }

        // Une ligne encodée hier sur un produit qu'on ne prépare plus la veille
        // reste affichée : la retirer de l'écran ne la retire pas de la MEP.
        $categories = array_values(array_unique(array_merge(array_keys($pdb), array_keys($rows))));
        usort($categories, 'strnatcasecmp');

        foreach ($rows as &$group) {
            usort($group, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
        }
        unset($group);

        return [
            'mep_sub'            => 'afternoon',
            'tomorrow'           => $tomorrow,
            'products_available' => $products !== null,
            'pdb_catalog'        => $pdb,
            'mep_next_categories'=> $categories,
            'mep_next_rows'      => $rows,
            'draft_available'    => $draft !== null,
        ];
    }

    /**
     * Taille de fournée par produit — le pas des boutons « − » et « + ».
     *
     * Un catalogue muet ne bloque pas la validation : le pas retombe à 1 et
     * on saisit au clavier, ce qui reste préférable à un écran vide.
     *
     * @return array<int, float>
     */
    private function batchSizes(): array
    {
        $sizes = [];
        foreach ($this->productionService->getProducts() ?? [] as $p) {
            if ($p->getIdProduct() !== null) {
                $sizes[$p->getIdProduct()] = $p->getEffectiveBatchSize();
            }
        }
        return $sizes;
    }

    /**
     * GET /ajax/production/stock
     *
     * Stock et propositions dans une seule réponse : deux sondages séparés
     * afficheraient un stock et des propositions calculées sur un autre.
     */
    public function ajaxStock(): void
    {
        $today    = date('Y-m-d');
        $sector   = $this->productionService->resolveSector(
            isset($_GET['sector']) ? (string)$_GET['sector'] : null
        );
        $ids      = $this->productionService->sectorIds($sector);
        $products = $this->productionService->productsInSector($sector);

        $stock = $this->productionService->getStock();
        $stock = $stock === null ? null : $this->productionService->filterToSector(
            $stock,
            fn($s) => $s->getIdProduct(),
            $sector,
            $ids
        );

        $orders  = $this->orders($today, $sector);
        $rebakes = $this->productionService->getRebakeSuggestions(
            $products,
            $stock,
            $today,
            null,
            $this->orderBook->dueForProducts(
                $orders['lines'],
                $products ?? [],
                ForecastService::minutesOf(date('H:i')),
                (int)round((float)$this->productionService->getParams()['forecast_hours'] * 60)
            )
        );

        $this->json([
            'success'           => $stock !== null,
            'stock_available'   => $stock !== null,
            'stock'             => $stock ?? [],
            'rebakes_available' => $rebakes['available'],
            'rebakes_samples'   => $rebakes['samples'],
            'rebakes'           => $rebakes['suggestions'],
        ], $stock !== null ? 200 : 502)->send();
    }

    /**
     * POST /ajax/production/mep/validate
     *
     * Corps : { lines: [ { id, quantity, skipped? } ] } — toujours pour
     * aujourd'hui.
     */
    public function ajaxValidateMep(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input) || !is_array($input['lines'] ?? null)) {
            $this->json(['success' => false, 'description' => 'invalid_payload'], 400)->send();
            return;
        }

        $lines = [];
        foreach ($input['lines'] as $line) {
            if (!is_array($line) || !isset($line['id'])) {
                continue;
            }
            $entry = [
                'id'       => (int)$line['id'],
                'quantity' => max(0, (float)($line['quantity'] ?? 0)),
            ];
            if (!empty($line['skipped'])) {
                $entry['skipped'] = true;
            }
            $lines[] = $entry;
        }

        if ($lines === []) {
            $this->json(['success' => false, 'description' => 'nothing_to_validate'], 400)->send();
            return;
        }

        $response = $this->productionService->validateMep(date('Y-m-d'), $lines);
        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /**
     * POST /ajax/production/mep
     *
     * Corps : { date, lines: [ { id_product, quantity } ] }
     * Encodage de la mise en place du lendemain.
     */
    public function ajaxSaveMep(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input) || !is_array($input['lines'] ?? null)) {
            $this->json(['success' => false, 'description' => 'invalid_payload'], 400)->send();
            return;
        }

        $lines = [];
        foreach ($input['lines'] as $line) {
            if (!is_array($line) || empty($line['id_product'])) {
                continue;
            }
            $quantity = max(0, (float)($line['quantity'] ?? 0));
            // Une ligne à zéro n'est pas une mise en place : elle est omise
            // plutôt qu'envoyée, sinon la MEP de demain s'ouvrirait sur
            // quarante lignes vides.
            if ($quantity <= 0) {
                continue;
            }
            $lines[] = ['id_product' => (int)$line['id_product'], 'quantity' => $quantity];
        }

        $date = (string)($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d', strtotime('+1 day'));
        }

        $response = $this->productionService->saveMep($date, $lines);
        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /**
     * POST /ajax/production/shelf
     *
     * Corps : { id_product, quantity, id_mep_line?, id_employee? }
     *
     * C'est ce geste qui met en vente : la validation de MEP constate ce qui
     * est sorti du four, la mise en rayon décide de ce que la caisse peut
     * vendre. Voir docs/ENDPOINTS_PRODUCTION.md.
     */
    public function ajaxShelve(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);

        $idProduct = (int)($input['id_product'] ?? 0);
        $quantity  = (float)($input['quantity'] ?? 0);

        if ($idProduct <= 0 || $quantity <= 0) {
            $this->json(['success' => false, 'description' => 'invalid_payload'], 400)->send();
            return;
        }

        $response = $this->productionService->shelve(
            $idProduct,
            $quantity,
            isset($input['id_mep_line']) ? (int)$input['id_mep_line'] : null,
            isset($input['id_employee']) ? (int)$input['id_employee'] : null
        );

        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /**
     * POST /ajax/production/rebake
     *
     * Corps : { id_product, quantity, id_employee? }
     */
    public function ajaxRebake(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);

        $idProduct = (int)($input['id_product'] ?? 0);
        $quantity  = (float)($input['quantity'] ?? 0);

        if ($idProduct <= 0 || $quantity <= 0) {
            $this->json(['success' => false, 'description' => 'invalid_payload'], 400)->send();
            return;
        }

        $response = $this->productionService->createRebake(
            $idProduct,
            $quantity,
            isset($input['id_employee']) ? (int)$input['id_employee'] : null
        );

        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /** @param \App\Kitchen\app\Models\Production\PeriodModel[] $periods */
    private function readView(array $periods): string
    {
        $allowed = array_map(fn($p) => $p->getKey(), $periods);
        $allowed[] = 'stock';
        $allowed[] = 'minimums';
        $allowed[] = 'mep';
        $allowed[] = 'planning';

        $view = (string)($_GET['view'] ?? '');
        if (in_array($view, $allowed, true)) {
            return $view;
        }
        // Sans choix explicite, on ouvre sur la période en cours : à 15 h, un
        // écran qui s'ouvre sur « Matin » se referme aussitôt.
        return $this->productionService->currentPeriodKey();
    }
}
