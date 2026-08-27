<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Models\Production\StockLineModel;

/**
 * Le stock lu vers l'avant : tiendra-t-il jusqu'au bout du service ?
 *
 * « 3 macarons » ne dit rien tout seul. Ce qui se décide, c'est l'écart entre
 * ce qu'on a et ce qui va partir — d'abord sur la période en cours, puis sur
 * la suivante. Deux horizons parce que ce sont deux décisions différentes :
 * relancer maintenant, ou prévoir pour tout à l'heure.
 *
 * Service PUR : ni HTTP, ni horloge, ni session. Vérifiable hors serveur —
 * bin/outlook-test.php.
 */
class StockOutlookService
{
    /** Rupture avant la fin de la période en cours. */
    public const OUT_P1 = 'out_p1';
    /** Tient la période en cours, pas la suivante. */
    public const OUT_P2 = 'out_p2';
    /** Il en restera plus qu'on n'en aura vendu sur les deux périodes. */
    public const OVER = 'over';
    /** Ni l'un ni l'autre : ça passe. */
    public const OK = 'ok';
    /** Produit absent du profil : aucune conclusion honnête. */
    public const UNKNOWN = 'unknown';

    public const FLAGS = [self::OUT_P1, self::OUT_P2, self::OVER];

    /**
     * Les deux horizons, à partir de l'instant présent.
     *
     * P1 va de maintenant à la fin de la période en cours — pas la période
     * entière : à 10 h 45, ce qui reste à tenir, c'est un quart d'heure, et
     * compter les ventes depuis 5 h condamnerait tout le magasin à la rupture.
     * P2 est la période suivante, entière.
     *
     * @param PeriodModel[] $periods
     * @return array{p1: array{key: ?string, from: int, to: int}, p2: array{key: ?string, from: int, to: int}}
     */
    public function horizons(array $periods, int $nowMinutes): array
    {
        $bounds = [];
        foreach ($periods as $p) {
            $bounds[] = [
                'key'  => $p->getKey(),
                'from' => ForecastService::minutesOf($p->getStart()),
                'to'   => ForecastService::minutesOf($p->getEnd()),
            ];
        }
        usort($bounds, fn($a, $b) => $a['from'] <=> $b['from']);

        $i = null;
        foreach ($bounds as $k => $b) {
            if ($nowMinutes < $b['to']) {
                $i = $k;
                break;
            }
        }

        // Après la dernière période : plus rien à projeter, les deux horizons
        // sont vides plutôt qu'inventés sur la journée du lendemain.
        if ($i === null) {
            return [
                'p1' => ['key' => null, 'from' => $nowMinutes, 'to' => $nowMinutes],
                'p2' => ['key' => null, 'from' => $nowMinutes, 'to' => $nowMinutes],
            ];
        }

        $current = $bounds[$i];
        $next    = $bounds[$i + 1] ?? null;

        return [
            'p1' => [
                'key'  => $current['key'],
                'from' => max($nowMinutes, $current['from']),
                'to'   => $current['to'],
            ],
            'p2' => $next === null
                ? ['key' => null, 'from' => $current['to'], 'to' => $current['to']]
                : ['key' => $next['key'], 'from' => $next['from'], 'to' => $next['to']],
        ];
    }

    /**
     * Les périodes qu'il reste à faire.
     *
     * Une cuisine ne produit pas pour ce matin à 15 h. Les périodes passées
     * restent dans les données — le stock d'aujourd'hui vient d'elles, et leur
     * URL continue de répondre — mais elles quittent le sélecteur : des onglets
     * qui ne mènent qu'à du révolu font perdre le fil du service en cours.
     *
     * La période active reste visible même passée, sinon un lien partagé ou un
     * retour arrière ouvrirait un écran dont l'onglet n'existe pas. Et après la
     * fermeture on garde la dernière plutôt que de vider la barre : c'est celle
     * qu'on vient de finir, et c'est là qu'on va lire ce qui reste.
     *
     * @param PeriodModel[] $periods
     * @return PeriodModel[]
     */
    public function upcoming(array $periods, int $nowMinutes, ?string $activeKey = null): array
    {
        $kept = array_values(array_filter(
            $periods,
            fn(PeriodModel $p) => ForecastService::minutesOf($p->getEnd()) > $nowMinutes
                               || $p->getKey() === $activeKey
        ));

        if ($kept === [] && $periods !== []) {
            $last = $periods;
            usort($last, fn($a, $b) => ForecastService::minutesOf($a->getEnd()) <=> ForecastService::minutesOf($b->getEnd()));
            $kept = [end($last)];
        }

        return $kept;
    }

    /**
     * Une ligne par produit en stock, prête pour le tableau.
     *
     * Le catalogue donne la catégorie et l'unité quand la ligne de stock ne
     * les porte pas — c'est fréquent, et une colonne « — » de plus n'aide
     * personne.
     *
     * @param StockLineModel[]         $stock
     * @param ProductionProductModel[] $products
     * @param array{p1: array, p2: array} $horizons
     * @param array<int, float>        $orders1  commandes dues sur P1, par produit
     * @param array<int, float>        $orders2  commandes dues sur P2, par produit
     *
     * @return array<int, array>
     */
    public function rows(
        array $stock,
        array $products,
        ?SalesProfileModel $profile,
        array $horizons,
        array $orders1 = [],
        array $orders2 = []
    ): array {
        $byId = [];
        foreach ($products as $p) {
            if ($p->getIdProduct() !== null) {
                $byId[$p->getIdProduct()] = $p;
            }
        }

        $rows = [];
        foreach ($stock as $line) {
            $id = $line->getIdProduct();
            if ($id === null) {
                continue;
            }

            $product = $byId[$id] ?? null;
            $qty     = $line->getQuantity();

            $e1 = $profile?->expectedBetween($id, $horizons['p1']['from'], $horizons['p1']['to']);
            $e2 = $profile?->expectedBetween($id, $horizons['p2']['from'], $horizons['p2']['to']);

            // Ce qui est commandé partira, profil ou pas : le delta le
            // soustrait au même titre que les ventes prévues, mais la colonne
            // reste séparée — on veut pouvoir dire d'où vient le manque.
            $o1 = (float)($orders1[$id] ?? 0.0);
            $o2 = (float)($orders2[$id] ?? 0.0);

            $known = $e1 !== null;
            $d1    = $known ? $qty - $e1 - $o1 : null;
            $d2    = $known ? $qty - $e1 - $o1 - ($e2 ?? 0.0) - $o2 : null;

            $rows[] = [
                'id_product' => $id,
                'name'       => $line->getName() ?? $product?->getName() ?? '—',
                'category'   => $line->getCategoryName() ?: ($product?->getCategoryName() ?: '—'),
                'unit'       => $line->getUnitName() ?? $product?->getUnitName(),
                'stock'      => $qty,
                'expected_1' => $e1,
                'expected_2' => $e2,
                'orders_1'   => $o1,
                'orders_2'   => $o2,
                'delta_1'    => $d1,
                'delta_2'    => $d2,
                'flag'       => self::flag($e1, $e2, $o1, $o2, $d1, $d2),
            ];
        }

        // Catégorie puis produit : c'est l'ordre du magasin, et un tableau se
        // parcourt par blocs. Le tri par tension vit sur l'écran de période,
        // qui est fait pour agir ; celui-ci est fait pour lire.
        usort($rows, function (array $a, array $b) {
            $c = strnatcasecmp($a['category'], $b['category']);
            return $c !== 0 ? $c : strcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Le classement d'une ligne.
     *
     * La surproduction se mesure à ce qu'on vend, pas à un seuil arbitraire :
     * s'il reste après les deux périodes de quoi les servir une seconde fois,
     * on en a fait trop. Un produit qui ne se vend pas du tout sur l'horizon
     * n'est pas surproduit pour autant — il est hors saison, hors service, ou
     * simplement pas de ce moment de la journée.
     */
    private static function flag(?float $e1, ?float $e2, float $o1, float $o2, ?float $d1, ?float $d2): string
    {
        if ($e1 === null) {
            return self::UNKNOWN;
        }
        if ($d1 < 0) {
            return self::OUT_P1;
        }
        if ($d2 < 0) {
            return self::OUT_P2;
        }

        $sortant = $e1 + ($e2 ?? 0.0) + $o1 + $o2;
        return $sortant > 0 && $d2 >= $sortant ? self::OVER : self::OK;
    }

    /**
     * Les catégories présentes, dans l'ordre du tableau.
     *
     * @param array<int, array{category: string}> $rows
     * @return string[]
     */
    public function categories(array $rows): array
    {
        $seen = [];
        foreach ($rows as $r) {
            $seen[$r['category']] = true;
        }
        $out = array_keys($seen);
        usort($out, 'strnatcasecmp');
        return $out;
    }

    /**
     * Compte par filtre, pour les pastilles.
     *
     * @param array<int, array{flag: string}> $rows
     */
    public function counts(array $rows): array
    {
        $counts = array_fill_keys(self::FLAGS, 0);
        $counts['all'] = count($rows);
        foreach ($rows as $r) {
            if (isset($counts[$r['flag']])) {
                $counts[$r['flag']]++;
            }
        }
        return $counts;
    }
}
