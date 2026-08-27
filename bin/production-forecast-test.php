<?php
/**
 * Vérifie le moteur de prévision de production, sans serveur ni réseau.
 *
 *     php bin/production-forecast-test.php
 *
 * Ce qui doit tenir ici : la prévision suit EXACTEMENT les décisions du 27/08
 * — 6 semaines pondérées vers les récentes, même jour de semaine, même tranche,
 * ruptures exclues — et surtout, une absence d'historique rend « inconnu » (null)
 * et jamais zéro. Confondre les deux ferait produire à l'aveugle ou ne rien
 * produire par erreur.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Production\ProductionForecastService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    $eq = is_float($want) && is_float($got) ? abs($got - $want) < 1e-9 : $got === $want;
    if ($eq) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want), json_encode($got));
}

$svc = new ProductionForecastService();

// Le jour cible : jeudi 27 août 2026. Les jeudis précédents : 20, 13, 06 août,
// 30, 23, 16 juillet — soit k = 1..6 semaines en arrière.
$CIBLE = '2026-08-27';
$jeudi = fn(int $k) => date('Y-m-d', strtotime("$CIBLE -" . (7 * $k) . " days"));

// ── La pondération ───────────────────────────────────────────────────────────
// Une seule semaine d'historique : la prévision est cette valeur.
check('une semaine → sa valeur', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 40],
], $CIBLE), 40.0);

// Deux semaines égales : la moyenne est la valeur, quel que soit le poids.
check('deux semaines égales', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 40],
    ['date' => $jeudi(2), 'quantity' => 40],
], $CIBLE), 40.0);

// La semaine récente pèse plus. k=1 (poids 6) vaut 60, k=2 (poids 5) vaut 40 :
// (6·60 + 5·40) / (6+5) = 560/11 = 50.909…
check('récent pèse davantage', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 60],
    ['date' => $jeudi(2), 'quantity' => 40],
], $CIBLE), 560.0 / 11.0);

// Les six semaines pleines, poids 6..1. Σ poids = 21.
check('six semaines pondérées', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 10],
    ['date' => $jeudi(2), 'quantity' => 10],
    ['date' => $jeudi(3), 'quantity' => 10],
    ['date' => $jeudi(4), 'quantity' => 10],
    ['date' => $jeudi(5), 'quantity' => 10],
    ['date' => $jeudi(6), 'quantity' => 10],
], $CIBLE), 10.0);

// ── La fenêtre ────────────────────────────────────────────────────────────────
// Au-delà de N=6 semaines, l'échantillon est hors fenêtre : ignoré.
check('7e semaine hors fenêtre', $svc->forecastOne([
    ['date' => $jeudi(7), 'quantity' => 999],
    ['date' => $jeudi(1), 'quantity' => 20],
], $CIBLE), 20.0);

// N réglable : avec N=2, seules les deux dernières comptent.
check('N=2 borne la fenêtre', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 30],
    ['date' => $jeudi(2), 'quantity' => 30],
    ['date' => $jeudi(3), 'quantity' => 999],
], $CIBLE, 2), 30.0);

// N ≤ 0 → défaut (6).
check('N invalide → défaut', $svc->forecastOne([
    ['date' => $jeudi(6), 'quantity' => 15],
], $CIBLE, 0), 15.0);

// ── Le jour de semaine ────────────────────────────────────────────────────────
// Un mercredi (la veille) ne prévoit pas un jeudi, même récent.
check('autre jour de semaine ignoré', $svc->forecastOne([
    ['date' => date('Y-m-d', strtotime("$CIBLE -1 day")), 'quantity' => 999],
    ['date' => $jeudi(1), 'quantity' => 25],
], $CIBLE), 25.0);

// Le jour cible lui-même (k=0) ne se prévoit pas sur soi.
check('le jour cible est écarté', $svc->forecastOne([
    ['date' => $CIBLE, 'quantity' => 999],
    ['date' => $jeudi(1), 'quantity' => 18],
], $CIBLE), 18.0);

// Un jeudi FUTUR (la semaine prochaine) est écarté.
check('le futur est écarté', $svc->forecastOne([
    ['date' => date('Y-m-d', strtotime("$CIBLE +7 days")), 'quantity' => 999],
    ['date' => $jeudi(1), 'quantity' => 22],
], $CIBLE), 22.0);

// ── Les ruptures ──────────────────────────────────────────────────────────────
// Une tranche en rupture est écartée : elle bride la demande.
check('rupture exclue', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 5,  'stockout' => true],
    ['date' => $jeudi(2), 'quantity' => 40],
], $CIBLE), 40.0);

// Toutes en rupture → inconnu, pas zéro.
check('tout en rupture → inconnu', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 5, 'stockout' => true],
    ['date' => $jeudi(2), 'quantity' => 5, 'stockout' => true],
], $CIBLE), null);

// ── Inconnu vs zéro ───────────────────────────────────────────────────────────
// Le point le plus important. Aucun historique → null, jamais 0.0.
check('aucun historique → inconnu', $svc->forecastOne([], $CIBLE), null);

// Des ventes réellement nulles, elles, se lisent comme zéro.
check('ventes nulles → zéro', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 0],
], $CIBLE), 0.0);

// Lignes illisibles ignorées, sans faire tomber le reste.
check('lignes illisibles ignorées', $svc->forecastOne([
    'bruit', ['rien' => 1], ['date' => 'pas-une-date', 'quantity' => 9],
    ['date' => $jeudi(1), 'quantity' => 33],
], $CIBLE), 33.0);

// Date cible illisible → inconnu.
check('date cible illisible', $svc->forecastOne([
    ['date' => $jeudi(1), 'quantity' => 10],
], '27/08/2026'), null);

// ── forecastMany ──────────────────────────────────────────────────────────────
$many = $svc->forecastMany([
    101 => [['date' => $jeudi(1), 'quantity' => 48]],
    102 => [['date' => $jeudi(1), 'quantity' => 0]],
    103 => [],                                            // inconnu → absent
    104 => [['date' => $jeudi(1), 'quantity' => 5, 'stockout' => true]], // absent
], $CIBLE);
check('many : produit avec historique', $many[101] ?? 'absent', 48.0);
check('many : ventes nulles présentes', $many[102] ?? 'absent', 0.0);
check('many : inconnu absent',          array_key_exists(103, $many), false);
check('many : tout-rupture absent',     array_key_exists(104, $many), false);

// ── L'écart : prévu − vendu (décision 5) ─────────────────────────────────────
check('écart = prévu − vendu',   ProductionForecastService::gap(50.0, 30.0), 20.0);
check('écart négatif possible',  ProductionForecastService::gap(30.0, 42.0), -12.0);
// Prévision inconnue → écart inconnu, surtout pas « −vendu ».
check('écart inconnu si prévu inconnu', ProductionForecastService::gap(null, 30.0), null);

// ── Verdict ────────────────────────────────────────────────────────────────
if ($ko) {
    echo implode("\n", $ko) . "\n\n✗ " . count($ko) . " échec(s), $ok passées\n";
    exit(1);
}
echo "✓ $ok vérifications passées\n";
