<?php
/**
 * Vérifie ShiftService sans serveur, sans cookie, sans navigateur.
 *
 *     php bin/shift-test.php
 *
 * La prise de poste évite de retaper un PIN à chaque tâche. Ce qui doit tenir
 * ici n'est pas qu'un poste valide passe — c'est qu'un poste FORGÉ ou PÉRIMÉ
 * ne passe pas. Une faute à cet endroit ne se voit pas à l'écran : elle se voit
 * le jour où une tâche porte le nom de quelqu'un qui n'était pas là.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Checklist\ShiftService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$s   = new ShiftService();
$SEC = 'secret-de-test';
$T0  = 1754000000;

// ── Un poste ouvert ─────────────────────────────────────────────────────────
$tok = $s->sign(7, 'Nathan Lambert', $T0, $T0, $SEC);
$c   = $s->verify($tok, $T0, $SEC);
check('poste reconnu',              $c !== null, true);
check('la personne suit',           $c['id'], 7);
check('le nom suit',                $c['name'], 'Nathan Lambert');

// La prise de poste est valable pour une boutique et un jour précis. Un même
// navigateur ne doit pas conserver une signature après changement de magasin.
$scoped = $s->verify($s->sign(7, 'Nathan Lambert', $T0, $T0, $SEC, 12, '2026-08-21'), $T0, $SEC);
check('le magasin suit',            $scoped['shop_id'], 12);
check('le jour suit',               $scoped['work_date'], '2026-08-21');

// ── Le glissement ───────────────────────────────────────────────────────────
// Tant qu'on enchaîne les tâches, le poste reste ouvert.
check('29 min après : ouvert',      $s->verify($tok, $T0 + 1740, $SEC) !== null, true);
check('31 min après : refermé',     $s->verify($tok, $T0 + 1860, $SEC), null);

// Chaque tâche validée repousse l'échéance : c'est ce qui permet d'enchaîner
// une checklist longue sans jamais retaper le code.
$tok2 = $s->sign(7, 'Nathan Lambert', $T0, $T0 + 1500, $SEC);
check('geste à 25 min : repoussé',  $s->verify($tok2, $T0 + 3000, $SEC) !== null, true);

// ── Le plafond ──────────────────────────────────────────────────────────────
// Même en gestes continus, un poste ne dure pas plus qu'un service.
$tard = $s->sign(7, 'Nathan', $T0, $T0 + 8 * 3600 + 60, $SEC);
check('au-delà de 8 h : refermé',   $s->verify($tard, $T0 + 8 * 3600 + 60, $SEC), null);
$juste = $s->sign(7, 'Nathan', $T0, $T0 + 7 * 3600, $SEC);
check('à 7 h : encore ouvert',      $s->verify($juste, $T0 + 7 * 3600, $SEC) !== null, true);

// ── Ce qui doit être refusé ─────────────────────────────────────────────────
check('absent',                     $s->verify(null, $T0, $SEC), null);
check('vide',                       $s->verify('', $T0, $SEC), null);
check('sans point',                 $s->verify('nimportequoi', $T0, $SEC), null);

// Le cœur du sujet : sans le secret, on ne fabrique pas un poste. Sans quoi
// n'importe qui signerait sous le nom de n'importe qui.
check('signé avec un autre secret', $s->verify($tok, $T0, 'autre-secret'), null);

// Charge modifiée : on change l'employé, la signature ne suit pas.
[$body, $sig] = explode('.', $tok, 2);
$forge = rtrim(strtr(base64_encode(json_encode(['id'=>99,'n'=>'Intrus','o'=>$T0,'t'=>$T0])), '+/', '-_'), '=');
check('charge remplacée',           $s->verify($forge . '.' . $sig, $T0, $SEC), null);

// Une horloge reculée ne doit pas rallonger un poste.
check('daté du futur',              $s->verify($s->sign(7, 'N', $T0 + 500, $T0 + 500, $SEC), $T0, $SEC), null);
check('employé 0',                  $s->verify($s->sign(0, 'N', $T0, $T0, $SEC), $T0, $SEC), null);

// ── Ce que l'écran affiche ──────────────────────────────────────────────────
// Un poste qui se referme sans prévenir passerait pour une panne.
check('restant à l ouverture',      $s->remaining($c, $T0), 1800);
check('restant après 25 min',       $s->remaining($c, $T0 + 1500), 300);
check('jamais négatif',             $s->remaining($c, $T0 + 99999), 0);
$vieux = $s->verify($s->sign(7,'N',$T0, $T0 + 7*3600 + 3300, $SEC), $T0 + 7*3600 + 3300, $SEC);
check('le plafond peut primer',     $s->remaining($vieux, $T0 + 7 * 3600 + 3300), 300);

check('initiales',                  $s->initials('Nathan Lambert'), 'NL');
check('initiales, un seul mot',     $s->initials('Nathan'), 'N');
check('initiales, accents',         $s->initials('Élodie Renard'), 'ÉR');

echo $ko === []
    ? "✓ {$ok} vérifications passées\n"
    : sprintf("%d passées, %d échouées :\n%s\n", $ok, count($ko), implode("\n", $ko));
exit($ko === [] ? 0 : 1);
