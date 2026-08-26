<?php
/**
 * Vérifie la lecture d'un parcours de préparation, sans serveur ni réseau.
 *
 *     php bin/preparation-test.php
 *
 * Ce qui doit tenir ici n'est pas le cas nominal. C'est qu'un produit SANS
 * parcours ne se confonde jamais avec une route qui ne répond pas — l'un se
 * règle au back-office, l'autre chez le développeur — et qu'un ordre d'étapes
 * ne soit jamais réinventé : exécuter les gestes dans le mauvais sens ne se
 * rattrape pas.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Knowledge\Preparation\PreparationPathService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$textes = fn(array $steps) => array_map(fn($s) => $s['n'] . ':' . $s['text'], $steps);
$S      = fn(array $rows) => PreparationPathService::steps(['steps' => $rows]);

// ── Ce qu'on lit ────────────────────────────────────────────────────────────
check('une étape', $textes($S([
    ['description' => 'Peser la farine', 'duration_seconds' => 90],
])), ['1:Peser la farine']);

check('la durée', $S([['description' => 'A', 'duration_seconds' => 90]])[0]['seconds'], 90);
check('durée absente', $S([['description' => 'A']])[0]['seconds'], null);

// La doc du back ne nomme ni le champ du texte ni celui de la durée : on
// accepte les orthographes plausibles plutot que d'afficher des etapes muettes.
check('autres noms du texte', $textes($S([
    ['instruction' => 'A'], ['step_description' => 'B'], ['text' => 'C'], ['label' => 'D'],
])), ['1:A', '2:B', '3:C', '4:D']);
check('autres noms de la durée', array_column($S([
    ['description' => 'A', 'duration_second' => 30],
    ['description' => 'B', 'duration' => 45],
]), 'seconds'), [30, 45]);

// ── L'ordre ─────────────────────────────────────────────────────────────────
// Le back a une route dediee pour le persister : on le respecte.
check('ordre servi respecté', $textes($S([
    ['description' => 'Petrir'], ['description' => 'Cuire'], ['description' => 'Refroidir'],
])), ['1:Petrir', '2:Cuire', '3:Refroidir']);

check('rang explicite respecté', $textes($S([
    ['description' => 'Cuire',   'position' => 2],
    ['description' => 'Petrir',  'position' => 1],
])), ['1:Petrir', '2:Cuire']);

check('rangs égaux → ordre servi', $textes($S([
    ['description' => 'A', 'position' => 1],
    ['description' => 'B', 'position' => 1],
])), ['1:A', '2:B']);

// ── Le four et le batch ─────────────────────────────────────────────────────
$four = $S([[
    'description' => 'Enfourner', 'duration_seconds' => 1200,
    'batch_group_id' => 7, 'batch_capacity' => 48,
    'products_per_tray' => 12, 'trays_per_oven' => 4,
]])[0];
check('four déduit des plaques', $four['oven'], true);
check('capacité de batch',       $four['batch_capacity'], 48);
check('pièces par plaque',       $four['per_tray'], 12);
check('plaques',                 $four['trays'], 4);
check('groupe sans nom → id',    $four['batch_group'], '#7');

check('nom du groupe préféré', $S([[
    'description' => 'A', 'batch_group_id' => 7, 'batch_group_name' => 'Fours 180°',
]])[0]['batch_group'], 'Fours 180°');
check('groupe imbriqué', $S([[
    'description' => 'A', 'batch_group' => ['id' => 7, 'name' => 'Pousse'],
]])[0]['batch_group'], 'Pousse');

check('drapeau explicite fait foi', $S([[
    'description' => 'A', 'uses_oven' => false, 'products_per_tray' => 12, 'trays_per_oven' => 4,
]])[0]['oven'], false);
// On ne conclut jamais « pas de four » d'une absence de drapeau, mais une
// etape sans four ni parametre reste une etape sans four.
check('ni drapeau ni paramètre', $S([['description' => 'A']])[0]['oven'], false);
check('étape non batchable', $S([['description' => 'A']])[0]['batch_group'], null);

// ── Les photos ──────────────────────────────────────────────────────────────
check('liste de clés', $S([['description' => 'A', 'photos' => ['a.jpg', 'b.jpg']]])[0]['photos'], ['a.jpg', 'b.jpg']);
check('objets photo',   $S([['description' => 'A', 'photos' => [['key' => 'a.jpg'], ['url' => 'b.jpg']]]])[0]['photos'], ['a.jpg', 'b.jpg']);
check('emplacements numérotés', $S([[
    'description' => 'A', 'image_key_1' => 'a.jpg', 'image_key_3' => 'c.jpg',
]])[0]['photos'], ['a.jpg', 'c.jpg']);
// Le back en garantit trois ; une quatrieme deborderait la rangee sans qu'on
// sache d'ou elle vient.
check('quatre photos → trois', $S([[
    'description' => 'A', 'photos' => ['a', 'b', 'c', 'd'],
]])[0]['photos'], ['a', 'b', 'c']);
check('sans photo', $S([['description' => 'A']])[0]['photos'], []);

// ── Ce qu'on écarte, et qu'on compte ────────────────────────────────────────
// Une etape sans consigne lisible n'est pas affichable : la montrer vide
// ferait croire a un geste sans instruction. Elle est comptee.
check('étape muette écartée', $textes($S([
    ['description' => 'A'], ['duration_seconds' => 60], ['description' => 'B'],
])), ['1:A', '2:B']);
check('étapes muettes comptées', PreparationPathService::unreadable(['steps' => [
    ['description' => 'A'], ['duration_seconds' => 60], ['rien' => 1],
]]), 2);
check('rien à signaler', PreparationPathService::unreadable(['steps' => [
    ['description' => 'A'],
]]), 0);
check('lignes illisibles ignorées', $textes(PreparationPathService::steps(['steps' => [
    'bruit', 42, ['description' => 'A'],
]])), ['1:A']);
check('la numérotation ne saute pas', array_column($S([
    ['rien' => 1], ['description' => 'A'], ['rien' => 1], ['description' => 'B'],
]), 'n'), [1, 2]);

// ── Où sont les étapes dans la réponse ──────────────────────────────────────
check('sous « steps »', $textes(PreparationPathService::steps(['steps' => [['description' => 'A']]])), ['1:A']);
check('sous « items »', $textes(PreparationPathService::steps(['items' => [['description' => 'A']]])), ['1:A']);
check('la liste elle-même', $textes(PreparationPathService::steps([['description' => 'A']])), ['1:A']);
check('emballage inconnu → rien', PreparationPathService::steps(['bidule' => [['description' => 'A']]]), []);

// ── Les trois états ─────────────────────────────────────────────────────────
// C'est le point de la revision : « ce produit n'a pas de parcours » et « la
// route ne repond pas » se ressemblent a l'affichage et n'appellent pas la
// meme reaction. L'un se regle au back-office, l'autre chez le developpeur.
$svc = fn(?array $reponse) => new PreparationPathService(
    new class($reponse) extends \App\Kitchen\app\Repositories\Knowledge\Preparation\PreparationPathRepository {
        public function __construct(private ?array $r) {}
        public function get(int $productId): ?array { return $this->r; }
    }
);

$s = $svc(['configured' => true, 'steps' => [['description' => 'A', 'duration_seconds' => 60]]]);
$r = $s->forProduct(12);
check('parcours servi → état',   $r['state'], 'served');
check('parcours servi → total',  $r['total_seconds'], 60);
check('parcours servi → rien à créer', $r['missing'], null);

$r = $svc(['configured' => false])->forProduct(12);
check('non configuré → état',    $r['state'], 'unconfigured');
check('non configuré → aucune étape', $r['steps'], []);
check('non configuré N’EST PAS une panne', $r['missing'], null);

// Configure, mais aucune etape encore saisie : ce n'est pas une panne non plus.
$r = $svc(['configured' => true, 'steps' => []])->forProduct(12);
check('configuré sans étape → état', $r['state'], 'unconfigured');
check('configuré sans étape → rien à créer', $r['missing'], null);

$s = $svc(null);
$r = $s->forProduct(12);
check('route muette → état',     $r['state'], 'missing');
check('route muette → aucune étape', $r['steps'], []);
check('route muette → route nommée', $r['missing'], 'GET /products/12/preparation-path');
check('route muette → missingApi()', $s->missingApi(), 'GET /products/12/preparation-path');

// Une reponse ni configured ni exploitable est un probleme d'API, pas un
// produit sans parcours : le dire evite de chercher au back-office ce qui se
// regle chez le developpeur.
$r = $svc(['bidule' => 1])->forProduct(12);
check('réponse informe → état', $r['state'], 'missing');
check('réponse informe → route nommée', str_contains((string)$r['missing'], 'réponse sans étape'), true);

$s = $svc(['configured' => true, 'steps' => [['description' => 'A']]]);
$s->forProduct(12);
check('rien à signaler après un succès', $s->missingApi(), null);

// ── Les durées lisibles ─────────────────────────────────────────────────────
check('secondes',        PreparationPathService::humanDuration(45), '45 s');
check('minutes rondes',  PreparationPathService::humanDuration(120), '2 min');
check('minutes et secondes', PreparationPathService::humanDuration(90), '1 min 30 s');
check('heures',          PreparationPathService::humanDuration(3900), '1 h 05');
// Une duree absente ne vaut pas zero : « 0 s » se lit comme un geste instantane.
check('durée absente',   PreparationPathService::humanDuration(null), null);
check('durée nulle',     PreparationPathService::humanDuration(0), null);

// ── Le résumé pour la production ────────────────────────────────────────────
// L'écran des besoins veut une ligne par produit a lancer : duree totale et
// capacite du four. Rien n'est demande aux produits que la route des
// identifiants ne declare pas configures, et rien n'est invente pour un
// parcours qui ne repond pas.
$svc2 = function (?array $ids, array $paths) {
    return new PreparationPathService(
        new class($ids, $paths) extends \App\Kitchen\app\Repositories\Knowledge\Preparation\PreparationPathRepository {
            public function __construct(private ?array $ids, private array $paths) {}
            public function configuredProductIds(): ?array { return $this->ids; }
            public function get(int $productId): ?array { return $this->paths[$productId] ?? null; }
        }
    );
};

$baguette = ['configured' => true, 'steps' => [
    ['description' => 'Frasage', 'duration_seconds' => 480],
    ['description' => 'Cuisson', 'duration_seconds' => 1320, 'uses_oven' => true,
     'batch_group_id' => 8, 'batch_capacity' => 80, 'products_per_tray' => 20, 'trays_per_oven' => 4],
]];

$r = $svc2([6700120], [6700120 => $baguette])->summaries([6700120, 999]);
check('résumé : servi',            $r['available'], true);
check('résumé : durée',            $r['map'][6700120]['total'], '30 min');
check('résumé : four',             $r['map'][6700120]['oven'], '20 × 4');
check('résumé : capacité',         $r['map'][6700120]['capacity'], 80);
check('résumé : le non-configuré est absent', array_key_exists(999, $r['map']), false);

// Un produit configure dont le parcours ne repond pas : absent de la map,
// jamais invente.
$r = $svc2([6700120], [])->summaries([6700120]);
check('parcours muet → absent',    $r['map'], []);
check('parcours muet → ids servis quand même', $r['available'], true);

// La route des identifiants ne repond pas : on le dit, et on ne demande RIEN.
$r = $svc2(null, [])->summaries([6700120]);
check('ids muets → indisponible',  $r['available'], false);
check('ids muets → route nommée',  $r['missing'], 'GET /preparation-paths/configured-product-ids');
check('ids muets → map vide',      $r['map'], []);

// Sans four, pas de capacite inventee.
$r = $svc2([5], [5 => ['configured' => true, 'steps' => [['description' => 'A', 'duration_seconds' => 60]]]])
    ->summaries([5]);
check('sans four → pas de capacité', $r['map'][5]['capacity'], null);
check('sans four → durée quand même', $r['map'][5]['total'], '1 min');

// ── Verdict ────────────────────────────────────────────────────────────────
if ($ko) {
    echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), $ok passées\n";
    exit(1);
}
echo "✓ $ok vérifications passées\n";
