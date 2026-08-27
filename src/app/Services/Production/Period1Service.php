<?php

namespace App\Kitchen\app\Services\Production;

/**
 * L'assemblage de l'écran « État période 1 ».
 *
 * ── Pur, comme le moteur ──
 * Il ne fait aucun appel : il reçoit ce que le dépôt a rapporté des endpoints
 * et compose les lignes de l'écran — prévu / vendu / écart, par produit, par
 * section. Vérifiable sans réseau (bin/period1-test.php).
 *
 * La prévision vient de ProductionForecastService (6 semaines pondérées, même
 * jour, même tranche, ruptures exclues). Le vendu vient des ventes réelles du
 * jour sur la tranche. L'écart = prévu − vendu (décision du 27/08) — et reste
 * inconnu si la prévision l'est, jamais « −vendu ».
 *
 * ── Ce qu'il ne masque pas ──
 * Un produit sans prévision exploitable garde sa ligne, avec « prévu » à null :
 * l'atelier voit qu'il se vend (le vendu est là) mais qu'on ne sait pas encore
 * le prévoir — c'est une information, pas un trou à cacher.
 */
class Period1Service
{
    public function __construct(
        private ProductionForecastService $forecast
    ) {}

    /**
     * Les lignes de la tranche, une par produit, triées par section puis nom.
     *
     * @param array<int|string, array<int, array{date: string, quantity: float|int, stockout?: bool}>> $historyByProduct
     *        Historique de ventes de la tranche, par produit (pour la prévision).
     * @param array<int|string, float> $soldByProduct
     *        Ventes réelles du jour sur la tranche, par produit.
     * @param array<int|string, array{name?: string, section?: string}> $meta
     *        Nom et section de chaque produit.
     * @param string $targetDate  Jour affiché (Y-m-d).
     * @param int    $weeks        Fenêtre d'historique (paramètre de base).
     *
     * @return array<int, array{product_id: int|string, name: string, section: string,
     *                          prevu: ?float, vendu: float, ecart: ?float}>
     */
    public function rows(
        array $historyByProduct,
        array $soldByProduct,
        array $meta,
        string $targetDate,
        int $weeks = ProductionForecastService::DEFAULT_WEEKS
    ): array {
        $forecasts = $this->forecast->forecastMany($historyByProduct, $targetDate, $weeks);

        // L'union des produits vus : ceux qu'on prévoit ET ceux qui se sont
        // vendus. Un produit vendu sans historique doit apparaître (prévu
        // inconnu) ; un produit prévu sans vente du jour aussi (vendu = 0).
        $ids = array_keys($soldByProduct) + array_keys($historyByProduct);
        $ids = array_values(array_unique(array_merge(array_keys($soldByProduct), array_keys($historyByProduct))));

        $rows = [];
        foreach ($ids as $id) {
            $prevu = $forecasts[$id] ?? null;
            $vendu = (float)($soldByProduct[$id] ?? 0.0);
            $rows[] = [
                'product_id' => $id,
                'name'       => (string)($meta[$id]['name'] ?? ('#' . $id)),
                'section'    => (string)($meta[$id]['section'] ?? ''),
                'prevu'      => $prevu,
                'vendu'      => $vendu,
                'ecart'      => ProductionForecastService::gap($prevu, $vendu),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['section'], mb_strtolower($a['name'])]
               <=> [$b['section'], mb_strtolower($b['name'])];
        });

        return $rows;
    }

    /**
     * Le total de la tranche : combien il reste à produire, section par section.
     *
     * On ne somme que les écarts POSITIFS connus : un produit en avance
     * (écart négatif) ne compense pas un produit en retard — on ne détruit pas
     * ce qui est déjà en vitrine. Un écart inconnu ne compte pas.
     *
     * @param array<int, array{section: string, ecart: ?float}> $rows
     * @return array{by_section: array<string, float>, total: float, unknown: int}
     */
    public function toProduce(array $rows): array
    {
        $bySection = [];
        $total     = 0.0;
        $unknown   = 0;
        foreach ($rows as $r) {
            if ($r['ecart'] === null) {
                $unknown++;
                continue;
            }
            if ($r['ecart'] > 0) {
                $sec = $r['section'];
                $bySection[$sec] = ($bySection[$sec] ?? 0.0) + $r['ecart'];
                $total += $r['ecart'];
            }
        }

        return ['by_section' => $bySection, 'total' => $total, 'unknown' => $unknown];
    }
}
