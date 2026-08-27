<?php

namespace App\Kitchen\app\Http\Controllers\Production;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Repositories\Production\ProductionPlanningRepository;
use App\Kitchen\app\Services\Knowledge\Preparation\PreparationPathService;
use App\Kitchen\app\Services\Production\Period1Service;
use App\Kitchen\core\Support\GlobalRegistry;
use App\Kitchen\core\Support\Route;

/**
 * Pilotage de la production — prévision du jour (version A, journée entière).
 *
 * Par produit : ce que la prévision annonce, ce qui s'est déjà vendu, et
 * l'écart (prévu − vendu). La prévision pondère les ventes des N mêmes jours de
 * semaine précédents (product-category-groups par date). Tout est journée
 * entière tant que l'ERP n'expose pas le filtre par créneau — le passage au
 * découpage horaire ne changera que la source des échantillons.
 *
 * La prévision (ProductionForecastService) et l'assemblage (Period1Service) sont
 * purs et testés ; ce contrôleur ne fait que réunir les données de l'endpoint
 * et nommer la route si elle se tait.
 */
class PilotageController extends Controller
{
    public function __construct(
        private ProductionPlanningRepository $planning,
        private Period1Service              $period1,
        private PreparationPathService      $prep
    ) {}

    /**
     * Fin de journée : jeter des pièces (photo facultative, quantité).
     * Écrit via l'API — jamais de succès inventé : si la route refuse,
     * l'erreur revient au navigateur avec le nom de la route.
     */
    public function wasteAction(): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $pid    = (int)($body['product_id'] ?? 0);
        $qty    = (float)($body['qty'] ?? 0);
        $photo  = is_string($body['photo_base64'] ?? null) ? $body['photo_base64'] : null;

        $out = $this->planning->wasteOut($shopId, $pid, $qty, 'end_of_day', $photo);
        header('Content-Type: application/json');
        echo json_encode($out);
    }

    /** Fin de journée : reporter des pièces au stock de demain (mouvement d'entrée). */
    public function stockAction(): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $body   = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $pid    = (int)($body['product_id'] ?? 0);
        $qty    = (float)($body['qty'] ?? 0);

        $out = $this->planning->stockIn($shopId, $pid, $qty);
        header('Content-Type: application/json');
        echo json_encode($out);
    }

    #[Route('GET', '/production/pilotage')]
    public function period1(): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $today  = date('Y-m-d');
        $weeks  = (int)PRODUCTION_HISTORY_WEEKS;   // paramètre de base (défaut 6)

        // ── L'historique : les N mêmes jours de semaine précédents ──
        // Une lecture par date. Une journée non servie réduit la fenêtre sans
        // bloquer : le moteur pondère sur ce qui est là.
        $historyByProduct = [];
        $meta             = [];   // id → {name, section}
        $lead             = [];   // id → délai de préparation (heures)
        $served           = 0;
        for ($k = 1; $k <= $weeks; $k++) {
            $d = date('Y-m-d', strtotime("$today -" . (7 * $k) . ' days'));
            $rows = $this->safeFetch(fn() => $this->planning->productSales($shopId, $d), $this->warnings, null, null);
            if ($rows === null) {
                continue;
            }
            $served++;
            foreach ($rows as $r) {
                $historyByProduct[$r['id']][] = ['date' => $d, 'quantity' => $r['qty']];
                $meta[$r['id']] ??= ['name' => $r['name'], 'section' => $r['sector']];
                if ($r['lead_hours'] !== null) {
                    $lead[$r['id']] = $r['lead_hours'];
                }
            }
        }

        // ── Les ventes réelles du jour ──
        $todayRows = $this->safeFetch(fn() => $this->planning->productSales($shopId, $today), $this->warnings, null, null);
        $sold = [];
        if (is_array($todayRows)) {
            foreach ($todayRows as $r) {
                $sold[$r['id']] = $r['qty'];
                $meta[$r['id']] ??= ['name' => $r['name'], 'section' => $r['sector']];
            }
        }

        // Aucune journée servie, ni historique ni aujourd'hui : la route ne
        // répond pas. On la nomme, on n'invente rien.
        if ($served === 0 && $todayRows === null) {
            $this->view('production/pilotage', [
                'state'       => 'missing',
                'rows'        => [],
                'to_produce'  => ['by_section' => [], 'total' => 0.0, 'unknown' => 0],
                'selected_date' => $today,
                'weeks'       => $weeks,
                'missing_api' => 'GET /shops/{id}/statistics/sales/product-category-groups',
            ]);
            return;
        }

        $rows = $this->period1->rows($historyByProduct, $sold, $meta, $today, $weeks);

        // ── Les commandes clients, déduites d'entrée ──
        // Les précommandes relèvent le besoin (on ne cuit jamais moins que ce
        // qui est commandé) et se déduisent de la vente libre. Un produit
        // commandé que la prévision ignore entre dans la liste : une commande
        // est un fait.
        $tomorrow       = date('Y-m-d', strtotime("$today +1 day"));
        $ordersToday    = $this->safeFetch(fn() => $this->planning->orderedForDay($shopId, $today), $this->warnings, null, null);
        $ordersTomorrow = $this->safeFetch(fn() => $this->planning->orderedForDay($shopId, $tomorrow), $this->warnings, null, null);
        $rows = $this->period1->applyReservations($rows, $ordersToday['lines'] ?? []);

        // ── Les drapeaux du catalogue : PDM, tenue, recuisson ──
        // Une lecture pour toute la boutique. Route muette : le flux reste
        // utilisable — tout part au matin, la recuisson s'affiche sans les
        // paramètres du catalogue, et l'écran nomme la route.
        $flags = $this->safeFetch(fn() => $this->planning->productFlags($shopId), $this->warnings, null, null);
        $flagsMissing = $flags === null ? 'GET /shops/{id}/products/available' : null;
        $flags      ??= [];

        // ── La gamme de production accrochée à chaque produit ──
        // Un seul appel pour la liste des produits configurés, puis un appel
        // par produit RÉELLEMENT configuré de la liste (le service referme
        // l'intersection lui-même). L'ETA vient alors de la durée du parcours
        // — le champ `preparation_lead_time_hours` de l'endpoint des ventes
        // reste vide en prod, on ne s'appuie donc pas dessus.
        $ids  = array_map(static fn(array $r): int => (int)$r['product_id'], $rows);
        $prep = $this->prep->detailsFor($ids);
        $now = time();
        foreach ($rows as &$row) {
            $detail = $prep['map'][(int)$row['product_id']] ?? null;
            $row['prep']       = $detail;              // null = pas de parcours servi
            $row['lead_hours'] = $lead[$row['product_id']] ?? null;  // repli si un jour l'API le remplit
            // Combien de fournées pour combler l'écart, si le four a une capacité.
            // La tenue du catalogue accompagne chaque carte (badges C/M/L).
            $row['shelf_minutes'] = $flags[(int)$row['product_id']]['shelf_minutes'] ?? null;
            $row['fournees'] = null;
            $need = $row['need'] ?? $row['ecart'];
            if ($detail !== null && $detail['oven_capacity'] && $need !== null && $need > 0) {
                $row['fournees'] = (int)ceil($need / $detail['oven_capacity']);
            }
            // Les heures : fin de chaque étape et sortie de chaque fournée SI
            // on lance maintenant. Le calcul est pur (ovenTimeline) ; ici on ne
            // fait qu'ajouter l'heure qu'il est.
            if ($detail !== null && $detail['steps'] !== []) {
                $tl = $this->period1->ovenTimeline($detail['steps'], $row['fournees'] ?? 1);
                foreach ($detail['steps'] as $i => &$st) {
                    $off = $tl['step_ends'][$i] ?? null;
                    $st['end_at'] = $off !== null ? date('H:i', $now + $off) : null;
                }
                unset($st);
                $row['prep']['steps'] = $detail['steps'];
                $row['batch_etas'] = array_map(
                    static fn(int $off): string => date('H:i', $now + $off),
                    $tl['batch_ready']
                );
            } else {
                $row['batch_etas'] = [];
            }
        }
        unset($row);

        // ── L'écran de pilotage montre ce qu'il y a À PRODUIRE ──
        // Le catalogue entier (souvent 200 lignes : cafés, épicerie…) noierait
        // le boulanger. On n'affiche que les produits en manque (écart connu et
        // positif), le plus gros besoin en tête de chaque section ; le reste
        // (déjà à niveau, ou sans prévision) est compté en pied de liste, pas
        // caché en silence.
        $needOf = static fn(array $r): ?float => $r['need'] ?? $r['ecart'];
        $display = array_values(array_filter(
            $rows,
            static fn(array $r): bool => $needOf($r) !== null && $needOf($r) > 0
        ));
        usort($display, static function (array $a, array $b) use ($needOf): int {
            $sa = $a['section'] === '' ? "\xff" : mb_strtolower($a['section']);
            $sb = $b['section'] === '' ? "\xff" : mb_strtolower($b['section']);
            // Section (Autres en dernier), puis besoin décroissant, puis nom.
            return [$sa, -$needOf($a), mb_strtolower($a['name'])]
               <=> [$sb, -$needOf($b), mb_strtolower($b['name'])];
        });

        // ── Étape 1 : matin / veille (PDM), panneaux par section ──
        $split = $this->period1->splitByPdm($display, $flags);
        $panels = [];
        foreach ($split['morning'] as $r) {
            $sec = $r['section'];
            if (!isset($panels[$sec])) {
                $panels[$sec] = ['section' => $sec, 'total' => 0.0, 'rows' => []];
            }
            $panels[$sec]['rows'][]   = $r;
            $panels[$sec]['total']   += $r['need'] ?? $r['ecart'];
        }
        $panels = array_values($panels);

        // ── Étape 3 : la file de recuisson, du plus urgent au moins urgent ──
        $reheat = $this->period1->reheatQueue($rows, $flags);

        // ── « À préparer pour demain » : la prévision de demain, produits PDM ──
        // Même moteur, cible = demain : l'historique porte sur les mêmes jours
        // de semaine que DEMAIN. Rien n'est encore vendu demain, l'écart vaut
        // donc la prévision entière — c'est la liste de préparation du soir.
        $historyTomorrow = [];
        $servedTomorrow  = 0;
        for ($k = 1; $k <= $weeks; $k++) {
            $d = date('Y-m-d', strtotime("$tomorrow -" . (7 * $k) . ' days'));
            $rowsT = $this->safeFetch(fn() => $this->planning->productSales($shopId, $d), $this->warnings, null, null);
            if ($rowsT === null) {
                continue;
            }
            $servedTomorrow++;
            foreach ($rowsT as $r) {
                $historyTomorrow[$r['id']][] = ['date' => $d, 'quantity' => $r['qty']];
                $meta[$r['id']] ??= ['name' => $r['name'], 'section' => $r['sector']];
            }
        }
        $rowsTomorrow = $this->period1->rows($historyTomorrow, [], $meta, $tomorrow, $weeks);
        // Seuls les produits marqués « préparation la veille » se préparent ce
        // soir — et seulement ceux que la prévision annonce (écart connu > 0).
        // Prévision + commandes fermes de demain : besoin = max des deux, et
        // un produit PDM commandé sans prévision entre quand même en liste.
        $tomorrowPdm = $this->period1->tomorrowPrep(
            $this->period1->splitByPdm($rowsTomorrow, $flags)['pdm'],
            $ordersTomorrow['lines'] ?? [],
            $flags
        );

        // ── La vue « stock » : tout le catalogue, secteur × conservation ──
        // Ouverte d'un toucher sur le compteur « EN STOCK ». Le secteur vient
        // des ventes (group_name) ; un produit jamais croisé tombe sous
        // « Autres ». Les infos du jour s'accrochent quand elles existent.
        $sectionOf = [];
        foreach ($meta as $id => $m) {
            $sectionOf[(int)$id] = (string)($m['section'] ?? '');
        }
        $todayById = [];
        foreach ($rows as $r) {
            $todayById[(int)$r['product_id']] = $r;
        }
        $stockPanels = $this->period1->stockPanels($flags, $sectionOf, $todayById);

        // ── Le plan « par four » et la base du bilan du jour ──
        $ovenPlan  = $this->period1->ovenPlan($reheat);
        $reportBase = $this->period1->dayReportBase($rows);

        // ── Les candidats vente flash (aussi le volet « fin de cycle ») ──
        // Tenue courte (≤ 12 h au catalogue) et encore au-dessus de la
        // prévision. Sans lots horodatés côté API, c'est la lecture la plus
        // honnête du risque d'invendu.
        $flash = $this->period1->flashCandidates($reheat);

        // ── Le bandeau de volumes de la recuisson ──
        $volumes = $this->period1->volumesOfDay($rows, $reheat);

        // ── Ce que l'écran de recuisson affiche ──
        // Les 12 plus urgents, PLUS tous les « fin de cycle » (tenue courte
        // encore en manque) même au-delà du plafond : le filtre doit les
        // montrer tous. Chaque ligne sait à quel(s) volet(s) elle appartient.
        $mainIds  = array_map(static fn(array $r) => (int)$r['product_id'], array_slice($reheat, 0, 12));
        $cycleIds = array_map(static fn(array $r) => (int)$r['product_id'], $flash);
        $queueDisplay = [];
        foreach ($reheat as $r) {
            $id = (int)$r['product_id'];
            $inMain  = in_array($id, $mainIds, true);
            $inCycle = in_array($id, $cycleIds, true);
            if ($inMain || $inCycle) {
                $r['in_main']  = $inMain;
                $r['in_cycle'] = $inCycle;
                $queueDisplay[] = $r;
            }
        }


        // Les réductions actives (liste vide = « aucune », un fait) et la
        // démarque des 7 derniers jours — ce qu'on a réellement jeté éclaire
        // ce qu'il faut solder plutôt que reproduire.
        $discounts = $this->safeFetch(fn() => $this->planning->activeDiscounts($shopId), $this->warnings, null, null);
        $wasteWeek = $this->safeFetch(
            fn() => $this->planning->waste($shopId, date('Y-m-d', strtotime("$today -7 days")), date('Y-m-d', strtotime("$today -1 day"))),
            $this->warnings, null, null
        );

        $atLevel = 0;   // produits qui se vendent mais sans manque (déjà à niveau / en avance)
        $unknown = 0;   // produits sans prévision exploitable
        foreach ($rows as $r) {
            if ($r['ecart'] === null)      { $unknown++; }
            elseif ($r['ecart'] <= 0)      { $atLevel++; }
        }

        $this->view('production/pilotage', [
            'state'         => 'served',
            'panels'        => $panels,
            'pdm_rows'      => $split['pdm'],
            'tomorrow_rows' => $tomorrowPdm,
            'tomorrow_date' => $tomorrow,
            'tomorrow_served' => $servedTomorrow,
            'orders_today'    => $ordersToday,
            'orders_tomorrow' => $ordersTomorrow,
            'orders_missing'  => $ordersToday === null ? 'GET /shops/{id}/client-orders' : null,
            'reheat_rows'   => $reheat,
            'queue_rows'    => $queueDisplay,
            'flags_missing' => $flagsMissing,
            'stock_panels'  => $stockPanels,
            'oven_plan'     => $ovenPlan,
            'report_base'   => $reportBase,
            'shown'         => count($display),
            'at_level'      => $atLevel,
            'unknown_count' => $unknown,
            'to_produce'    => $this->period1->toProduce($rows),
            'selected_date' => $today,
            'weeks'         => $weeks,
            'history_days'  => $served,
            'prep_missing'  => $prep['available'] ? null : $prep['missing'],
            'missing_api'   => null,
            // Étape 2 : l'écriture du stock attend son contrat ERP — on nomme
            // la route, on ne code pas d'écriture à l'aveugle.
            'inventory_api' => 'PATCH /shops/{id}/products/{id}/inventory',
            'discounts'         => $discounts,
            'discounts_missing' => $discounts === null ? 'GET /shops/{id}/scheduled-product-discounts/active' : null,
            'waste_week'        => $wasteWeek,
            'flash_rows'        => $flash,
            'volumes'           => $volumes,
            'waste_missing'     => $wasteWeek === null ? 'GET /shops/{id}/products/waste' : null,
        ]);
    }
}
