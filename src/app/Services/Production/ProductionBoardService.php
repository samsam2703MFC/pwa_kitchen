<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\app\Models\Production\MepLineModel;
use App\Kitchen\app\Models\Production\ProductionProductModel;

/**
 * Compose le tableau de travail d'une période.
 *
 * Une période n'est pas un catalogue : c'est une liste de gestes. Les produits
 * s'y rangent donc par ce qu'ils attendent de nous — mettre en rayon, finir,
 * surveiller le four, préparer — et non par catégorie. La catégorie reste,
 * mais comme filtre.
 *
 * Service PUR : ni HTTP, ni horloge, ni session. Tout entre en paramètre, ce
 * qui le rend vérifiable hors serveur (bin/board-test.php).
 */
class ProductionBoardService
{
    /** « Prêt, plus rien au four » — c'est là que se fait la mise en rayon. */
    public const B_SHELF = 'shelf';
    public const B_FINISH = 'finish';
    public const B_COOK   = 'cook';
    public const B_PREP   = 'prep';
    /** Ni à faire, ni à mettre en rayon : le reste de la période. */
    public const B_IDLE   = 'idle';

    /**
     * Ordre de lecture. Ce qui est actionnable maintenant d'abord, puis le
     * cycle à rebours — ce qui sort bientôt avant ce qui n'a pas commencé.
     */
    public const BUCKETS = [self::B_SHELF, self::B_FINISH, self::B_COOK, self::B_PREP, self::B_IDLE];

    /**
     * L'étape en cours de chaque produit, lue depuis le plan de cuisson.
     *
     * Un produit peut avoir plusieurs fournées dans la journée : c'est la plus
     * imminente qui donne l'étape, parce que c'est celle qui va demander un
     * geste. Les fournées terminées ne comptent pas — leur produit est passé
     * en rayon ou l'attend.
     *
     * @param BakingBatchModel[] $batches
     * @return array<int, array{stage: string, batch: BakingBatchModel}>
     */
    public function stagesByProduct(array $batches): array
    {
        $out = [];
        foreach ($batches as $b) {
            $id = $b->getIdProduct();
            if ($id === null || $b->isDone() || $b->getStage() === BakingBatchModel::STAGE_DONE) {
                continue;
            }
            if (!isset($out[$id]) || $b->getDueTime() < $out[$id]['batch']->getDueTime()) {
                $out[$id] = ['stage' => $b->getStage(), 'batch' => $b];
            }
        }
        return $out;
    }

    /**
     * Le tableau de la période, prêt à afficher.
     *
     * @param ProductionProductModel[] $products    déjà filtrés sur la période
     * @param MepLineModel[]           $mepLines    la MEP du jour
     * @param array<int, array{stage: string, batch: BakingBatchModel}> $stages
     * @param array<int, array{stock: float, expected: ?float, projected: ?float}> $tension
     *
     * @return array{
     *   buckets: array<string, array<int, array>>,
     *   categories: string[],
     *   to_shelf: int
     * }
     */
    public function build(array $products, array $mepLines, array $stages, array $tension): array
    {
        $lines = [];
        foreach ($mepLines as $l) {
            if ($l->getIdProduct() !== null) {
                $lines[$l->getIdProduct()] = $l;
            }
        }

        $buckets    = array_fill_keys(self::BUCKETS, []);
        $categories = [];

        foreach ($products as $p) {
            $id = $p->getIdProduct();
            if ($id === null || !$p->isActive()) {
                continue;
            }

            $line      = $lines[$id] ?? null;
            $remaining = $line?->getRemainingToShelf() ?? 0.0;
            $stage     = $stages[$id]['stage'] ?? null;
            $t         = ($tension[$id] ?? []) + ['stock' => 0.0, 'expected' => null, 'ordered' => 0.0, 'projected' => null];

            // Ce qui est prêt passe devant son étape : une fournée suivante au
            // four ne dispense pas de porter en rayon celle qui est sortie.
            $bucket = $remaining > 0
                ? self::B_SHELF
                : (in_array($stage, [self::B_PREP, self::B_COOK, self::B_FINISH], true) ? $stage : self::B_IDLE);

            $category = $p->getCategoryName() ?: '—';
            $categories[$category] = true;

            $buckets[$bucket][] = [
                'product'    => $p,
                'line'       => $line,
                'batch'      => $stages[$id]['batch'] ?? null,
                'category'   => $category,
                'remaining'  => $remaining,
                'stock'      => $t['stock'],
                'expected'   => $t['expected'],
                // Ce qui est déjà promis à un client : compté dans la
                // projection, mais dit à part sur la tuile — « il en manque 24 »
                // et « il en manque 24 dont 18 commandés » n'appellent pas la
                // même vigilance.
                'ordered'    => $t['ordered'],
                'projected'  => $t['projected'],
                'to_produce' => self::toProduce($t['projected'], $p->getEffectiveBatchSize()),
                'level'      => self::level($t['projected'], $p->getEffectiveBatchSize()),
            ];
        }

        foreach ($buckets as &$rows) {
            usort($rows, [$this, 'compare']);
        }
        unset($rows);

        $categories = array_keys($categories);
        usort($categories, 'strnatcasecmp');

        return [
            'buckets'    => $buckets,
            'categories' => $categories,
            'to_shelf'   => count($buckets[self::B_SHELF]),
        ];
    }

    /**
     * Combien il faudra produire, arrondi à la fournée supérieure.
     *
     * null quand on ne sait pas (ventes non servies), 0 quand la projection
     * tient. « Il manque 17 croissants » n'est pas exécutable devant un four
     * qui sort des plaques de 24 — c'est le même arrondi que les propositions
     * de recuisson, et volontairement le même, pour que les deux écrans ne
     * donnent jamais deux chiffres différents du même besoin.
     */
    private static function toProduce(?float $projected, float $batch): ?float
    {
        if ($projected === null) {
            return null;
        }
        if ($projected >= 0) {
            return 0.0;
        }
        $batch = $batch > 0 ? $batch : 1.0;
        return ceil(-$projected / $batch) * $batch;
    }

    /**
     * L'état en un mot, pour la couleur.
     *
     * - `short`   : ça va manquer, il y a un lot à lancer
     * - `tight`   : ça passe, mais à moins d'une fournée près — on surveille
     * - `ok`      : de la marge
     * - `unknown` : ventes non servies ; aucune couleur ne serait honnête
     *
     * La bande orange vaut une fournée et pas un pourcentage : c'est l'unité
     * dans laquelle l'atelier décide. À moins d'un lot de marge, on ne lance
     * rien encore mais on ne quitte plus le produit des yeux.
     */
    private static function level(?float $projected, float $batch): string
    {
        if ($projected === null) {
            return 'unknown';
        }
        if ($projected < 0) {
            return 'short';
        }
        return $projected < ($batch > 0 ? $batch : 1.0) ? 'tight' : 'ok';
    }

    /**
     * Tri d'une section : la tension d'abord.
     *
     * Le stock qui manquera le plus vite passe en tête. Un produit dont on ne
     * connaît pas les ventes ne peut pas être classé par tension : il vient
     * après ceux qu'on sait en difficulté, mais avant l'ordre alphabétique
     * seul, classé sur son stock. Deviner une prévision pour le trier serait
     * inventer un chiffre pour faire joli.
     */
    private function compare(array $a, array $b): int
    {
        $ka = $a['projected'] === null ? 1 : 0;
        $kb = $b['projected'] === null ? 1 : 0;
        if ($ka !== $kb) {
            return $ka <=> $kb;
        }

        if ($ka === 0) {
            $c = $a['projected'] <=> $b['projected'];
            if ($c !== 0) {
                return $c;
            }
        } else {
            $c = $a['stock'] <=> $b['stock'];
            if ($c !== 0) {
                return $c;
            }
        }

        return strcasecmp((string)$a['product']->getName(), (string)$b['product']->getName());
    }
}
