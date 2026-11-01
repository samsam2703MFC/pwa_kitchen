<?php

namespace App\Kitchen\app\Services\Knowledge\Product;

use App\Kitchen\app\Models\Knowledge\Product\ProductDetailModel;
use App\Kitchen\app\Models\Knowledge\Recipe\RecipeIngredientModel;
use App\Kitchen\app\Models\Knowledge\Recipe\RecipeSubrecipeModel;

/**
 * Le « book » de production : la fiche technique remise en pages, une étape par écran,
 * à la manière de la cuisson guidée d'un Thermomix.
 *
 * Rien n'est inventé : tout vient de la fiche (étapes de préparation, temps par type,
 * ingrédients et quantités de la recette). Le builder ne fait que lire le texte des
 * étapes pour en sortir ce qu'un vendeur doit voir en gros :
 *   · les durées       « 12 min », « 1h30 », « 30 sec », « 12 à 15 min » → un minuteur ;
 *   · les températures « 180°C », « 180 degrés »                           → mises en avant ;
 *   · les vitesses     « vitesse 3 », « sens inverse »                     → mises en avant ;
 *   · la mise sur plaque « mise sur plaque 3 x 3 »                         → le schéma de la plaque,
 *     garnie de la forme du produit (baguette, croissant, quiche…), et le nombre de plaques ;
 *   · les ingrédients cités dans l'étape → rappelés avec leur quantité.
 *
 * Quand la fiche n'a pas d'étapes mais des temps par type (Préparation, Cuisson…),
 * chaque type devient une page avec son minuteur.
 */
class ProductionBookBuilder
{
    private const NUM = '\d+(?:[.,]\d+)?';
    private const RANGE_SEP = '\s*(?:-|–|—|à|a|to|do|tot)\s*';
    private const UNIT_H = 'h|hr|hrs|heures?|hours?|godz(?:\.|in[ay]?)?|uur|ore|ora';
    private const UNIT_MIN = 'min(?:utes?|uten|uti|ut[ay]?|\.)?|mn';
    private const UNIT_SEC = 'sec(?:\.|ondes?|onds?|onden|ondi)?|sek(?:\.|und[ay]?)?|s';
    private const SPEED_WORDS = 'vitesse|vit\.|speed|snelheid|velocit[àa]|pr[ęe]dko[śs][ćc]';
    /** Un « 3 x 3 » n'est une mise sur plaque que si l'étape parle de plaque. */
    private const TRAY_WORDS = '/(?<!\p{L})(?:plaques?|t[ôo]les?|grilles?|trays?|blach[ay]?|bakpla(?:at|ten)|tegli[ae])(?!\p{L})/iu';
    private const TRAY_UNITS = 'g|kg|ml|cl|l|cm|mm|m|min|mn|sec|s|h|pcs|pc|°';

    /** Mots qui ne disent rien d'un ingrédient (fr, en, pl, nl, it). */
    private const STOPWORDS = [
        'les', 'des', 'aux', 'une', 'pour', 'avec', 'sans', 'dans', 'sur', 'par', 'puis', 'bien', 'tout', 'toute',
        'the', 'and', 'with', 'for', 'from', 'into',
        'oraz', 'lub', 'bez', 'dla',
        'van', 'het', 'een', 'met', 'voor',
        'del', 'della', 'con', 'per',
        'kg', 'ml', 'cl', 'pcs', 'pce', 'szt',
    ];

    /**
     * Mots qui qualifient sans nommer : seuls, ils ne suffisent pas à dire qu'un ingrédient est cité
     * (« ciboulette fraîche » ne cite pas la Crème fraîche). Formes normalisées, comme tokens().
     */
    private const WEAK = [
        'frai', 'fraiche', 'noir', 'noire', 'blanc', 'blanche', 'rouge', 'vert', 'verte', 'jaune',
        'doux', 'douce', 'sale_', 'sec', 'seche', 'entier', 'entiere', 'fin', 'fine',
        'gros', 'grosse', 'petit', 'petite', 'liquide', 'moulu', 'moulue', 'hache_', 'rape_',
        'bio', 'maison', 'extra', 'nature', 'surgele_', 'congele_',
        'fresh', 'dark', 'white', 'red', 'green', 'whole', 'ground', 'frozen',
    ];

    private TrayLayout $trayLayout;

    public function __construct(?TrayLayout $trayLayout = null)
    {
        $this->trayLayout = $trayLayout ?? new TrayLayout();
    }

    public function build(ProductDetailModel $product): array
    {
        $ingredients = $this->ingredients($product);
        $phases = $this->phases($product);
        $steps = $this->steps($product, $ingredients, $phases, $written);

        $recipe = $product->getRecipe();
        $yield = $recipe ? $this->toFloat($recipe->getYieldQuantity()) : null;

        $total = 0;
        foreach ($phases as $p) {
            $total += $p['seconds'];
        }

        $tray = null;
        foreach ($steps as $st) {
            if ($st['tray']) {
                $tray = $st['tray'];
                break;
            }
        }

        return [
            'available' => !empty($steps) || !empty($ingredients),
            'ingredients' => $ingredients,
            'phases' => $phases,
            'steps' => $steps,
            // false : la fiche n'a pas encore d'étapes rédigées (SOP à saisir) — les pages viennent des temps.
            'steps_written' => $written,
            'total_seconds' => $total,
            'total_label' => $total > 0 ? $this->durationLabel($total) : null,
            'yield' => $yield && $yield > 0 ? $yield : null,
            'yield_label' => $yield && $yield > 0 ? $this->number($yield) : null,
            // la première mise sur plaque des étapes, rappelée sur la page « Avant de commencer »
            'tray' => $tray,
        ];
    }

    /**
     * Les ingrédients du premier niveau de la recette, dans l'ordre de la fiche.
     * Une sous-recette reste une ligne (elle se prépare à part) ; on dit ce qu'elle contient,
     * sans en recalculer les quantités : la fiche ne dit pas pour quel rendement elles valent.
     */
    private function ingredients(ProductDetailModel $product): array
    {
        $recipe = $product->getRecipe();
        if (!$recipe || !$recipe->hasElements()) {
            return [];
        }

        $out = [];
        foreach ($recipe->getElements() as $i => $element) {
            $qty = $this->toFloat($element->getRecipeQuantity());
            $row = [
                'key' => 'i' . $i,
                'name' => trim((string) $element->getName()),
                'qty' => $qty,
                'qty_label' => $qty !== null ? $this->number($qty) : null,
                'unit' => trim((string) $element->getUnitName()),
                'is_subrecipe' => $element instanceof RecipeSubrecipeModel,
                'contains' => [],
                'allergens' => [],
            ];

            if ($element instanceof RecipeIngredientModel) {
                $row['allergens'] = array_values(array_filter((array) $element->getAllergens()));
            } elseif ($element instanceof RecipeSubrecipeModel) {
                foreach ($element->getElements() as $sub) {
                    if ($sub->getName()) {
                        $row['contains'][] = trim((string) $sub->getName());
                    }
                    if ($sub instanceof RecipeIngredientModel) {
                        $row['allergens'] = array_merge($row['allergens'], array_filter((array) $sub->getAllergens()));
                    }
                }
                $row['allergens'] = array_values(array_unique($row['allergens']));
            }

            if ($row['name'] !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Les temps par type de préparation (Préparation 15 min, Cuisson 25 min…). */
    private function phases(ProductDetailModel $product): array
    {
        $prep = $product->getPreparation();
        if (!$prep || !$prep->hasTypes()) {
            return [];
        }

        $out = [];
        foreach ($prep->getTypes() as $type) {
            $seconds = (int) ($type->getDurationSecond() ?? 0);
            $out[] = [
                'name' => trim((string) $type->getName()),
                'seconds' => max(0, $seconds),
                'label' => $seconds > 0 ? $this->durationLabel($seconds) : null,
            ];
        }

        return $out;
    }

    private function steps(ProductDetailModel $product, array $ingredients, array $phases, ?bool &$written = null): array
    {
        $prep = $product->getPreparation();
        $raw = [];

        if ($prep && $prep->hasSteps()) {
            foreach ($prep->getSteps() as $i => $step) {
                $text = trim((string) $step->getStepDescription());
                if ($text === '') {
                    continue;
                }
                $raw[] = [
                    'order' => is_numeric($step->getStepNumber()) ? (float) $step->getStepNumber() : 1000 + $i,
                    'index' => $i,
                    'text' => $text,
                    'title' => null,
                    'phase_seconds' => 0,
                ];
            }
            usort($raw, fn ($a, $b) => [$a['order'], $a['index']] <=> [$b['order'], $b['index']]);
        }

        $written = !empty($raw);
        if (!$written) {
            // Pas d'étapes rédigées : chaque type de préparation chronométré devient une page.
            foreach ($phases as $i => $phase) {
                if ($phase['name'] === '' && $phase['seconds'] <= 0) {
                    continue;
                }
                $raw[] = [
                    'order' => $i,
                    'index' => $i,
                    'text' => $phase['name'],
                    'title' => $phase['name'],
                    'phase_seconds' => $phase['seconds'],
                ];
            }
        }

        $obj = $product->getObject();
        $shape = $this->trayLayout->shapeFor($product->getName(), $obj?->getCategoryName());

        $steps = [];
        foreach ($raw as $n => $r) {
            $parsed = $this->parse($r['text']);

            $tray = null;
            if ($parsed['trays']) {
                $t = $parsed['trays'][0];
                $grid = $this->trayLayout->grid($t['a'], $t['b']);
                $tray = $grid + [
                    'label' => $t['a'] . ' × ' . $t['b'],
                    'shape' => $shape,
                    'svg' => $this->trayLayout->svg($grid['cols'], $grid['rows'], $shape),
                ];
            }

            $timers = $parsed['timers'];
            if ($r['phase_seconds'] > 0 && empty($timers)) {
                $timers[] = [
                    'seconds' => $r['phase_seconds'],
                    'label' => $this->durationLabel($r['phase_seconds']),
                ];
            }

            $steps[] = [
                'number' => $n + 1,
                'text' => $r['text'],
                'segments' => $parsed['segments'],
                'timers' => $timers,
                'temperatures' => $parsed['temperatures'],
                'speeds' => $parsed['speeds'],
                'tray' => $tray,
                'ingredients' => $this->ingredientsIn($r['text'], $ingredients),
            ];
        }

        return $steps;
    }

    /**
     * Découpe le texte d'une étape en morceaux : du texte, et entre deux, les durées,
     * températures et vitesses trouvées. Les décalages sont en octets (preg), de bout en bout.
     */
    public function parse(string $text): array
    {
        $n = self::NUM;
        $sep = self::RANGE_SEP;
        $after = '(?![\p{L}\d])';

        $hasTrayWord = (bool) preg_match(self::TRAY_WORDS, $text);
        $max = TrayLayout::MAX;

        $patterns = [
            // 3 x 3, 4×3, 5*2 — pas « 2 x 500 g », pas « 40 x 60 cm »
            'tray' => "(?<tr_a>\d{1,2})\s*[x×X*]\s*(?<tr_b>\d{1,2})(?!\d|[.,]\d)(?!\s*(?:" . self::TRAY_UNITS . "){$after})",
            // 1h30, 1 h 30 min
            'hm' => "(?<hm_h>\d+)\s*h\s*(?<hm_m>[0-5]?\d){$after}(?:\s*(?:" . self::UNIT_MIN . "){$after})?",
            'dur' => "(?<d_a>{$n})(?:{$sep}(?<d_b>{$n}))?\s*(?<d_u>" . self::UNIT_H . '|' . self::UNIT_MIN . '|' . self::UNIT_SEC . "){$after}",
            'temp' => "(?<t_a>-?{$n})(?:{$sep}(?<t_b>{$n}))?\s*(?:°\s*C?|degr[ée]s?\s*(?:C|celsius)?{$after}|degrees?{$after}|stopni(?:e|a)?{$after}|graden{$after}|gradi{$after})",
            'speed' => "(?:" . self::SPEED_WORDS . ")\s*(?<s_v>{$n}(?:{$sep}{$n})?|cuill[èe]re|spoon|turbo){$after}|sens\s+inverse{$after}",
        ];

        $regex = '/' . implode('|', array_map(fn ($k, $p) => "(?<{$k}>{$p})", array_keys($patterns), $patterns)) . '/iu';

        $segments = [];
        $timers = [];
        $temperatures = [];
        $speeds = [];
        $trays = [];
        $pos = 0;

        if (preg_match_all($regex, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            foreach ($matches as $m) {
                [$value, $offset] = $m[0];
                if ($offset > $pos) {
                    $segments[] = ['t' => 'text', 'v' => substr($text, $pos, $offset - $pos)];
                }
                $pos = $offset + strlen($value);
                $value = trim($value);

                if ($this->hit($m, 'tray')) {
                    $a = (int) $m['tr_a'][0];
                    $b = (int) $m['tr_b'][0];
                    if ($hasTrayWord && $a >= 1 && $b >= 1 && $a <= $max && $b <= $max) {
                        $segments[] = ['t' => 'tray', 'v' => $value];
                        $trays[] = ['a' => $a, 'b' => $b];
                    } else {
                        $segments[] = ['t' => 'text', 'v' => $m[0][0]];
                    }
                } elseif ($this->hit($m, 'hm')) {
                    $seconds = (int) $m['hm_h'][0] * 3600 + (int) $m['hm_m'][0] * 60;
                    $segments[] = ['t' => 'duration', 'v' => $value];
                    $this->addTimer($timers, $seconds, $value);
                } elseif ($this->hit($m, 'dur')) {
                    $unit = $this->unitSeconds($m['d_u'][0]);
                    $seconds = (int) round($this->toFloat($m['d_a'][0]) * $unit);
                    $segments[] = ['t' => 'duration', 'v' => $value];
                    $this->addTimer($timers, $seconds, $value);
                } elseif ($this->hit($m, 'temp')) {
                    $segments[] = ['t' => 'temp', 'v' => $value];
                    $temperatures[] = $value;
                } elseif ($this->hit($m, 'speed')) {
                    $segments[] = ['t' => 'speed', 'v' => $value];
                    $speeds[] = $value;
                } else {
                    $segments[] = ['t' => 'text', 'v' => $m[0][0]];
                }
            }
        }

        if ($pos < strlen($text)) {
            $segments[] = ['t' => 'text', 'v' => substr($text, $pos)];
        }

        return [
            'segments' => $segments,
            'timers' => $timers,
            'temperatures' => array_values(array_unique($temperatures)),
            'speeds' => array_values(array_unique($speeds)),
            'trays' => $trays,
        ];
    }

    private function hit(array $m, string $group): bool
    {
        return isset($m[$group]) && $m[$group][0] !== null && $m[$group][0] !== '';
    }

    private function addTimer(array &$timers, int $seconds, string $label): void
    {
        // Au-delà de 24 h ce n'est plus un minuteur (temps de repos, de froid…), on le montre sans plus.
        if ($seconds <= 0 || $seconds > 86400) {
            return;
        }
        foreach ($timers as $t) {
            if ($t['seconds'] === $seconds) {
                return;
            }
        }
        $timers[] = ['seconds' => $seconds, 'label' => $label];
    }

    private function unitSeconds(string $unit): int
    {
        $u = mb_strtolower($unit);
        if (preg_match('/^(?:' . self::UNIT_H . ')$/iu', $u)) {
            return 3600;
        }
        if (preg_match('/^(?:' . self::UNIT_MIN . ')$/iu', $u)) {
            return 60;
        }
        return 1;
    }

    /**
     * Les ingrédients que le texte de l'étape cite, dans l'ordre où il les cite.
     * Un ingrédient est cité quand l'un de ses mots significatifs l'est ; quand deux
     * ingrédients se disputent un mot (« chocolat » pour Chocolat noir et Chocolat blanc),
     * celui dont l'étape cite le plus de mots l'emporte (« chocolat noir »), à égalité les deux restent.
     */
    public function ingredientsIn(string $text, array $ingredients): array
    {
        $stepTokens = $this->tokens($text);
        if (!$stepTokens) {
            return [];
        }
        $positions = [];
        foreach ($stepTokens as $i => $tok) {
            $positions[$tok] ??= $i;
        }

        $candidates = [];
        foreach ($ingredients as $ing) {
            $tokens = array_values(array_unique($this->tokens($ing['name'])));
            if (!$tokens) {
                continue;
            }
            $hits = array_values(array_filter($tokens, fn ($t) => isset($positions[$t])));
            $strong = array_diff($hits, self::WEAK);
            if (!$hits || (!$strong && count($hits) < count($tokens))) {
                continue;
            }
            $candidates[$ing['key']] = [
                'hits' => $hits,
                'score' => count($hits) / count($tokens),
                'count' => count($hits),
                'first' => min(array_map(fn ($t) => $positions[$t], $hits)),
            ];
        }

        // Pour chaque mot disputé, seuls les meilleurs restent.
        $keep = $candidates;
        foreach ($candidates as $key => $c) {
            foreach ($candidates as $other => $o) {
                if ($other === $key || !array_intersect($c['hits'], $o['hits'])) {
                    continue;
                }
                if ([$o['count'], $o['score']] > [$c['count'], $c['score']]) {
                    unset($keep[$key]);
                    break;
                }
            }
        }

        uasort($keep, fn ($a, $b) => $a['first'] <=> $b['first']);

        return array_keys($keep);
    }

    /** Mots significatifs, en minuscules sans accents, au singulier approximatif. */
    private function tokens(string $text): array
    {
        $t = mb_strtolower($text);
        // Un mot qui finit en -é, -ée, -és, -ées (beurrés, hachée) garde une marque : « ramequins beurrés »
        // ne doit pas rappeler le Beurre. Le même traitement des deux côtés laisse « purée » trouver la Purée.
        $t = preg_replace('/(?<=\p{L})ée?s?(?!\p{L})/u', 'e_', $t);
        $t = strtr($t, [
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a',
            'ç' => 'c', 'ć' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ý' => 'y', 'ñ' => 'n', 'ń' => 'n', 'ł' => 'l', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        $parts = preg_split('/[^\p{L}_]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, self::STOPWORDS, true)) {
                continue;
            }
            // pluriels : oeufs → oeuf, choux → chou, tomates → tomate
            if (mb_strlen($p) > 4 && preg_match('/[sx]$/', $p)) {
                $p = mb_substr($p, 0, -1);
            }
            $out[] = $p;
        }

        return $out;
    }

    private function toFloat($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /** 12 → « 12 », 0.5 → « 0,5 », 1.333 → « 1,33 ». */
    private function number(float $v): string
    {
        $s = number_format($v, 2, ',', ' ');
        return rtrim(rtrim($s, '0'), ',');
    }

    /** 90 → « 1 min 30 s », 5400 → « 1 h 30 », 1500 → « 25 min ». */
    public function durationLabel(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return $m > 0 ? sprintf('%d h %02d', $h, $m) : sprintf('%d h', $h);
        }
        if ($m > 0) {
            return $s > 0 ? sprintf('%d min %d s', $m, $s) : sprintf('%d min', $m);
        }
        return sprintf('%d s', $s);
    }
}
