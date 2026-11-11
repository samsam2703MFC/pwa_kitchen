<?php
/**
 * Vérifie ProductionBoardService sans serveur ni réseau.
 *
 *     php bin/board-test.php
 *
 * Le service est pur : tout entre en paramètre. C'est ce qui permet de tester
 * ici les deux règles qui décident de l'écran — dans quelle section tombe un
 * produit, et dans quel ordre les tuiles se rangent.
 */
require __DIR__ . '/../vendor/autoload.php';

const PRODUCTION_FORECAST_HOURS = 2;
const PRODUCTION_HISTORY_WEEKS  = 6;
const PRODUCTION_SAFETY_MARGIN  = 0;

use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\app\Models\Production\MepLineModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Services\Production\ForecastService;
use App\Kitchen\app\Services\Production\ProductionBoardService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) {
        $ok++;
        return;
    }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$board = new ProductionBoardService();

// ── Le catalogue de la période ────────────────────────────────────────────
$p = fn(int $id, string $name, string $cat, ?float $batch = 12) => new ProductionProductModel([
    'id_product' => $id, 'name' => $name, 'category_name' => $cat,
    'periods' => ['morning'], 'batch_size' => $batch, 'unit_name' => 'pc', 'is_active' => true,
]);

$products = [
    $p(1, 'Croissant', 'Viennoiserie', 24),
    $p(2, 'Baguette', 'Boulangerie', 12),
    $p(3, 'Pain céréales', 'Boulangerie', 10),
    $p(4, 'Éclair', 'Pâtisserie', 6),
    $p(5, 'Cookie', 'Pâtisserie', 20),
];

// ── L'étape vient du plan de cuisson ──────────────────────────────────────
$batch = fn(int $id, int $idProduct, string $status, string $prepStart, string $cookStart) => new BakingBatchModel([
    'id' => $id, 'id_product' => $idProduct, 'status' => $status,
    'prep_start' => $prepStart, 'prep_minutes' => 20,
    'cook_start' => $cookStart, 'cook_minutes' => 20,
    'finish_type' => 'LOT', 'finish_minutes' => 10, 'shelf_delay_minutes' => 5,
]);

$stages = $board->stagesByProduct([
    $batch(90, 2, 'BAKING',    '06:00', '07:00'),
    $batch(91, 3, 'FINISHING', '05:30', '06:30'),
    $batch(92, 4, 'PLANNED',   '07:20', '08:00'),
    // Terminée : elle ne donne aucune étape à son produit.
    $batch(93, 5, 'DONE',      '05:00', '06:00'),
    // Deux fournées du même produit : c'est la plus imminente qui parle. Celle
    // de l'après-midi ne doit pas remonter le croissant en « préparation »
    // alors qu'une autre est déjà au four.
    $batch(94, 1, 'PLANNED',   '13:00', '13:40'),
    $batch(95, 1, 'BAKING',    '06:10', '07:10'),
]);

check('étape lue depuis le plan (baguette au four)', $stages[2]['stage'] ?? null, 'cook');
check('étape lue depuis le plan (pain en finition)', $stages[3]['stage'] ?? null, 'finish');
check('une fournée non commencée reste en préparation', $stages[4]['stage'] ?? null, 'prep');
check('une fournée terminée ne donne pas d\'étape', isset($stages[5]), false);
check('deux fournées : la plus imminente décide', $stages[1]['stage'] ?? null, 'cook');

// ── Tension : stock et ventes prévues ─────────────────────────────────────
$stock = [
    new StockLineModel(['id_product' => 1, 'quantity' => 40]),
    new StockLineModel(['id_product' => 2, 'quantity' => 4]),
    new StockLineModel(['id_product' => 3, 'quantity' => 30]),
    new StockLineModel(['id_product' => 4, 'quantity' => 2]),
    new StockLineModel(['id_product' => 5, 'quantity' => 50]),
];

// Profil plat de 08:00 à 10:00. Le cookie (5) en est absent : on ne sait rien
// de ses ventes, et le taire le ferait passer pour un produit qui ne se vend pas.
$profile = new SalesProfileModel([
    'granularity_minutes' => 30,
    'weeks'   => 6,
    'samples' => 6,
    'slots'   => ['08:00', '08:30', '09:00', '09:30'],
    'products' => [
        ['id_product' => 1, 'expected' => [2, 2, 2, 2]],
        ['id_product' => 2, 'expected' => [10, 10, 10, 10]],
        ['id_product' => 3, 'expected' => [2, 2, 2, 2]],
        ['id_product' => 4, 'expected' => [2, 2, 2, 2]],
    ],
]);

$tension = (new ForecastService())->tension($products, $stock, $profile, ForecastService::minutesOf('08:00'));

check('ventes prévues connues pour la baguette', $tension[2]['expected'], 40.0);
check('projeté négatif quand ça va manquer', $tension[2]['projected'], -36.0);
check('produit absent du profil : ventes inconnues', $tension[5]['expected'], null);
check('produit absent du profil : pas de projection inventée', $tension[5]['projected'], null);

// ── Les sections ──────────────────────────────────────────────────────────
$mep = [
    // Produit et à moitié porté : reste 59 à mettre en rayon.
    new MepLineModel(['id' => 401, 'id_product' => 1, 'quantity_planned' => 120,
                      'quantity_validated' => 118, 'quantity_shelved' => 59, 'status' => 'VALIDATED']),
    // Produit et entièrement porté : plus rien à faire de ce côté.
    new MepLineModel(['id' => 402, 'id_product' => 3, 'quantity_planned' => 30,
                      'quantity_validated' => 30, 'quantity_shelved' => 30, 'status' => 'VALIDATED']),
    // Pas encore validée : rien n'est constaté, donc rien à porter.
    new MepLineModel(['id' => 403, 'id_product' => 4, 'quantity_planned' => 24, 'status' => 'PREPARED']),
];

$b = $board->build($products, $mep, $stages, $tension);
$ids = fn(string $k) => array_map(fn($r) => $r['product']->getIdProduct(), $b['buckets'][$k]);

check('ce qui reste à porter passe devant son étape', $ids('shelf'), [1]);
check('une ligne non validée ne donne rien à porter', in_array(4, $ids('shelf'), true), false);
check('finition', $ids('finish'), [3]);
check('cuisson', $ids('cook'), [2]);
check('préparation', $ids('prep'), [4]);
check('sans fournée ni reste à porter : rien en cours', $ids('idle'), [5]);
check('compteur de mises en rayon', $b['to_shelf'], 1);
check('catégories triées, une seule fois chacune', $b['categories'], ['Boulangerie', 'Pâtisserie', 'Viennoiserie']);

// ── La conclusion de la tuile : couleur et quantité à produire ────────────
// Sans MEP ni plan : tout tombe dans la même section, on n'observe que la
// conclusion et le tri.
$b2 = $board->build($products, [], [], $tension);

$row = fn(string $k, int $id) => current(array_filter(
    $b2['buckets'][$k],
    fn($r) => $r['product']->getIdProduct() === $id
)) ?: [];

// 2 : stock 4, ventes 40 → −36. Fournée de 12 → trois fournées.
check('manque : quantité arrondie à la fournée', $row('idle', 2)['to_produce'] ?? null, 36.0);
check('manque : niveau rouge',                   $row('idle', 2)['level'] ?? null, 'short');
// 4 : stock 2, ventes 8 → −6. Fournée de 6 → une fournée.
check('un seul lot suffit',                      $row('idle', 4)['to_produce'] ?? null, 6.0);
// 1 : stock 40, ventes 8 → 32, fournée 24 → plus d'une fournée de marge.
check('de la marge : rien à produire',           $row('idle', 1)['to_produce'] ?? null, 0.0);
check('de la marge : niveau vert',               $row('idle', 1)['level'] ?? null, 'ok');
// 3 : stock 30, ventes 8 → 22, fournée 10 → deux fournées de marge.
check('marge confortable malgré un petit lot',   $row('idle', 3)['level'] ?? null, 'ok');
// 5 : absent du profil.
// `??` confondrait « null » et « clé absente », ce qui est justement la
// distinction testée : inconnu n'est pas zéro.
$inconnu = $row('idle', 5);
check('ventes inconnues : la clé existe',              array_key_exists('to_produce', $inconnu), true);
check('ventes inconnues : rien à produire, pas zéro',  $inconnu['to_produce'], null);
check('ventes inconnues : aucune couleur',       $row('idle', 5)['level'] ?? null, 'unknown');

// Ça passe, mais à moins d'une fournée près : orange, et toujours rien à
// lancer — un chiffre ici ferait croire le contraire.
$serre = $board->build(
    [$p(9, 'Brioche', 'Viennoiserie', 20)],
    [], [],
    [9 => ['stock' => 15.0, 'expected' => 3.0, 'projected' => 12.0]]
);
check('marge inférieure à une fournée : orange', $serre['buckets']['idle'][0]['level'], 'tight');
check('orange : rien à produire',                $serre['buckets']['idle'][0]['to_produce'], 0.0);

// ── Le tri à l'intérieur d'une section ────────────────────────────────────
check(
    // 2 : 4−40 = −36 · 4 : 2−8 = −6 · 3 : 30−8 = 22 · 1 : 40−8 = 32 · 5 : inconnu
    'la rupture la plus profonde d\'abord, ventes inconnues en dernier',
    array_map(fn($r) => $r['product']->getIdProduct(), $b2['buckets']['idle']),
    [2, 4, 3, 1, 5]
);

// Sans profil de ventes, le classement retombe sur le stock seul plutôt que
// sur l'ordre alphabétique.
$t3 = (new ForecastService())->tension($products, $stock, null, ForecastService::minutesOf('08:00'));
$b3 = $board->build($products, [], [], $t3);
check(
    'sans profil : classement par stock croissant',
    array_map(fn($r) => $r['product']->getIdProduct(), $b3['buckets']['idle']),
    [4, 2, 3, 1, 5]
);

// ── Résultat ──────────────────────────────────────────────────────────────
echo "\n";
if ($ko === []) {
    echo "✓ {$ok} assertions vérifiées\n\n";
    exit(0);
}
echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), {$ok} succès\n\n";
exit(1);
