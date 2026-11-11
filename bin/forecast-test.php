#!/usr/bin/env php
<?php
/**
 * Vérifie la logique de prévision des recuissons, sans serveur ni API.
 *
 *     php bin/forecast-test.php            # jeu de référence + assertions
 *     php bin/forecast-test.php 15:30      # même jeu, à une autre heure
 *     php bin/forecast-test.php 10:00 3    # avec une fenêtre de 3 heures
 *
 * ForecastService est pur : mêmes entrées, mêmes sorties. Un désaccord sur une
 * proposition se tranche donc en modifiant tests/fixtures/, pas en discutant.
 *
 * Le jeu de référence (10:00, fenêtre 2 h, marge 0) est calculable à la main :
 *
 *   Croissant  batch 24, cuisson 40 min → fenêtre 10:00→12:40
 *              6 pc/30 min × 5,33 créneaux = 32 · stock 10 → −22 → 24 (1 fournée)
 *   Macaron    pas de batch déclaré      → fenêtre 10:00→12:00
 *              2 × 4 = 8 · stock 3 → −5 → 5 (à l'unité, signalé)
 *   Tarte      batch 6, pas de cuisson   → fenêtre 10:00→12:00
 *              1,5 × 4 = 6 · stock 2 → −4 → 6 (1 fournée)
 *   Baguette   stock 30, ventes 14       → +16, aucune proposition
 *   Bûche      is_active = false         → jamais proposée, même à zéro
 *   Sandwich   absent du profil          → jamais proposé : on ne sait rien
 */

require __DIR__ . '/../vendor/autoload.php';

// Le service ne lit que ces constantes, et par défaut seulement.
define('SHARED_FILES_URL', 'https://files.local');
const PRODUCTION_FORECAST_HOURS = 2;
const PRODUCTION_HISTORY_WEEKS  = 6;
const PRODUCTION_SAFETY_MARGIN  = 0;

use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Services\Production\FixtureSalesProvider;
use App\Kitchen\app\Services\Production\ForecastService;

$fixtures = __DIR__ . '/../tests/fixtures';
$time     = $argv[1] ?? '10:00';
$hours    = isset($argv[2]) ? (float)$argv[2] : (float)PRODUCTION_FORECAST_HOURS;
$margin   = isset($argv[3]) ? (float)$argv[3] : (float)PRODUCTION_SAFETY_MARGIN;

$load = function (string $file) use ($fixtures) {
    $raw = json_decode((string)file_get_contents("$fixtures/$file"), true);
    if (!is_array($raw)) {
        fwrite(STDERR, "Fixture illisible : $file\n");
        exit(1);
    }
    return $raw;
};

$products = array_map(fn($r) => new ProductionProductModel($r), $load('products.json'));
$stock    = array_map(fn($r) => new StockLineModel($r), $load('stock.json')['items']);
$profile  = (new FixtureSalesProvider("$fixtures/sales_profile.json"))
    ->getProfile(1, '2026-07-31', (int)PRODUCTION_HISTORY_WEEKS);

if ($profile === null) {
    fwrite(STDERR, "Profil de ventes illisible.\n");
    exit(1);
}

$suggestions = (new ForecastService())->suggest(
    $products,
    $stock,
    $profile,
    ForecastService::minutesOf($time),
    ['forecast_hours' => $hours, 'safety_margin' => $margin]
);

printf(
    "\nHeure %s · fenêtre %s h · marge %s · historique %d semaines (%d journées agrégées)\n\n",
    $time, rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ','), $margin,
    $profile->getWeeks(), $profile->getSamples()
);

printf("%-32s %7s %9s %10s %8s %10s\n", 'Produit', 'Stock', 'Ventes', 'Projeté', 'Batch', 'À cuire');
echo str_repeat('-', 82), "\n";

foreach ($suggestions as $s) {
    printf(
        "%-32s %7s %9s %10s %8s %10s\n",
        mb_strimwidth((string)$s->getName(), 0, 32, '…'),
        rtrim(rtrim(number_format($s->getStock(), 1, ',', ' '), '0'), ','),
        number_format($s->getExpectedSales(), 1, ',', ' '),
        number_format($s->getProjected(), 1, ',', ' '),
        $s->isBatchKnown() ? (int)$s->getBatchSize() : '—',
        (int)$s->getSuggestedQuantity() . ($s->isBatchKnown() ? ' (' . $s->getBatchCount() . '×)' : '')
    );
}

if ($suggestions === []) {
    echo "  aucune proposition\n";
}
echo "\n";

// ── Assertions sur le jeu de référence ───────────────────────────────────
if ($time !== '10:00' || $hours !== 2.0 || $margin !== 0.0) {
    echo "Paramètres non standard : assertions ignorées.\n\n";
    exit(0);
}

$failures = [];
$check = function (string $label, $expected, $actual) use (&$failures) {
    if ($expected != $actual) {
        $failures[] = sprintf('%s : attendu %s, obtenu %s', $label, var_export($expected, true), var_export($actual, true));
    }
};

$byId = [];
foreach ($suggestions as $s) {
    $byId[$s->getIdProduct()] = $s;
}

$check('nombre de propositions', 3, count($suggestions));
$check('ordre — la rupture la plus profonde en premier', 6700106, $suggestions[0]->getIdProduct() ?? null);

$check('croissant · ventes prévues', 32.0, round($byId[6700106]?->getExpectedSales() ?? -1, 2));
$check('croissant · projection',     -22.0, round($byId[6700106]?->getProjected() ?? 0, 2));
$check('croissant · quantité (arrondie au batch de 24)', 24.0, $byId[6700106]?->getSuggestedQuantity());
$check('croissant · nombre de fournées', 1, $byId[6700106]?->getBatchCount());

$check('tarte · quantité (arrondie au batch de 6)', 6.0, $byId[6700130]?->getSuggestedQuantity());

$check('macaron · batch inconnu signalé', false, $byId[6700140]?->isBatchKnown());
$check('macaron · quantité à l\'unité', 5.0, $byId[6700140]?->getSuggestedQuantity());

$check('baguette · stock suffisant, aucune proposition', false, isset($byId[6700120]));
$check('bûche · produit inactif, jamais proposé', false, isset($byId[6700150]));
$check('sandwich · absent du profil, jamais proposé', false, isset($byId[6700160]));

if ($failures === []) {
    echo "✓ 11 assertions vérifiées\n\n";
    exit(0);
}

echo "✗ " . count($failures) . " échec(s) :\n";
foreach ($failures as $f) {
    echo "  · $f\n";
}
echo "\n";
exit(1);
