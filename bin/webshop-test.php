<?php
/**
 * Vérifie WebShopService sans serveur, sans réseau, sans navigateur.
 *
 *     php bin/webshop-test.php
 *
 * Trois règles décident des écrans de comptoir : quel geste propose une
 * commande, dans quel ordre elles se lisent, et ce qu'on affiche quand une
 * quantité n'est pas servie. Une erreur ici ne se voit pas — elle se voit au
 * comptoir, quand quelqu'un prépare deux fois la même commande.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\WebShop\WebShopService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$s = new WebShopService();

// ── Le geste suivant ────────────────────────────────────────────────────────
// Le bouton porte l'étape SUIVANTE, pas l'état actuel : au comptoir on
// n'affiche pas un statut, on avance.
check('payée → à préparer',        $s->nextStatus('pending'), 'preparing');
check('confirmée → à préparer',    $s->nextStatus('confirmed'), 'preparing');
check('en préparation → prête',    $s->nextStatus('preparing'), 'ready');
check('prête → remise',            $s->nextStatus('ready'), 'delivered');
check('remise : plus rien',        $s->nextStatus('delivered'), null);
check('annulée : plus rien',       $s->nextStatus('cancelled'), null);
check('statut inconnu : plus rien', $s->nextStatus('zzz'), null);

// ── Les trois groupes ───────────────────────────────────────────────────────
check('pending → à faire',         $s->bucket('pending'), WebShopService::TODO);
check('preparing → à faire',       $s->bucket('preparing'), WebShopService::TODO);
check('ready → prête',             $s->bucket('ready'), WebShopService::READY);
check('delivered → finie',         $s->bucket('delivered'), WebShopService::DONE);

// ── L'ordre de lecture ──────────────────────────────────────────────────────
// Ce qui reste à faire en haut, ce qui est parti en bas : un comptoir lit du
// haut vers le bas et s'arrête dès que les lignes ne le concernent plus.
$raw = [
    ['id' => '3', 'ref' => '#3', 'client' => 'C', 'slot' => '14:00', 'statusKey' => 'delivered'],
    ['id' => '1', 'ref' => '#1', 'client' => 'A', 'slot' => '12:00', 'statusKey' => 'ready'],
    ['id' => '2', 'ref' => '#2', 'client' => 'B', 'slot' => '08:00', 'statusKey' => 'pending'],
    ['id' => '4', 'ref' => '#4', 'client' => 'D', 'slot' => '09:00', 'statusKey' => 'preparing'],
];
$rows = $s->orders($raw);
check('à faire, puis prêtes, puis finies',
    array_column($rows, 'id'), ['2', '4', '1', '3']);
check('le créneau départage',      $rows[0]['slot'], '08:00');
check('le geste suit le statut',   $rows[0]['next'], 'preparing');
check('une finie n\'appelle rien', $rows[3]['next'], null);

// La pastille ne compte que ce qui reste : une commande remise n'est plus du
// travail, et la laisser dans le compte ferait croire à un retard.
check('reste à faire',             $s->countTodo($rows), 3);
check('rien à faire sur liste vide', $s->countTodo([]), 0);
check('rien à faire sur null',     $s->countTodo(null), 0);

// « Pas servi » n'est pas « aucune commande » : l'écran écrit la différence.
check('null reste null',           $s->orders(null), null);
check('liste vide reste vide',     $s->orders([]), []);

// ── Le stock ────────────────────────────────────────────────────────────────
$cat = [
    ['cat' => 'Pains', 'produits' => [
        ['nom' => 'Baguette', 'stock' => 12],
        ['nom' => 'Céréales', 'stock' => 0],
        ['nom' => 'Seigle'],                       // quantité non servie
    ]],
    ['cat' => 'Viennoiserie', 'produits' => [
        ['nom' => 'Croissant', 'qty' => 4],        // autre graphie
    ]],
];
$st = $s->stock($cat);
check('catalogue aplati',          count($st), 4);
check('la catégorie suit',         $st[0]['cat'], 'Pains');
check('quantité lue',              $st[0]['qty'], 12.0);
check('zéro = rupture',            $st[1]['out'], true);
// Zéro est une décision, l'absence une ignorance. Les confondre ferait
// annoncer une rupture sur un produit dont on ne sait rien.
check('non servi ≠ zéro',          $st[2]['qty'], null);
check('non servi ≠ rupture',       $st[2]['out'], false);
check('autre graphie acceptée',    $st[3]['qty'], 4.0);
check('ruptures comptées',         $s->countOut($st), 1);
check('stock null reste null',     $s->stock(null), null);

// ── Verdict ─────────────────────────────────────────────────────────────────
echo $ko === []
    ? "✓ {$ok} vérifications passées\n"
    : sprintf("%d passées, %d échouées :\n%s\n", $ok, count($ko), implode("\n", $ko));
exit($ko === [] ? 0 : 1);
