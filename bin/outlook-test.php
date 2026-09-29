<?php
/**
 * Vérifie StockOutlookService sans serveur ni réseau.
 *
 *     php bin/outlook-test.php
 *
 * Deux règles décident de l'écran Stock : jusqu'où porte chaque horizon, et
 * dans quel filtre tombe une ligne. Les deux se testent ici.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Services\Production\ForecastService;
use App\Kitchen\app\Services\Production\StockOutlookService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$svc = new StockOutlookService();

$periods = array_map(fn($p) => new PeriodModel($p), [
    ['key' => 'morning',   'start' => '05:00', 'end' => '11:00'],
    ['key' => 'noon',      'start' => '11:00', 'end' => '14:00'],
    ['key' => 'afternoon', 'start' => '14:00', 'end' => '19:00'],
]);
$at = fn(string $t) => $svc->horizons($periods, ForecastService::minutesOf($t));

// ── Les horizons ──────────────────────────────────────────────────────────
$h = $at('07:00');
check('P1 = la période en cours',                 $h['p1']['key'], 'morning');
check('P1 part de maintenant, pas de 5 h',        $h['p1']['from'], 420);
check('P1 va au bout de la période',              $h['p1']['to'], 660);
check('P2 = la période suivante, entière',        [$h['p2']['key'], $h['p2']['from'], $h['p2']['to']], ['noon', 660, 840]);

// À 10 h 45, ce qui reste à tenir c'est un quart d'heure. Compter les ventes
// depuis 5 h condamnerait tout le magasin à la rupture.
$h = $at('10:45');
check('fin de période : P1 ne dure que le reste', $h['p1']['to'] - $h['p1']['from'], 15);

// Avant l'ouverture, la première période compte en entier.
$h = $at('04:00');
check('avant ouverture : P1 est la première',     $h['p1']['key'], 'morning');
check('avant ouverture : P1 part de l\'ouverture', $h['p1']['from'], 300);

// Sur la dernière période, il n'y a pas de suivante — et on ne l'invente pas
// sur la journée du lendemain.
$h = $at('15:00');
check('dernière période : pas de P2',             $h['p2']['key'], null);
check('dernière période : P2 est vide',           $h['p2']['to'] - $h['p2']['from'], 0);

// Après la fermeture, plus rien à projeter.
$h = $at('20:00');
check('après fermeture : aucun horizon',          [$h['p1']['key'], $h['p2']['key']], [null, null]);

// ── Les onglets qu'il reste à faire ───────────────────────────────────────
$tabs = fn(string $t, ?string $active = null) => array_map(
    fn(PeriodModel $p) => $p->getKey(),
    $svc->upcoming($periods, ForecastService::minutesOf($t), $active)
);

check('début de journée : tout est devant',   $tabs('07:00'), ['morning', 'noon', 'afternoon']);
check('le matin fini disparaît',              $tabs('12:30'), ['noon', 'afternoon']);
check('il ne reste que la dernière',          $tabs('16:00'), ['afternoon']);
// Un lien partagé sur une période passée doit garder son onglet, sinon l'écran
// s'affiche sans que rien ne dise où l'on est.
check('la période active reste visible',      $tabs('16:00', 'morning'), ['morning', 'afternoon']);
// Après la fermeture, on garde la dernière plutôt que de vider la barre.
check('après fermeture : la dernière reste',  $tabs('20:00'), ['afternoon']);
check('bornes : à 11:00 pile le matin est fini', $tabs('11:00'), ['noon', 'afternoon']);
check('bornes : à 10:59 il tient encore',        $tabs('10:59'), ['morning', 'noon', 'afternoon']);

// ── Les filtres ───────────────────────────────────────────────────────────
$horizons = $at('07:00');

$prod = fn(int $id, string $name, string $cat) => new ProductionProductModel([
    'id_product' => $id, 'name' => $name, 'category_name' => $cat,
    'periods' => ['morning'], 'unit_name' => 'pc', 'is_active' => true,
]);
$products = [
    $prod(1, 'Croissant', 'Viennoiserie'),
    $prod(2, 'Baguette', 'Boulangerie'),
    $prod(3, 'Éclair', 'Pâtisserie'),
    $prod(4, 'Cookie', 'Pâtisserie'),
    $prod(5, 'Bûche', 'Pâtisserie'),
];

// Profil plat : 10/h le matin (07:00→11:00 = 40), 10/h le midi (11:00→14:00 = 30).
$slots = [];
for ($m = 300; $m < 1140; $m += 60) {
    $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
$flat = array_fill(0, count($slots), 10);
$profile = new SalesProfileModel([
    'granularity_minutes' => 60,
    'slots'    => $slots,
    'products' => [
        ['id_product' => 1, 'expected' => $flat],
        ['id_product' => 2, 'expected' => $flat],
        ['id_product' => 3, 'expected' => $flat],
        ['id_product' => 4, 'expected' => $flat],
        // 5 absent : on ne sait rien de ses ventes.
    ],
]);

$stock = [
    new StockLineModel(['id_product' => 1, 'name' => 'Croissant', 'quantity' => 20]),   // < 40 → out P1
    new StockLineModel(['id_product' => 2, 'name' => 'Baguette',  'quantity' => 50]),   // tient P1 (40), pas P1+P2 (70)
    new StockLineModel(['id_product' => 3, 'name' => 'Éclair',    'quantity' => 90]),   // tient, reste 20 < 70
    new StockLineModel(['id_product' => 4, 'name' => 'Cookie',    'quantity' => 200]),  // reste 130 >= 70 → surproduction
    new StockLineModel(['id_product' => 5, 'name' => 'Bûche',     'quantity' => 4]),    // ventes inconnues
];

$rows = $svc->rows($stock, $products, $profile, $horizons);
$by   = [];
foreach ($rows as $r) { $by[$r['id_product']] = $r; }

check('ventes P1 = de maintenant à la fin du matin', $by[1]['expected_1'], 40.0);
check('ventes P2 = le midi entier',                  $by[1]['expected_2'], 30.0);
check('delta P1',                                    $by[1]['delta_1'], -20.0);
check('delta P2 cumule les deux périodes',           $by[2]['delta_2'], -20.0);

check('rupture avant la fin du service',             $by[1]['flag'], StockOutlookService::OUT_P1);
check('tient le service, pas le suivant',            $by[2]['flag'], StockOutlookService::OUT_P2);
check('ça passe',                                    $by[3]['flag'], StockOutlookService::OK);
check('surproduction : il en reste plus qu\'on n\'en vend', $by[4]['flag'], StockOutlookService::OVER);
check('ventes inconnues : aucune conclusion',        $by[5]['flag'], StockOutlookService::UNKNOWN);
check('ventes inconnues : pas de delta inventé',     $by[5]['delta_1'], null);

check('trié par catégorie puis produit',
    array_map(fn($r) => $r['category'] . '/' . $r['name'], $rows),
    ['Boulangerie/Baguette', 'Pâtisserie/Bûche', 'Pâtisserie/Cookie', 'Pâtisserie/Éclair', 'Viennoiserie/Croissant']);

check('compteurs des pastilles',
    $svc->counts($rows),
    ['out_p1' => 1, 'out_p2' => 1, 'over' => 1, 'all' => 5]);

// Un produit qui ne se vend pas du tout sur l'horizon n'est pas surproduit :
// il est hors service, hors saison, ou pas de ce moment de la journée.
$muet = new SalesProfileModel([
    'granularity_minutes' => 60, 'slots' => $slots,
    'products' => [['id_product' => 1, 'expected' => array_fill(0, count($slots), 0)]],
]);
$r = $svc->rows([new StockLineModel(['id_product' => 1, 'quantity' => 50])], $products, $muet, $horizons);
check('zéro vente prévue n\'est pas une surproduction', $r[0]['flag'], StockOutlookService::OK);

// Sans profil du tout, on affiche le stock et rien d'autre.
$r = $svc->rows($stock, $products, null, $horizons);
check('sans profil : aucune projection', [$r[0]['expected_1'], $r[0]['delta_1'], $r[0]['flag']],
    [null, null, StockOutlookService::UNKNOWN]);

echo "\n";
if ($ko === []) { echo "✓ {$ok} assertions vérifiées\n\n"; exit(0); }
echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), {$ok} succès\n\n";
exit(1);
