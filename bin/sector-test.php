<?php
/**
 * Vérifie SectorService, OrderBookService et MinimumService sans serveur ni
 * réseau.
 *
 *     php bin/sector-test.php
 *
 * Trois règles décident des nouveaux écrans : ce qu'un secteur laisse passer,
 * ce qu'une commande ajoute au manque, et quand un plancher de vitrine est
 * franchi. Les trois se testent ici.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Models\Production\OrderLineModel;
use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\StockLineModel;
use App\Kitchen\app\Services\Production\ForecastService;
use App\Kitchen\app\Services\Production\MinimumService;
use App\Kitchen\app\Services\Production\OrderBookService;
use App\Kitchen\app\Services\Production\SectorService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

// ── Le catalogue d'essai ──────────────────────────────────────────────────
// Deux secteurs, un produit sans secteur déclaré, un inactif : ce sont les
// quatre cas qui décident du sélecteur.
$products = array_map(fn($d) => new ProductionProductModel($d), [
    ['id_product' => 1, 'name' => 'Baguette', 'category_name' => 'Boulangerie', 'periods' => ['morning', 'noon', 'afternoon'],
     'batch_size' => 12, 'production_lead_minutes' => 20, 'sector' => 'bakery', 'sector_name' => 'Boulangerie',
     'is_pdm' => true, 'pdm_minimums' => ['morning' => 24, 'noon' => 24, 'afternoon' => 12]],
    ['id_product' => 2, 'name' => 'Croissant', 'category_name' => 'Viennoiserie', 'periods' => ['morning'],
     'batch_size' => 24, 'sector' => 'bakery', 'sector_name' => 'Boulangerie',
     'is_pdm' => true, 'pdm_min' => 48],
    ['id_product' => 3, 'name' => 'Sandwich', 'category_name' => 'Sandwichs', 'periods' => ['noon'],
     'batch_size' => 10, 'sector' => 'catering', 'sector_name' => 'Traiteur',
     'is_pdm' => true, 'pdm_minimums' => ['noon' => 20]],
    ['id_product' => 4, 'name' => 'Plateau', 'category_name' => 'Traiteur chaud', 'periods' => ['afternoon'],
     'batch_size' => 2, 'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => false],
    // Sans secteur : ne crée pas de rubrique « Autre », et reste visible partout.
    ['id_product' => 5, 'name' => 'Café', 'category_name' => 'Boissons', 'periods' => ['morning']],
    // Inactif : ne compte pas dans le sélecteur.
    ['id_product' => 6, 'name' => 'Bûche', 'category_name' => 'Pâtisserie', 'periods' => ['afternoon'],
     'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_active' => false, 'is_pdm' => true],
]);

// ── Le modèle ─────────────────────────────────────────────────────────────
$byId = [];
foreach ($products as $p) { $byId[$p->getIdProduct()] = $p; }

check('secteur lu',                       $byId[1]->getSector(), 'bakery');
check('libellé de secteur',               $byId[3]->getSectorName(), 'Traiteur');
check('sans secteur : null',              $byId[5]->getSector(), null);
check('sans secteur : dans tout secteur', $byId[5]->inSector('bakery'), false);
check('sans filtre : tout passe',         $byId[3]->inSector(null), true);

check('minimum par période',              $byId[1]->getMinimum('noon'), 24.0);
check('minimum : la période choisie',     $byId[1]->getMinimum('afternoon'), 12.0);
// Une valeur unique vaut pour toutes les périodes : c'est ce que le back-office
// enverra pour un produit qu'on tient pareil toute la journée.
check('minimum global : vaut partout',    $byId[2]->getMinimum('afternoon'), 48.0);
// Non déclaré n'est PAS zéro : zéro est une décision, null une ignorance.
check('minimum absent : null, pas zéro',  $byId[3]->getMinimum('morning'), null);
check('is_pdm par défaut : false',        $byId[5]->isPdm(), false);
check('is_pdm lu',                        $byId[4]->isPdm(), false);

// ── Les secteurs ──────────────────────────────────────────────────────────
$sec = new SectorService();
$sectors = $sec->sectors($products);

check('deux secteurs déclarés',           count($sectors), 2);
check('triés par libellé',                array_map(fn($s) => $s['key'], $sectors), ['bakery', 'catering']);
// Bûche est inactive : elle ne gonfle pas le compteur de la boulangerie.
check('inactif non compté',               $sectors[0]['count'], 2);
check('traiteur : deux produits',         $sectors[1]['count'], 2);

check('secteur demandé connu',            $sec->resolve($sectors, 'catering'), 'catering');
check('secteur inconnu : tout',           $sec->resolve($sectors, 'poissonnerie'), null);
check('rien demandé : tout',              $sec->resolve($sectors, null), null);
check('casse ignorée',                    $sec->resolve($sectors, 'BAKERY'), 'bakery');

check('filtre : boulangerie',             array_map(fn($p) => $p->getIdProduct(), $sec->filter($products, 'bakery')), [1, 2, 6]);
check('filtre nul : tout le catalogue',   count($sec->filter($products, null)), 6);
check('ids du secteur',                   array_keys($sec->idsOf($products, 'catering')), [3, 4]);

// Ce qui n'a pas d'id de produit reste : le filtre est un confort de lecture,
// pas un contrôle d'accès. Faire disparaître une fournée coûterait plus cher.
$ids  = $sec->idsOf($products, 'catering');
$rows = [['id' => 3], ['id' => 1], ['id' => null], ['id' => 4]];
check('garde le secteur, et les orphelins',
    array_map(fn($r) => $r['id'], $sec->keepIn($rows, fn($r) => $r['id'], 'catering', $ids)),
    [3, null, 4]);

// ── Le carnet de commandes ────────────────────────────────────────────────
$book = new OrderBookService();
$orders = array_map(fn($d) => new OrderLineModel($d), [
    ['id_order' => 1, 'id_product' => 1, 'quantity' => 24, 'channel' => 'shop',           'due_time' => '08:00'],
    ['id_order' => 2, 'id_product' => 1, 'quantity' => 12, 'channel' => 'click_collect',  'due_time' => '10:30'],
    ['id_order' => 3, 'id_product' => 3, 'quantity' => 20, 'channel' => 'delivery',       'due_time' => '11:00'],
    ['id_order' => 4, 'id_product' => 3, 'quantity' => 6,  'channel' => 'inconnu',        'period' => 'noon'],
    ['id_order' => 5, 'id_product' => 2, 'quantity' => 0,  'channel' => 'shop',           'due_time' => '09:00'],
]);

check('canal livraison reconnu',          $orders[2]->getChannel(), OrderLineModel::CH_DELIVERY);
check('click & collect reconnu',          $orders[1]->getChannel(), OrderLineModel::CH_CLICK);
// Un canal mal étiqueté reste une commande à honorer : on la range au magasin
// plutôt que de la faire disparaître du calcul.
check('canal inconnu : magasin',          $orders[3]->getChannel(), OrderLineModel::CH_SHOP);
check('heure extraite du timestamp',
    (new OrderLineModel(['id_product' => 1, 'quantity' => 1, 'due_time' => '2026-08-01 14:05:00']))->getDueTime(),
    '14:05');
check('sans heure : due tout de suite',   $orders[3]->getDueMinutes(), 0);

$due = $book->dueBetween($orders, ForecastService::minutesOf('07:00'), ForecastService::minutesOf('11:00'));
check('fenêtre : les deux du matin',      $due[1] ?? null, 36.0);
// Borne haute exclue : 11 h 00 pile appartient à la période qui commence, pas à
// celle qui finit — sinon la commande serait comptée sur deux horizons.
check('borne haute exclue',               array_key_exists(3, $due), false);
check('quantité nulle ignorée',           array_key_exists(2, $due), false);

$noon = $book->dueInPeriod($orders, 'noon', ForecastService::minutesOf('11:00'), ForecastService::minutesOf('14:00'));
check('période : par heure',              $noon[3] ?? null, 26.0);

// La fenêtre suit le lead de chaque produit : la baguette demande 20 minutes,
// on regarde donc 20 minutes plus loin pour elle.
// Sans lead, la fenêtre s'arrêterait à 10 h 20 et raterait la commande de
// 10 h 30 ; les vingt minutes de la baguette la rattrapent.
$forProducts = $book->dueForProducts($orders, $products, ForecastService::minutesOf('08:00'), 140);
check('fenêtre + lead du produit',        $forProducts[1] ?? null, 36.0);
$court = $book->dueForProducts($orders, $products, ForecastService::minutesOf('08:00'), 100);
check('fenêtre courte : la 10 h 30 sort', $court[1] ?? null, 24.0);

$agg = $book->byProduct($orders);
check('total par produit',                $agg[1]['total'], 36.0);
check('ventilé par canal',                $agg[1]['channels'][OrderLineModel::CH_CLICK], 12.0);
check('prochaine échéance',               $agg[1]['next'], '08:00');
check('compte par canal',                 $book->counts($orders)['delivery'], 1);

// En retard d'abord, puis par échéance : à 10 h, la commande de 8 h passe
// devant celle de 10 h 30.
$up = $book->upcoming($orders, ForecastService::minutesOf('10:00'));
check('les retards en tête',              array_map(fn($o) => $o->getIdOrder(), $up), [4, 1, 2, 3]);

// ── Les minimums de vitrine ───────────────────────────────────────────────
$min = new MinimumService();
$periods = array_map(fn($p) => new PeriodModel($p), [
    ['key' => 'morning',   'start' => '05:00', 'end' => '11:00'],
    ['key' => 'noon',      'start' => '11:00', 'end' => '14:00'],
    ['key' => 'afternoon', 'start' => '14:00', 'end' => '19:00'],
]);
$stock = array_map(fn($d) => new StockLineModel($d), [
    ['id_product' => 1, 'name' => 'Baguette',  'quantity' => 10],
    ['id_product' => 2, 'name' => 'Croissant', 'quantity' => 90],
    ['id_product' => 3, 'name' => 'Sandwich',  'quantity' => 25],
]);

$rows = $min->rows($products, $stock, [], $periods, 'morning');
$byProduct = [];
foreach ($rows as $r) { $byProduct[$r['id_product']] = $r; }

// Seuls les is_pdm : Plateau et Café n'ont pas de plancher, ils se pilotent à
// la prévision et n'ont rien à faire dans ce tableau.
check('seuls les produits à plancher',    array_keys($byProduct), [1, 3, 2]);
check('les trois périodes en colonnes',   array_keys($byProduct[1]['minimums']), ['morning', 'noon', 'afternoon']);

check('sous le plancher',                 $byProduct[1]['level'], MinimumService::SHORT);
check('manque arrondi à la fournée',      $byProduct[1]['to_produce'], 24.0);  // il manque 14, fournée de 12
check('au-dessus du plancher',            $byProduct[2]['level'], MinimumService::OK);
check('rien à produire',                  $byProduct[2]['to_produce'], 0.0);
// Plancher non déclaré sur la période affichée : aucune conclusion honnête, et
// surtout pas « il en faut zéro ».
check('plancher absent : inconnu',        $byProduct[3]['level'], MinimumService::UNKNOWN);
check('plancher absent : rien à produire', $byProduct[3]['to_produce'], null);

// Ce qui est promis à un client n'est pas en vitrine : le plancher et les
// commandes s'additionnent.
$rows = $min->rows($products, $stock, [1 => 20.0], $periods, 'morning');
foreach ($rows as $r) { if ($r['id_product'] === 1) { $b = $r; } }
check('les commandes s\'ajoutent au plancher', $b['to_produce'], 36.0);  // 24 + 20 − 10 = 34 → 3 fournées
check('la commande est dite à part',      $b['ordered'], 20.0);

// À moins d'une fournée au-dessus : rien à lancer, mais on surveille.
$serre = $min->rows($products, [new StockLineModel(['id_product' => 1, 'quantity' => 30])], [], $periods, 'morning');
check('juste au-dessus : tendu',          $serre[0]['level'], MinimumService::TIGHT);

// Stock non servi : on ne compare rien, et on le dit. Un plancher comparé à un
// stock absent produirait un ordre inventé.
$sansStock = $min->rows($products, null, [], $periods, 'morning');
check('stock non servi : inconnu',        $sansStock[0]['level'], MinimumService::UNKNOWN);
check('stock non servi : pas de zéro',    $sansStock[0]['stock'], null);

$rows = $min->rows($products, $stock, [], $periods, 'noon');
check('la période choisie décide',        $rows[0]['target'], 24.0);
check('comptes par état',                 $min->counts($rows)['all'], 3);
check('catégories triées',                $min->categories($rows), ['Boulangerie', 'Sandwichs', 'Viennoiserie']);

// ── Verdict ───────────────────────────────────────────────────────────────
echo $ko === []
    ? "✓ {$ok} vérifications passées\n"
    : sprintf("%d passées, %d échouées :\n%s\n", $ok, count($ko), implode("\n", $ko));
exit($ko === [] ? 0 : 1);
