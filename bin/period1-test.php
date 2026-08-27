<?php
/**
 * Vérifie l'assemblage de l'écran « État période 1 », sans serveur.
 *
 *     php bin/period1-test.php
 *
 * Ce qui doit tenir : chaque produit vu — prévu OU vendu — a sa ligne ;
 * l'écart suit prévu − vendu et reste inconnu si la prévision l'est ; le total
 * « à produire » ne somme que les manques connus, section par section, et un
 * produit en avance ne comble pas un produit en retard.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Production\Period1Service;
use App\Kitchen\app\Services\Production\ProductionForecastService;

$ok = 0; $ko = [];
function check(string $what, $got, $want): void {
    global $ok, $ko;
    $eq = is_float($want) && is_float($got) ? abs($got - $want) < 1e-9 : $got === $want;
    if ($eq) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s", $what, json_encode($want), json_encode($got));
}

$svc = new Period1Service(new ProductionForecastService());
$C = '2026-08-27';
$jeudi = fn(int $k) => date('Y-m-d', strtotime("$C -" . 7*$k . " days"));

// Historique : baguette (101) vendue 40 la semaine passée ; croissant (102)
// sans historique ; éclair (103) 20.
$hist = [
    101 => [['date' => $jeudi(1), 'quantity' => 40], ['date' => $jeudi(2), 'quantity' => 40]],
    103 => [['date' => $jeudi(1), 'quantity' => 20], ['date' => $jeudi(2), 'quantity' => 20]],
];
$sold = [101 => 12.0, 102 => 5.0];   // 102 s'est vendu sans historique
$meta = [
    101 => ['name' => 'Baguette tradition', 'section' => 'boulangerie'],
    102 => ['name' => 'Croissant',          'section' => 'boulangerie'],
    103 => ['name' => 'Éclair chocolat',    'section' => 'patisserie'],
];

$rows = $svc->rows($hist, $sold, $meta, $C);
$by = [];
foreach ($rows as $r) { $by[$r['product_id']] = $r; }

check('trois produits vus',        count($rows), 3);
check('baguette : prévu',          $by[101]['prevu'], 40.0);
check('baguette : vendu',          $by[101]['vendu'], 12.0);
check('baguette : écart prévu−vendu', $by[101]['ecart'], 28.0);

// Croissant : vendu mais sans historique → prévu inconnu, écart inconnu.
check('croissant vu malgré aucun historique', isset($by[102]), true);
check('croissant : prévu inconnu', $by[102]['prevu'], null);
check('croissant : vendu présent', $by[102]['vendu'], 5.0);
check('croissant : écart inconnu', $by[102]['ecart'], null);

// Éclair : prévu 20, pas vendu aujourd'hui → vendu 0, écart 20.
check('éclair : vendu à zéro',     $by[103]['vendu'], 0.0);
check('éclair : écart = prévu',    $by[103]['ecart'], 20.0);

// Tri : section puis nom. boulangerie (Baguette, Croissant) avant patisserie.
check('tri par section puis nom', array_map(fn($r) => $r['name'], $rows),
    ['Baguette tradition', 'Croissant', 'Éclair chocolat']);

// ── À produire ────────────────────────────────────────────────────────────
$tp = $svc->toProduce($rows);
// baguette +28 (boulangerie), éclair +20 (patisserie) ; croissant inconnu.
check('à produire boulangerie', $tp['by_section']['boulangerie'] ?? 0.0, 28.0);
check('à produire patisserie',  $tp['by_section']['patisserie'] ?? 0.0, 20.0);
check('total à produire',       $tp['total'], 48.0);
check('un produit inconnu compté', $tp['unknown'], 1);

// Un produit EN AVANCE (écart négatif) ne comble pas un retard.
$rows2 = $svc->rows(
    [201 => [['date' => $jeudi(1), 'quantity' => 10]], 202 => [['date' => $jeudi(1), 'quantity' => 10]]],
    [201 => 30.0, 202 => 2.0],   // 201 déjà sur-vendu (écart -20), 202 en retard (+8)
    [201 => ['name' => 'A', 'section' => 's'], 202 => ['name' => 'B', 'section' => 's']],
    $C
);
$tp2 = $svc->toProduce($rows2);
check('avance ne compense pas retard', $tp2['total'], 8.0);

// ── Rien à assembler ────────────────────────────────────────────────────────
check('aucun produit → aucune ligne', $svc->rows([], [], [], $C), []);
check('aucun produit → rien à produire', $svc->toProduce([])['total'], 0.0);

if ($ko) { echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), $ok passées\n"; exit(1); }
echo "✓ $ok vérifications passées\n";
