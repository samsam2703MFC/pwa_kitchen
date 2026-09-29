<?php

namespace App\Kitchen\app\Services\Production;

/**
 * La prévision de production : combien produire, par produit et par tranche.
 *
 * ── Volontairement PUR ──
 * Ni HTTP, ni horloge, ni base. Il reçoit un historique déjà normalisé et rend
 * une prévision. C'est ce qui le rend vérifiable sans serveur —
 * bin/production-forecast-test.php — et ce qui respecte la contrainte du brief :
 * la logique métier se construit à partir des données des endpoints, elle ne
 * les appelle pas elle-même. Le dépôt (à écrire quand les contrats seront
 * confirmés au jeton) traduira `statistics/production-planning` et
 * `sales/hourly-distribution` en `Sample[]` ; ce moteur ne connaît que ça.
 *
 * ── La règle (décisions du 27/08/2026) ──
 *   prévision(produit, tranche, jour_cible)
 *       = moyenne PONDÉRÉE des ventes de ce produit sur cette même tranche,
 *         le même jour de semaine, au fil des N dernières semaines.
 *
 *   • N = 6 semaines par défaut, paramétrable en base — jamais en dur.
 *   • Pondération en faveur des semaines récentes : poids(k) = (N+1 − k) pour
 *     la k-ième semaine en arrière (k=1 = la semaine dernière, poids le plus
 *     fort), renormalisée sur les semaines réellement présentes.
 *   • Même jour de semaine, même tranche : un mardi matin se prévoit sur les
 *     mardis matin.
 *   • Les heures de RUPTURE sont exclues : une tranche où le produit était
 *     épuisé mesure une demande bridée, pas la vraie. On retire l'échantillon
 *     plutôt que de le lire comme une vente faible.
 *
 * ── Ce que le moteur refuse de faire ──
 * Sans aucun échantillon exploitable, il rend `null` — PAS zéro. « Je ne sais
 * pas » et « rien ne se vend » n'appellent pas la même décision : le premier
 * demande de regarder, le second de ne rien produire. L'écran les distingue.
 *
 * ── Périmètre ──
 * Par boutique ET par section : le moteur ne mélange pas les sections, il
 * suffit de ne lui passer que les échantillons d'une (boulangerie / traiteur).
 * La boutique est déjà tranchée en amont — un Sample vient d'une seule boutique.
 */
class ProductionForecastService
{
    /** Fenêtre par défaut, en semaines. Surchargée par le paramètre de base. */
    public const DEFAULT_WEEKS = 6;

    /**
     * La prévision d'UN produit sur UNE tranche, pour un jour cible.
     *
     * @param array<int, array{date: string, quantity: float|int, stockout?: bool}> $samples
     *        L'historique de CE produit sur CETTE tranche : une entrée par jour
     *        observé. `date` en Y-m-d ; `stockout` = true si le produit était
     *        épuisé sur la tranche ce jour-là (l'échantillon est alors écarté).
     * @param string $targetDate  Le jour à prévoir (Y-m-d).
     * @param int    $weeks        N — fenêtre d'historique. ≤ 0 → défaut.
     *
     * @return float|null  La quantité prévue, ou null si aucun échantillon
     *                     exploitable (jour de semaine + tranche jamais observés,
     *                     ou tous en rupture).
     */
    public function forecastOne(array $samples, string $targetDate, int $weeks = self::DEFAULT_WEEKS): ?float
    {
        $weeks = $weeks > 0 ? $weeks : self::DEFAULT_WEEKS;

        $targetTs  = self::midnight($targetDate);
        if ($targetTs === null) {
            return null;
        }
        $targetDow = (int)date('N', $targetTs); // 1 (lundi) … 7 (dimanche)

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($samples as $s) {
            if (!is_array($s) || !isset($s['date'])) {
                continue;
            }
            // Une rupture bride la demande : on ne lit pas « peu vendu » là où
            // c'est « plus rien à vendre ».
            if (!empty($s['stockout'])) {
                continue;
            }

            $ts = self::midnight((string)$s['date']);
            if ($ts === null) {
                continue;
            }

            // Même jour de semaine, uniquement.
            if ((int)date('N', $ts) !== $targetDow) {
                continue;
            }

            // k = combien de semaines en arrière (1 = la semaine dernière).
            // Le jour cible lui-même (k=0) et le futur sont ignorés : on ne se
            // prévoit pas sur soi.
            $daysBack = (int)round(($targetTs - $ts) / 86400);
            if ($daysBack <= 0) {
                continue;
            }
            $k = intdiv($daysBack, 7);
            if ($daysBack % 7 !== 0) {
                // Pas exactement un multiple de 7 : ce n'est pas le même rang
                // hebdomadaire. Comme on a déjà filtré sur le jour de semaine,
                // ce cas ne devrait pas arriver ; on le rejette par prudence.
                continue;
            }
            if ($k < 1 || $k > $weeks) {
                continue;
            }

            $weight       = $weeks + 1 - $k; // récent = lourd
            $weightedSum += $weight * (float)$s['quantity'];
            $weightTotal += $weight;
        }

        if ($weightTotal <= 0.0) {
            return null;
        }

        return $weightedSum / $weightTotal;
    }

    /**
     * La prévision de PLUSIEURS produits sur une tranche, en une passe.
     *
     * @param array<int|string, array<int, array{date: string, quantity: float|int, stockout?: bool}>> $historyByProduct
     *        Historique par identifiant de produit.
     * @param string $targetDate
     * @param int    $weeks
     *
     * @return array<int|string, float>  identifiant → quantité prévue. Les
     *         produits sans prévision exploitable sont ABSENTS de la map (et
     *         non à zéro) : l'appelant sait ainsi lesquels restent inconnus.
     */
    public function forecastMany(array $historyByProduct, string $targetDate, int $weeks = self::DEFAULT_WEEKS): array
    {
        $out = [];
        foreach ($historyByProduct as $productId => $samples) {
            if (!is_array($samples)) {
                continue;
            }
            $q = $this->forecastOne($samples, $targetDate, $weeks);
            if ($q !== null) {
                $out[$productId] = $q;
            }
        }

        return $out;
    }

    /**
     * L'écart d'une tranche : prévu − vendu (décision du 27/08).
     *
     * Rendu séparément parce que « vendu » vient d'une autre source que la
     * prévision (ventes réelles du jour) et qu'un écart n'a de sens que quand
     * la prévision existe. Prévision inconnue → écart null, pas « −vendu ».
     *
     * @param float|null $forecast  la prévision, ou null si inconnue
     * @param float      $sold      les ventes réelles de la tranche
     * @return float|null           prévu − vendu, ou null si prévision inconnue
     */
    public static function gap(?float $forecast, float $sold): ?float
    {
        return $forecast === null ? null : $forecast - $sold;
    }

    /** Minuit d'une date Y-m-d, ou null si la date est illisible. */
    private static function midnight(string $date): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $ts = strtotime($date . ' 00:00:00 UTC');

        return $ts === false ? null : $ts;
    }
}
