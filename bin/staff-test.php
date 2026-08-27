<?php
/**
 * Vérifie le croisement employés × planning, sans serveur ni réseau.
 *
 *     php bin/staff-test.php
 *
 * Ce croisement décide qui peut signer une tâche. Ce qui doit tenir ici n'est
 * pas le cas nominal — c'est qu'on n'écarte JAMAIS quelqu'un sur une absence
 * d'information, et qu'un identifiant rendu tantôt en nombre tantôt en chaîne
 * ne fasse pas disparaître la moitié de l'équipe. Une faute à cet endroit se
 * voit le jour où personne ne peut plus valider l'ouverture du magasin.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Staff\StaffService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$ids = fn(array $rows) => array_column($rows, 'id');

// ── Qui est actif ───────────────────────────────────────────────────────────
check('is_active respecté', $ids(StaffService::activeOnly([
    ['id' => 1, 'is_active' => true],
    ['id' => 2, 'is_active' => false],
])), [1]);

check('« 0 » et « 1 » en chaînes', $ids(StaffService::activeOnly([
    ['id' => 1, 'is_active' => '1'],
    ['id' => 2, 'is_active' => '0'],
])), [1]);

check('active / enabled acceptés', $ids(StaffService::activeOnly([
    ['id' => 1, 'active'  => true],
    ['id' => 2, 'enabled' => false],
])), [1]);

check('deleted_at écarte', $ids(StaffService::activeOnly([
    ['id' => 1],
    ['id' => 2, 'deleted_at' => '2026-01-04'],
    ['id' => 3, 'archived_at' => '2026-01-04'],
])), [1]);

check('status texte', $ids(StaffService::activeOnly([
    ['id' => 1, 'status' => 'ACTIVE'],
    ['id' => 2, 'status' => 'inactive'],
    ['id' => 3, 'status' => 'Archived'],
])), [1]);

// Le point le plus important : sans indicateur, on GARDE. Écarter sur une
// absence d'information viderait la liste le jour où le champ change de nom.
check('sans indicateur → gardé', $ids(StaffService::activeOnly([
    ['id' => 1],
    ['id' => 2, 'name' => 'Nathan'],
])), [1, 2]);

check('champ vide → gardé', $ids(StaffService::activeOnly([
    ['id' => 1, 'is_active' => ''],
    ['id' => 2, 'is_active' => null],
])), [1, 2]);

check('liste vide', StaffService::activeOnly([]), []);

// ── Qui est au planning ─────────────────────────────────────────────────────
// Revision du 13/08/2026 : une seule source. /franchisee-employees a ete
// retire — le planning porte les personnes, il n'y a plus rien a croiser.
$J = '2026-08-06';
$noms = fn(array $rows) => array_map(fn($r) => $r['id'] . ':' . $r['name'], $rows);

check('à plat', $noms(StaffService::peopleOf([
    ['employee_id' => 11, 'name' => 'Nathan Colin'],
    ['employee_id' => 12, 'name' => 'Aïcha Benali'],
], $J)), ['11:Nathan Colin', '12:Aïcha Benali']);

check('fiche imbriquée', $noms(StaffService::peopleOf([
    ['id' => 901, 'employee' => ['id' => 31, 'name' => 'Marek Kowalski']],
], $J)), ['31:Marek Kowalski']);

// L'« id » d'une ligne de planning est celui du SERVICE, pas de la personne :
// le lire comme un employe attribuerait la tache au mauvais identifiant.
check("l'id du service n'est pas celui de l'employé", $noms(StaffService::peopleOf([
    ['id' => 901, 'employee_id' => 31, 'name' => 'Marek'],
], $J)), ['31:Marek']);

check('les autres noms de champ', $noms(StaffService::peopleOf([
    ['franchisee_employee_id' => 21, 'name' => 'A'],
    ['id_employee' => 22, 'name' => 'B'],
    ['id_franchisee_employee' => 23, 'name' => 'C'],
    ['user_id' => 24, 'name' => 'D'],
], $J)), ['21:A', '22:B', '23:C', '24:D']);

check('prénom + nom recomposés', $noms(StaffService::peopleOf([
    ['employee_id' => 41, 'first_name' => 'Sofia', 'last_name' => 'Ferreira'],
    ['employee_id' => 42, 'prenom' => 'Ali'],
], $J)), ['41:Sofia Ferreira', '42:Ali']);

check('full_name / display_name', $noms(StaffService::peopleOf([
    ['employee_id' => 51, 'full_name' => 'X Y'],
    ['employee_id' => 52, 'display_name' => 'Z W'],
], $J)), ['51:X Y', '52:Z W']);

check('initiales calculées', StaffService::peopleOf([
    ['employee_id' => 61, 'name' => 'Nathan Colin'],
], $J)[0]['initials'], 'NC');

// Deux services dans la journee : une seule personne. Sans dedoublonnage, elle
// apparait deux fois et on doute d'avoir touche le bon badge.
check('deux services → une personne', $noms(StaffService::peopleOf([
    ['employee_id' => 71, 'name' => 'Ali', 'start' => '06:00'],
    ['employee_id' => 71, 'name' => 'Ali', 'start' => '14:00'],
], $J)), ['71:Ali']);
check('nombre et chaîne : la même personne', $noms(StaffService::peopleOf([
    ['employee_id' => 71, 'name' => 'Ali'],
    ['employee_id' => '71', 'name' => 'Ali'],
], $J)), ['71:Ali']);

// Une ligne d'un autre jour ferait signer quelqu'un qui n'etait pas la.
check("lignes d'un autre jour écartées", $noms(StaffService::peopleOf([
    ['employee_id' => 81, 'name' => 'A', 'date' => $J],
    ['employee_id' => 82, 'name' => 'B', 'date' => '2026-08-05'],
], $J)), ['81:A']);
check('date horodatée acceptée', $noms(StaffService::peopleOf([
    ['employee_id' => 83, 'name' => 'A', 'date' => $J . ' 06:00:00'],
], $J)), ['83:A']);
check('autres noms de date', $noms(StaffService::peopleOf([
    ['employee_id' => 84, 'name' => 'A', 'work_date' => $J],
    ['employee_id' => 85, 'name' => 'B', 'day' => '2026-08-05'],
], $J)), ['84:A']);
check('ligne sans date gardée', $noms(StaffService::peopleOf([
    ['employee_id' => 86, 'name' => 'A'],
], $J)), ['86:A']);

// Un badge sans nom ne se choisit pas : signer sous « #47 » ne vaut pas mieux
// que ne pas signer.
check('sans nom → écartée', StaffService::peopleOf([
    ['employee_id' => 91],
], $J), []);
check('sans identifiant → écartée', StaffService::peopleOf([
    ['name' => 'Sans id'],
], $J), []);
check('lignes illisibles ignorées', $noms(StaffService::peopleOf([
    'bruit', ['rien' => 1], ['employee_id' => 92, 'name' => 'A'],
], $J)), ['92:A']);

// Une fiche explicitement desactivee ne signe pas, meme inscrite au planning.
check('fiche désactivée écartée', $noms(StaffService::peopleOf([
    ['employee' => ['id' => 93, 'name' => 'Parti', 'is_active' => false]],
    ['employee' => ['id' => 94, 'name' => 'Présent']],
], $J)), ['94:Présent']);

check('planning vide', StaffService::peopleOf([], $J), []);

// ── Le poste : source unique = l'endpoint /employees/{id}/positions ──────────
use App\Kitchen\app\Repositories\Staff\StaffPositionRepository;

// pick() choisit le poste a montrer parmi ceux d'une personne : plus bas
// level_order d'abord (le metier de base).
check('pick : plus bas niveau', StaffPositionRepository::pick([
    ['name' => 'Chef', 'level_order' => 3],
    ['name' => 'Boulanger', 'level_order' => 1],
]), 'Boulanger');
check('pick : sans ordre → premier', StaffPositionRepository::pick([
    ['name' => 'Vente'], ['name' => 'Four'],
]), 'Vente');
check('pick : nom vide ignore', StaffPositionRepository::pick([
    ['name' => '', 'level_order' => 1], ['name' => 'Traiteur', 'level_order' => 2],
]), 'Traiteur');
check('pick : rien d exploitable → null', StaffPositionRepository::pick([['id' => 1]]), null);
check('pick : liste vide → null', StaffPositionRepository::pick([]), null);

// withPositions() applique le poste a chaque personne via un lookup injecte —
// aucun reseau. Un poste null laisse la carte sans sous-titre (chaine vide).
$people = [
    ['id' => 11, 'name' => 'Nathan'],
    ['id' => 12, 'name' => 'Marek'],
];
$lookup = fn(int $id) => $id === 11 ? 'Boulanger' : null;
$enrichis = StaffService::withPositions($people, $lookup);
check('withPositions : poste applique', $enrichis[0]['role'], 'Boulanger');
check('withPositions : sans poste → vide', $enrichis[1]['role'], '');

// ── Ce que l'écran en fait ──────────────────────────────────────────────────
// Revision du 13/08/2026 : plus de repli. Si une route ne repond pas, on ne
// propose PERSONNE et l'ecran nomme la route. On rendait auparavant toute
// l'equipe « pour ne pas bloquer » : confortable, et trompeur — un trou passait
// pour un fonctionnement normal, et le back n'etait jamais reclame.
$s = new StaffService(new class extends \App\Kitchen\app\Repositories\Staff\StaffRepository {
    public function __construct() {}
});

$equipe = [
    ['id' => 1, 'name' => 'A', 'initials' => 'A', 'on_schedule' => true],
    ['id' => 2, 'name' => 'B', 'initials' => 'B', 'on_schedule' => false],
];
check('planning connu → filtré',   $ids($s->roster($equipe)['list']), [1]);
check('planning connu → mode',     $s->roster($equipe)['mode'], 'scheduled');
check('planning connu → rien à créer', $s->roster($equipe)['missing'], null);

/* Planning servi et vide : ce n'est PAS une panne, c'est une reponse. Personne
   n'est de service ce jour-la, et l'ecran le dit dans ces mots — pas dans ceux
   d'une API manquante. La distinction compte : l'une se regle au back-office,
   l'autre chez le developpeur. */
$vide = [
    ['id' => 1, 'name' => 'A', 'initials' => 'A', 'on_schedule' => false],
    ['id' => 2, 'name' => 'B', 'initials' => 'B', 'on_schedule' => false],
];
check('planning vide → personne',  $s->roster($vide)['list'], []);
check('planning vide → mode',      $s->roster($vide)['mode'], 'empty');
check('planning vide → rien à créer', $s->roster($vide)['missing'], null);

check('aucun employé → vide',      $s->roster([])['list'], []);
check('aucun employé → mode',      $s->roster([])['mode'], 'none');
check('liste absente → vide',      $s->roster(null)['list'], []);
check('liste absente → mode',      $s->roster(null)['mode'], 'none');

// Rien n'a ete appele : aucune route n'est signalee a tort.
check('rien à signaler au départ', $s->missingApi(), null);

// ── Verdict ────────────────────────────────────────────────────────────────
if ($ko) {
    echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), $ok passées\n";
    exit(1);
}
echo "✓ $ok vérifications passées\n";
