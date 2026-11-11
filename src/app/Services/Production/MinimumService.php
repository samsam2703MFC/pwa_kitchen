<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\PeriodModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;
use App\Kitchen\app\Models\Production\StockLineModel;

/**
 * Le minimum à tenir en magasin, période par période.
 *
 * Certains produits ne se pilotent pas à la prévision de ventes mais à la
 * présentation : une vitrine de boulangerie avec deux baguettes ne vend pas,
 * même si deux baguettes suffiraient à la demande de 16 h. Ces produits-là
 * portent `is_pdm` et un plancher par période ; l'écran compare ce plancher au
 * stock du magasin et dit quoi relancer, en fournées.
 *
 * Deux chiffres seulement dans la comparaison, et c'est voulu : le plancher et
 * ce qu'on a. Les ventes prévues vivent sur l'écran Stock, qui répond à l'autre
 * question — « est-ce que ça tient ». Ici la question est « est-ce que la
 * vitrine est présentable », et elle ne se discute pas avec une moyenne. Les
 * commandes fermes, elles, s'ajoutent au plancher : ce qui est promis à un
 * client n'est pas en vitrine.
 *
 * Service PUR : ni HTTP, ni horloge, ni session — bin/sector-test.php.
 */
class MinimumService
{
    /** Sous le plancher : il y a une fournée à lancer. */
    public const SHORT = 'short';
    /** Au plancher à moins d'une fournée près : on ne quitte plus des yeux. */
    public const TIGHT = 'tight';
    public const OK = 'ok';
    /** Produit `is_pdm` sans plancher déclaré : rien d'honnête à conclure. */
    public const UNKNOWN = 'unknown';

    public const LEVELS = [self::SHORT, self::TIGHT, self::OK, self::UNKNOWN];

    /**
     * Le tableau des minimums, une ligne par produit tenu au plancher.
     *
     * @param ProductionProductModel[] $products  déjà filtrés sur le secteur
     * @param StockLineModel[]|null    $stock     null = stock non servi
     * @param array<int, float>        $orders    commandes dues, par produit
     * @param PeriodModel[]            $periods   les colonnes du tableau
     * @param string|null              $focusKey  la période qui décide de la
     *                                            couleur ; null = la première
     *
     * @return array<int, array>
     */
    public function rows(array $products, ?array $stock, array $orders, array $periods, ?string $focusKey = null): array
    {
        $stockById = [];
        foreach ($stock ?? [] as $line) {
            if ($line->getIdProduct() !== null) {
                $stockById[$line->getIdProduct()] = $line->getQuantity();
            }
        }

        $keys  = array_map(fn(PeriodModel $p) => $p->getKey(), $periods);
        $focus = $focusKey !== null && in_array($focusKey, $keys, true) ? $focusKey : ($keys[0] ?? null);

        $rows = [];
        foreach ($products as $p) {
            $id = $p->getIdProduct();
            if ($id === null || !$p->isActive() || !$p->isPdm()) {
                continue;
            }

            $minimums = [];
            foreach ($keys as $key) {
                $minimums[$key] = $p->getMinimum($key);
            }

            $target  = $focus !== null ? $minimums[$focus] : null;
            $known   = $stock !== null && $target !== null;
            $onHand  = $stockById[$id] ?? 0.0;
            $due     = $orders[$id] ?? 0.0;
            // Ce qui est promis à un client n'est pas en vitrine : le plancher
            // et les commandes s'additionnent, ils ne se remplacent pas.
            $gap     = $known ? ($target + $due) - $onHand : null;
            $batch   = $p->getEffectiveBatchSize();

            $rows[] = [
                'id_product' => $id,
                'name'       => $p->getName() ?? '—',
                'category'   => $p->getCategoryName() ?: '—',
                'unit'       => $p->getUnitName(),
                'batch'      => $batch,
                'has_batch'  => $p->hasBatchSize(),
                'stock'      => $stock !== null ? $onHand : null,
                'ordered'    => $due,
                'minimums'   => $minimums,
                'target'     => $target,
                'gap'        => $gap,
                'to_produce' => self::toProduce($gap, $batch),
                'level'      => self::level($gap, $batch, $known),
            ];
        }

        // Catégorie puis produit : c'est l'ordre du magasin, et ce tableau se
        // lit rayon par rayon — on va remplir la vitrine dans cet ordre-là.
        usort($rows, function (array $a, array $b) {
            $c = strnatcasecmp($a['category'], $b['category']);
            return $c !== 0 ? $c : strcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Combien lancer, arrondi à la fournée supérieure.
     *
     * Même arrondi que le tableau de période et que les propositions de
     * recuisson, et volontairement le même : deux écrans qui donneraient deux
     * chiffres du même besoin en feraient douter des deux.
     */
    private static function toProduce(?float $gap, float $batch): ?float
    {
        if ($gap === null) {
            return null;
        }
        if ($gap <= 0) {
            return 0.0;
        }
        $batch = $batch > 0 ? $batch : 1.0;
        return ceil($gap / $batch) * $batch;
    }

    /** L'état en un mot, pour la couleur. */
    private static function level(?float $gap, float $batch, bool $known): string
    {
        if (!$known || $gap === null) {
            return self::UNKNOWN;
        }
        if ($gap > 0) {
            return self::SHORT;
        }
        // À moins d'une fournée au-dessus du plancher : rien à lancer encore,
        // mais deux clients suffisent à passer dessous.
        return -$gap < ($batch > 0 ? $batch : 1.0) ? self::TIGHT : self::OK;
    }

    /**
     * Compte par état, pour les pastilles.
     *
     * @param array<int, array{level: string}> $rows
     * @return array<string, int>
     */
    public function counts(array $rows): array
    {
        $counts = array_fill_keys(self::LEVELS, 0);
        $counts['all'] = count($rows);
        foreach ($rows as $r) {
            if (isset($counts[$r['level']])) {
                $counts[$r['level']]++;
            }
        }
        return $counts;
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
}
