<?php

namespace App\Kitchen\app\Http\Controllers\Production;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Repositories\Production\ProductionPlanningRepository;
use App\Kitchen\app\Services\Production\Period1Service;
use App\Kitchen\core\Support\GlobalRegistry;
use App\Kitchen\core\Support\Route;

/**
 * Le pilotage de la production, boutique par boutique.
 *
 * Brique 1 du module : l'état de la période 1 (matin) — par produit, ce qui
 * était prévu, ce qui s'est vendu, et l'écart (prévu − vendu). La prévision est
 * pure (ProductionForecastService), l'assemblage est pur (Period1Service) ; ce
 * contrôleur ne fait que réunir les données des endpoints et nommer la route
 * quand elles manquent.
 *
 * Rien n'est inventé : si `production-planning` ne rend pas de produits (ce qui
 * est le cas tant que la forme par-produit n'est pas confirmée au jeton),
 * l'écran le dit et nomme la route, comme partout ailleurs dans Kitchen.
 */
class PilotageController extends Controller
{
    public function __construct(
        private ProductionPlanningRepository $planning,
        private Period1Service              $period1
    ) {}

    #[Route('GET', '/production/pilotage')]
    public function period1(): void
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $today  = date('Y-m-d');

        // N est un paramètre de base (décision du 27/08). Sa vraie source est
        // `production/config`, absente de la prod : on prend la constante par
        // défaut en attendant, et on le documente.
        $weeks = (int)PRODUCTION_HISTORY_WEEKS;

        // La fenêtre : N semaines en arrière, même jour de semaine. On borne au
        // même jour de semaine côté moteur ; ici on demande large.
        $from = date('Y-m-d', strtotime("$today -" . (7 * $weeks) . " days"));

        $planningData = $this->safeFetch(
            fn() => $this->planning->planning($shopId, $from, $today, [(int)date('N')]),
            $this->warnings,
            null,
            null
        );

        $dayparts = $this->planning->dayparts($planningData);
        $period   = $dayparts[0] ?? null;   // la période 1 = première tranche

        $rows        = [];
        $toProduce   = ['by_section' => [], 'total' => 0.0, 'unknown' => 0];
        $state       = 'missing';
        $missing     = null;

        if ($planningData === null) {
            $missing = 'GET /shops/{id}/statistics/production-planning';
        } elseif ($period === null) {
            // La route répond mais ne ventile pas par tranche (WHOLE_DAY seul,
            // ou rien) : ce n'est pas une panne, c'est une réponse incomplète.
            $state   = 'no_period';
        } else {
            $history = $this->planning->historyFromPlanning($planningData, $period['code']);
            $meta    = $this->planning->metaFromPlanning($planningData);
            $sold    = $this->safeFetch(
                fn() => $this->planning->soldToday($shopId, $today, $period['from'], $period['to']),
                $this->warnings,
                null,
                null
            );

            if ($history === [] && ($sold === null || $sold === [])) {
                // La tranche existe mais aucun produit ne remonte : la forme
                // par-produit n'est pas encore confirmée. On nomme la route.
                $missing = 'GET /shops/{id}/statistics/production-planning — détail par produit';
            } else {
                $rows      = $this->period1->rows($history, $sold ?? [], $meta, $today, $weeks);
                $toProduce = $this->period1->toProduce($rows);
                $state     = 'served';
            }
        }

        $this->view('production/pilotage', [
            'period'      => $period,
            'rows'        => $rows,
            'to_produce'  => $toProduce,
            'state'       => $state,
            'weeks'       => $weeks,
            'selected_date' => $today,
            'missing_api' => $missing,
        ]);
    }
}
