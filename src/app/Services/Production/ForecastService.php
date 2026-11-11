<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\RebakeSuggestionModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;

/**
 * Décide s'il faut relancer une cuisson, et de combien.
 *
 * Service volontairement PUR : ni HTTP, ni horloge, ni session. L'heure
 * courante est passée en paramètre. C'est ce qui rend la logique vérifiable
 * hors serveur — voir bin/forecast-test.php et tests/fixtures/.
 *
 *     fenêtre(produit) = [maintenant ; maintenant + forecast_hours + lead]
 *     ventes_prévues   = Σ profil sur la fenêtre
 *     commandes        = Σ commandes fermes dues sur la fenêtre
 *     projection       = stock − ventes_prévues − commandes − marge
 *     si projection < 0 : ceil(−projection / batch) × batch
 *
 * Les commandes s'ajoutent aux ventes prévues, elles ne s'y substituent pas :
 * le profil dit ce qui partira probablement, une commande dit ce qui partira à
 * coup sûr. Un client qui repart sans son plateau coûte plus qu'une fournée de
 * trop.
 */
class ForecastService
{
    /**
     * @param ProductionProductModel[] $products
     * @param StockLineModel[]         $stockLines
     * @param SalesProfileModel|null   $profile     null = profil non servi : aucune proposition
     * @param int                      $nowMinutes  minutes depuis minuit
     * @param array{forecast_hours?: float, safety_margin?: float} $params
     * @param array<int, float>        $orders      commandes fermes dues, par produit
     *
     * @return RebakeSuggestionModel[] triées par urgence, la rupture la plus
     *         profonde d'abord
     */
    public function suggest(
        array $products,
        array $stockLines,
        ?SalesProfileModel $profile,
        int $nowMinutes,
        array $params = [],
        array $orders = []
    ): array {
        if ($profile === null) {
            // Sans profil, toute proposition serait inventée. L'écran affiche
            // le stock et dit que la prévision n'est pas disponible.
            return [];
        }

        $forecastMinutes = (int)round(((float)($params['forecast_hours'] ?? PRODUCTION_FORECAST_HOURS)) * 60);
        $margin          = (float)($params['safety_margin'] ?? PRODUCTION_SAFETY_MARGIN);

        $stockByProduct = [];
        foreach ($stockLines as $line) {
            if ($line->getIdProduct() !== null) {
                $stockByProduct[$line->getIdProduct()] = $line;
            }
        }

        $suggestions = [];

        foreach ($products as $product) {
            $id = $product->getIdProduct();
            if ($id === null || !$product->isActive()) {
                continue;
            }

            // Un produit absent du profil n'est pas un produit qui ne se vend
            // pas : c'en est un dont on ne sait rien. On ne propose pas.
            $window   = $forecastMinutes + $product->getLeadMinutes();
            $expected = $profile->expectedBetween($id, $nowMinutes, $nowMinutes + $window);
            if ($expected === null) {
                continue;
            }

            $stockLine = $stockByProduct[$id] ?? null;
            $stock     = $stockLine?->getQuantity() ?? 0.0;
            $ordered   = (float)($orders[$id] ?? 0.0);

            $projected = $stock - $expected - $ordered - $margin;
            if ($projected >= 0) {
                continue;
            }

            $deficit = -$projected;
            $batch   = $product->getEffectiveBatchSize();
            $qty     = ceil($deficit / $batch) * $batch;

            $suggestions[] = new RebakeSuggestionModel(
                $id,
                $product->getName() ?? $stockLine?->getName(),
                $product->getCategoryName() ?? $stockLine?->getCategoryName(),
                $stock,
                $expected,
                $projected,
                $deficit,
                $batch,
                $product->hasBatchSize(),
                $qty,
                $window,
                $product->getLeadMinutes(),
                $product->getUnitName() ?? $stockLine?->getUnitName(),
                $ordered
            );
        }

        // La rupture la plus profonde d'abord : c'est celle qui doit entrer au
        // four en premier.
        usort($suggestions, fn($a, $b) => $a->getProjected() <=> $b->getProjected());

        return $suggestions;
    }

    /**
     * Tension par produit : ce qui reste, ce qui va partir, ce qui restera.
     *
     * Même arithmétique que suggest(), mais rendue pour TOUS les produits et
     * sans décision de recuisson — c'est ce qui permet de classer une liste de
     * travail par urgence plutôt que par ordre alphabétique. Un produit dont on
     * ne sait rien garde `expected` et `projected` à null : le taire serait le
     * faire passer pour un produit qui ne se vend pas.
     *
     * @param ProductionProductModel[] $products
     * @param StockLineModel[]         $stockLines
     * @param array{forecast_hours?: float, safety_margin?: float} $params
     * @param array<int, float>        $orders  commandes fermes dues, par produit
     *
     * @return array<int, array{stock: float, expected: ?float, ordered: float, projected: ?float}>
     *         indexé par id_product
     */
    public function tension(
        array $products,
        array $stockLines,
        ?SalesProfileModel $profile,
        int $nowMinutes,
        array $params = [],
        array $orders = []
    ): array {
        $forecastMinutes = (int)round(((float)($params['forecast_hours'] ?? PRODUCTION_FORECAST_HOURS)) * 60);
        $margin          = (float)($params['safety_margin'] ?? PRODUCTION_SAFETY_MARGIN);

        $stockByProduct = [];
        foreach ($stockLines as $line) {
            if ($line->getIdProduct() !== null) {
                $stockByProduct[$line->getIdProduct()] = $line->getQuantity();
            }
        }

        $out = [];
        foreach ($products as $product) {
            $id = $product->getIdProduct();
            if ($id === null) {
                continue;
            }

            $stock    = $stockByProduct[$id] ?? 0.0;
            $expected = $profile?->expectedBetween(
                $id,
                $nowMinutes,
                $nowMinutes + $forecastMinutes + $product->getLeadMinutes()
            );

            // Une commande due se soustrait même quand le profil de ventes
            // manque : elle ne relève pas de la prévision, elle est déjà due.
            $ordered = (float)($orders[$id] ?? 0.0);

            $out[$id] = [
                'stock'     => $stock,
                'expected'  => $expected,
                'ordered'   => $ordered,
                'projected' => $expected === null ? null : $stock - $expected - $ordered - $margin,
            ];
        }

        return $out;
    }

    /** Minutes depuis minuit, pour une heure « HH:MM ». */
    public static function minutesOf(string $time): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)
            ? (int)$m[1] * 60 + (int)$m[2]
            : 0;
    }
}
