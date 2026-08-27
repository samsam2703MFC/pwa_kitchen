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

// ── Séparation matin / PDM ─────────────────────────────────────────────────
$flags = [
    301 => ['pdm' => true,  'shelf_minutes' => 240, 'reheat_minutes' => 10, 'reheat_celsius' => 180],
    302 => ['pdm' => false, 'shelf_minutes' => null, 'reheat_minutes' => null, 'reheat_celsius' => null],
];
$mix = [
    ['product_id' => 301, 'name' => 'Quiche',    'ecart' => 5.0],
    ['product_id' => 302, 'name' => 'Baguette',  'ecart' => 36.0],
    ['product_id' => 303, 'name' => 'Cake',      'ecart' => 2.0],   // absent du catalogue
];
$split = $svc->splitByPdm($mix, $flags);
check('PDM : produit coché part la veille', array_column($split['pdm'], 'product_id'), [301]);
check('PDM : le reste part au matin',       array_column($split['morning'], 'product_id'), [302, 303]);
check('PDM : drapeau absent = matin',       count($split['morning']), 2);
check('PDM : rien ne se perd', count($split['pdm']) + count($split['morning']), count($mix));

// ── File de recuisson ──────────────────────────────────────────────────────
$rq = $svc->reheatQueue([
    ['product_id' => 301, 'name' => 'Quiche',   'ecart' => 5.0],
    ['product_id' => 302, 'name' => 'Baguette', 'ecart' => 36.0],
    ['product_id' => 304, 'name' => 'Avance',   'ecart' => -3.0],  // en avance : hors file
    ['product_id' => 305, 'name' => 'Inconnu',  'ecart' => null],  // sans prévision : hors file
], $flags);
check('recuisson : manques seuls, urgents en tête', array_column($rq, 'product_id'), [302, 301]);
check('recuisson : reheat du catalogue accroché', $rq[1]['reheat_minutes'], 10);
check('recuisson : température accrochée',        $rq[1]['reheat_celsius'], 180);
check('recuisson : produit hors catalogue = sans reheat', $rq[0]['reheat_minutes'], null);
$rqEq = $svc->reheatQueue([
    ['product_id' => 1, 'name' => 'Zeta', 'ecart' => 4.0],
    ['product_id' => 2, 'name' => 'Alpha', 'ecart' => 4.0],
], []);
check('recuisson : à écart égal, ordre alphabétique', array_column($rqEq, 'name'), ['Alpha', 'Zeta']);

// ── Candidats vente flash ──────────────────────────────────────────────────
$fc = $svc->flashCandidates([
    ['product_id' => 1, 'name' => 'FlipFlap', 'ecart' => 14.0, 'shelf_minutes' => 720],
    ['product_id' => 2, 'name' => 'Pain',     'ecart' => 36.0, 'shelf_minutes' => 1444], // tenue longue : hors liste
    ['product_id' => 3, 'name' => 'Wrap',     'ecart' => 5.0,  'shelf_minutes' => 360],
    ['product_id' => 4, 'name' => 'Soupe',    'ecart' => -2.0, 'shelf_minutes' => 240],  // en avance : hors liste
    ['product_id' => 5, 'name' => 'Cake',     'ecart' => 9.0,  'shelf_minutes' => null], // tenue inconnue : hors liste
]);
check('flash : tenue courte et manque seuls', array_column($fc, 'product_id'), [3, 1]);
check('flash : la tenue la plus courte en tête', $fc[0]['shelf_minutes'], 360);
check('flash : rien → liste vide', $svc->flashCandidates([]), []);

// ── Chronologie de gamme (heures de fin, fournées) ─────────────────────────
// La gamme réelle de la baguette : 5 s ; four 720 s ; ressuage 60 s.
$tl = $svc->ovenTimeline([
    ['seconds' => 5,   'oven' => false],
    ['seconds' => 720, 'oven' => true],
    ['seconds' => 60,  'oven' => false],
], 2);
check('chrono : fins d\'étapes cumulées', $tl['step_ends'], [5, 725, 785]);
check('chrono : fournée 1 sort après la gamme entière', $tl['batch_ready'][0], 785);
check('chrono : fournée 2 = un tour de four de plus', $tl['batch_ready'][1], 785 + 720);
$tl2 = $svc->ovenTimeline([['seconds' => 300, 'oven' => false]], 3);
check('chrono : sans four, une seule sortie', $tl2['batch_ready'], [300]);
$tl3 = $svc->ovenTimeline([
    ['seconds' => null, 'oven' => false],
    ['seconds' => 600,  'oven' => true],
], 1);
check('chrono : durée inconnue → fins inconnues ensuite', $tl3['step_ends'], [null, null]);

// ── Volumes du jour ────────────────────────────────────────────────────────
$vol = $svc->volumesOfDay(
    [   // toutes les lignes du jour
        ['prevu' => 44.0, 'vendu' => 8.0,  'ecart' => 36.0],
        ['prevu' => 20.0, 'vendu' => 25.0, 'ecart' => -5.0],
        ['prevu' => null, 'vendu' => 3.0,  'ecart' => null],
    ],
    [   // la file enrichie
        ['ecart' => 36.0, 'fournees' => 2, 'prep' => ['oven_capacity' => 30]],
        ['ecart' => 4.0,  'fournees' => null, 'prep' => null],
    ]
);
check('volumes : prévu additionne les connus', $vol['prevu'], 64.0);
check('volumes : vendu additionne tout',       $vol['vendu'], 36.0);
check('volumes : % réalisé arrondi',           $vol['pct'], 56);
check('volumes : reste = manques de la file',  $vol['reste'], 40.0);
check('volumes : fournées comptées',           $vol['fournees'], 2);
check('volumes : pièces = fournées × capacité', $vol['pieces'], 60.0);
check('volumes : sans prévision, pas de %',    $svc->volumesOfDay([['prevu' => null, 'vendu' => 2.0, 'ecart' => null]], [])['pct'], null);

// ── Commandes appliquées au jour ───────────────────────────────────────────
$withRes = $svc->applyReservations([
    ['product_id' => 501, 'name' => 'Sandwich', 'section' => 's', 'prevu' => 21.0, 'vendu' => 9.0, 'ecart' => 12.0],
    ['product_id' => 502, 'name' => 'Baguette', 'section' => 's', 'prevu' => 44.0, 'vendu' => 8.0, 'ecart' => 36.0],
    ['product_id' => 503, 'name' => 'Sans prévision', 'section' => 's', 'prevu' => null, 'vendu' => 0.0, 'ecart' => null],
], [
    ['id' => 501, 'name' => 'Sandwich', 'qty' => 15.0],   // commandes > écart
    ['id' => 502, 'name' => 'Baguette', 'qty' => 2.0],    // commandes < écart
    ['id' => 999, 'name' => 'Plateau traiteur', 'qty' => 4.0],  // inconnu de la prévision
]);
$byId = array_column($withRes, null, 'product_id');
check('réservation : commandes > prévision → besoin = commandes', $byId[501]['need'], 15.0);
check('réservation : vente libre plancher zéro', $byId[501]['libre'], 0.0);
check('réservation : commandes < prévision → besoin = écart', $byId[502]['need'], 36.0);
check('réservation : libre = écart − réservé', $byId[502]['libre'], 34.0);
check('réservation : sans commande, besoin = écart (null compris)', $byId[503]['need'], null);
check('réservation : produit commandé inconnu entre en liste', $byId[999]['need'], 4.0);
check('réservation : l\'inconnu porte sa commande', $byId[999]['reserved'], 4.0);

// ── Préparation du soir : prévision + commandes fermes ─────────────────────
$prep = $svc->tomorrowPrep(
    [
        ['product_id' => 601, 'name' => 'Cerise', 'section' => '', 'prevu' => 3.0, 'vendu' => 0.0, 'ecart' => 3.0],
        ['product_id' => 602, 'name' => 'Spaghetti', 'section' => '', 'prevu' => 2.0, 'vendu' => 0.0, 'ecart' => 2.0],
    ],
    [
        ['id' => 601, 'name' => 'Cerise', 'qty' => 8.0],     // commande > prévision
        ['id' => 603, 'name' => 'Cannelloni', 'qty' => 5.0], // PDM commandé, hors prévision
        ['id' => 604, 'name' => 'Pain', 'qty' => 20.0],      // PAS PDM : n'entre pas
    ],
    [
        601 => ['pdm' => true], 602 => ['pdm' => true],
        603 => ['pdm' => true], 604 => ['pdm' => false],
    ]
);
check('soir : besoin = max(prévision, commande)', array_column($prep, 'need'),  [8.0, 5.0, 2.0]);
check('soir : ordre = besoin décroissant', array_column($prep, 'product_id'), [601, 603, 602]);
check('soir : le non-PDM commandé reste dehors', in_array(604, array_column($prep, 'product_id'), true), false);

// ── Classes de conservation ────────────────────────────────────────────────
check('classe : 12 h pile = courte',  \App\Kitchen\app\Services\Production\Period1Service::shelfClass(720), 'courte');
check('classe : 24 h = moyenne',      \App\Kitchen\app\Services\Production\Period1Service::shelfClass(1444), 'moyenne');
check('classe : 3 jours = longue',    \App\Kitchen\app\Services\Production\Period1Service::shelfClass(4320), 'longue');
check('classe : inconnue reste null', \App\Kitchen\app\Services\Production\Period1Service::shelfClass(null), null);

// ── Vue stock ──────────────────────────────────────────────────────────────
$sp = $svc->stockPanels(
    [
        701 => ['name' => 'Baguette',  'shelf_minutes' => 1444],
        702 => ['name' => 'FlipFlap',  'shelf_minutes' => 720],
        703 => ['name' => 'Conserve',  'shelf_minutes' => 100000],
        704 => ['name' => 'Mystère',   'shelf_minutes' => null],
    ],
    [701 => 'Boulangerie', 702 => 'Traiteur', 703 => 'Épicerie'],   // 704 jamais vendu
    [701 => ['vendu' => 8.0, 'ecart' => 36.0, 'reserved' => 2.0]]
);
$names = array_map(static fn($p) => $p['section'], $sp);
check('stock : secteurs triés, Autres en dernier', $names, ['Boulangerie', 'Traiteur', 'Épicerie', '']);
check('stock : le jour s\'accroche à la ligne', $sp[0]['rows'][0]['need'], 36.0);
check('stock : compteur par classe', $sp[0]['counts']['moyenne'] ?? 0, 1);
check('stock : classe portée par la ligne', $sp[1]['rows'][0]['class'], 'courte');
check('stock : l\'inconnu garde une classe nulle', $sp[3]['rows'][0]['class'], null);

// ── Plan par four ──────────────────────────────────────────────────────────
$plan = $svc->ovenPlan([
    ['name' => 'Baguette', 'ecart' => 36.0, 'need' => 36.0,
     'prep' => ['steps' => [['oven' => true, 'batch_group' => 'Boulangerie', 'batch_capacity' => 30]]]],
    ['name' => 'Pistolet', 'ecart' => 10.0, 'need' => 10.0,
     'prep' => ['steps' => [['oven' => true, 'batch_group' => 'Boulangerie', 'batch_capacity' => 30]]]],
    ['name' => 'Quiche',   'ecart' => 5.0,  'need' => 5.0,
     'prep' => ['steps' => [['oven' => true, 'batch_group' => 'Four 180°', 'batch_capacity' => 12]]]],
    ['name' => 'Sans gamme', 'ecart' => 9.0, 'need' => 9.0, 'prep' => null],
]);
check('four : les groupes partagés se réunissent', count($plan), 2);
check('four : le plus chargé en tête', $plan[0]['group'], 'Boulangerie');
check('four : fournée mixte remplie par urgence', array_column($plan[0]['items'], 'take'), [30.0, 0.0]);
check('four : le total et les fournées', [$plan[0]['total'], $plan[0]['fournees']], [46.0, 2]);
check('four : sans gamme, hors plan', in_array('Sans gamme', array_merge(...array_map(fn($g) => array_column($g['items'], 'name'), $plan)), true), false);

// ── Bilan du jour (part servie) ────────────────────────────────────────────
$base = $svc->dayReportBase([
    ['prevu' => 44.0, 'vendu' => 8.0], ['prevu' => null, 'vendu' => 3.0],
]);
check('bilan : vendu additionne tout', $base['vendu'], 11.0);
check('bilan : prévu additionne les connus', $base['prevu'], 44.0);

// ── Rien à assembler ────────────────────────────────────────────────────────
check('aucun produit → aucune ligne', $svc->rows([], [], [], $C), []);
check('aucun produit → rien à produire', $svc->toProduce([])['total'], 0.0);

if ($ko) { echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), $ok passées\n"; exit(1); }
echo "✓ $ok vérifications passées\n";
