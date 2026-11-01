<?php

namespace App\Kitchen\app\Services\Knowledge\Product;

/**
 * LA MISE SUR PLAQUE — le schéma d'une plaque de cuisson garnie, dessiné pour le pas à pas.
 *
 * La disposition vient de l'étape de la fiche (« Mise sur plaque 3 x 3 ») ; ce qu'on pose dessus
 * prend la forme du produit, reconnue à son nom et à sa catégorie, pour les familles du réseau :
 *   baguette   Baguette Tradition, ficelle, flûte
 *   eclair     éclairs
 *   croissant  Croissant, Mini - Croissant, Croissant Amandes
 *   cookie     Cookies, Damier, Leopard, Swirl
 *   tart       Quiches, Tartes, Tartissières, Flans, Entremets, Croûtes, Frangipanes (moule rond)
 *   cake       Cakes, Brownies, Brookie (moule rectangulaire)
 *   focaccia   Focaccia
 *   viennoiserie  Pain au Chocolat, Couques, Suisse, Tortillon, Mini - Chocolat
 *   roll       Pistolet, petits pains, Mini viennoiseries rondes, Huit, Baulus, Craquelin, choux
 *   loaf       Pain, Pavé, Bâtard, Fermier, Cramique, Seigle, Demi-Gris, 10 Céréales
 *   piece      tout le reste
 *
 * La plaque est une plaque 600 × 400 (format boulanger) vue de dessus ; le plus grand des deux
 * nombres va sur la longueur. Chaque pièce s'oriente dans le sens long de sa case.
 */
class TrayLayout
{
    public const MAX = 12;

    private const W = 600;
    private const H = 400;
    private const MARGIN = 22;

    /** L'ordre compte : « Pain au Chocolat » est une viennoiserie avant d'être un pain. */
    private const SHAPES = [
        'baguette' => ['baguette', 'ficelle', 'flute', 'stokbrood', 'bagietk'],
        'eclair' => ['eclair'],
        'croissant' => ['croissant', 'rogal'],
        'cookie' => ['cookie', 'damier', 'leopard', 'swirl', 'biscuit', 'sable'],
        'tart' => ['quiche', 'tarte', 'tartissiere', 'flan', 'entremet', 'croute', 'frangipane', 'merveilleux', 'tartelette', '⌀', 'ø'],
        'cake' => ['cake', 'brownie', 'brookie'],
        'focaccia' => ['focaccia'],
        'viennoiserie' => ['pain au chocolat', 'chocolatine', 'couque', 'suisse', 'tortillon', 'mini - chocolat', 'chocolat nappe'],
        'roll' => ['pistolet', 'petit pain', 'mini', 'huit', 'baulus', 'craquelin', 'choux', 'bun', 'muffin', 'bulka'],
        'loaf' => ['pain', 'pave', 'batard', 'fermier', 'cramique', 'seigle', 'boule', 'miche', 'brood', 'chleb'],
    ];

    public function shapeFor(?string ...$texts): string
    {
        $hay = $this->normalize(implode(' | ', array_filter($texts)));
        foreach (self::SHAPES as $shape => $words) {
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($this->normalize($w), '/') . '/u', $hay)) {
                    return $shape;
                }
            }
        }
        return 'piece';
    }

    /**
     * La disposition : le plus grand nombre sur la longueur de la plaque.
     * @return array{cols:int, rows:int, per_tray:int}
     */
    public function grid(int $a, int $b): array
    {
        $cols = max($a, $b);
        $rows = min($a, $b);
        return ['cols' => $cols, 'rows' => $rows, 'per_tray' => $cols * $rows];
    }

    /** Le dessin SVG de la plaque garnie — que des nombres et du balisage fixe, sûr à injecter tel quel. */
    public function svg(int $cols, int $rows, string $shape): string
    {
        $cols = max(1, min(self::MAX, $cols));
        $rows = max(1, min(self::MAX, $rows));
        $m = self::MARGIN;
        $cw = (self::W - 2 * $m) / $cols;
        $ch = (self::H - 2 * $m) / $rows;

        $out = '<svg class="pb-tray-svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" xmlns="http://www.w3.org/2000/svg" role="img">';
        $out .= '<defs><pattern id="pbPerf" width="16" height="16" patternUnits="userSpaceOnUse">'
            . '<circle cx="8" cy="8" r="2.2" fill="#aeb4ba"/></pattern></defs>';
        // la plaque : tôle perforée, bord relevé
        $out .= '<rect x="3" y="3" width="' . (self::W - 6) . '" height="' . (self::H - 6) . '" rx="18" fill="#cfd4d9" stroke="#8f979f" stroke-width="6"/>';
        $out .= '<rect x="14" y="14" width="' . (self::W - 28) . '" height="' . (self::H - 28) . '" rx="10" fill="url(#pbPerf)"/>';

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $cx = $m + $cw * ($c + 0.5);
                $cy = $m + $ch * ($r + 0.5);
                $out .= $this->piece($shape, $cx, $cy, $cw, $ch);
            }
        }

        return $out . '</svg>';
    }

    /** Une pièce, centrée dans sa case, allongée dans le sens long de la case. */
    private function piece(string $shape, float $cx, float $cy, float $cw, float $ch): string
    {
        $long = max($cw, $ch);
        $short = min($cw, $ch);
        $rot = $ch > $cw ? 90 : 0;          // case verticale : la pièce tourne
        $g = fn (string $body) => sprintf('<g transform="translate(%s %s) rotate(%d)">%s</g>', $this->n($cx), $this->n($cy), $rot, $body);

        $crust = 'fill="#d9a35b" stroke="#a8702f" stroke-width="2"';
        $score = 'stroke="#f2d49b" stroke-width="3" stroke-linecap="round" fill="none"';

        switch ($shape) {
            case 'baguette':
            case 'eclair':
                $l = $long * 0.88;
                $t = min($short * ($shape === 'eclair' ? 0.32 : 0.42), $l * 0.24);
                $body = $this->rect(-$l / 2, -$t / 2, $l, $t, $t / 2, $shape === 'eclair' ? 'fill="#c98f4a" stroke="#93602a" stroke-width="2"' : $crust);
                if ($shape === 'eclair') {
                    $body .= $this->rect(-$l * 0.42, -$t * 0.3, $l * 0.84, $t * 0.6, $t * 0.3, 'fill="#5b3a29"');
                } else {
                    $n = 3;
                    for ($i = 0; $i < $n; $i++) {
                        $x = -$l / 2 + $l * ($i + 0.5) / $n;
                        $body .= $this->line($x - $l * 0.08, $t * 0.22, $x + $l * 0.08, -$t * 0.22, $score);
                    }
                }
                return $g($body);

            case 'loaf':
                $rx = min($long * 0.44, $short * 0.62);
                $ry = min($short * 0.40, $rx * 0.72);
                return $g($this->ellipse(0, 0, $rx, $ry, $crust) . $this->line(-$rx * 0.6, 0, $rx * 0.6, 0, $score));

            case 'roll':
                $r = $short * 0.36;
                return $g($this->ellipse(0, 0, $r, $r, $crust) . $this->line(-$r * 0.5, 0, $r * 0.5, 0, $score));

            case 'croissant':
                $s = min($long * 0.9 / 100, $short * 0.8 / 50);
                $path = 'M -46 14 C -40 -26, 40 -26, 46 14 C 38 20, 32 20, 26 12 C 16 -4, -16 -4, -26 12 C -32 20, -38 20, -46 14 Z';
                $lines = '<path d="M -22 -10 Q -14 2 -18 10 M 0 -16 Q 4 -4 0 4 M 22 -10 Q 14 2 18 10" ' . str_replace('stroke-width="3"', 'stroke-width="2.5"', $score) . '/>';
                return $g(sprintf('<g transform="scale(%s)"><path d="%s" fill="#d9a35b" stroke="#a8702f" stroke-width="2.4"/>%s</g>', $this->n($s), $path, $lines));

            case 'viennoiserie':
                $l = min($long * 0.78, $short * 1.5);
                $t = min($short * 0.62, $l * 0.7);
                return $g($this->rect(-$l / 2, -$t / 2, $l, $t, 6, $crust)
                    . $this->line(-$l * 0.36, -$t * 0.18, $l * 0.36, -$t * 0.18, 'stroke="#5b3a29" stroke-width="4" stroke-linecap="round"')
                    . $this->line(-$l * 0.36, $t * 0.18, $l * 0.36, $t * 0.18, 'stroke="#5b3a29" stroke-width="4" stroke-linecap="round"'));

            case 'cake':
                $l = min($long * 0.86, $short * 2.4);
                $t = min($short * 0.6, $l * 0.5);
                return $g($this->rect(-$l / 2, -$t / 2, $l, $t, 4, 'fill="#7d858c" stroke="#5f666c" stroke-width="2"')
                    . $this->rect(-$l / 2 + 5, -$t / 2 + 5, $l - 10, $t - 10, 3, 'fill="#c98f4a"')
                    . $this->line(-$l * 0.32, 0, $l * 0.32, 0, 'stroke="#8a5a26" stroke-width="3" stroke-linecap="round"'));

            case 'focaccia':
                $l = $long * 0.86;
                $t = min($short * 0.8, $l * 0.8);
                $dots = '';
                foreach ([[-0.25, -0.2], [0.05, -0.25], [0.3, -0.1], [-0.3, 0.2], [0, 0.15], [0.25, 0.25]] as [$dx, $dy]) {
                    $dots .= $this->ellipse($l * $dx, $t * $dy, 3.5, 3.5, 'fill="#4f6b2b"');
                }
                return $g($this->rect(-$l / 2, -$t / 2, $l, $t, 10, 'fill="#e2b56a" stroke="#a8702f" stroke-width="2"') . $dots);

            case 'tart':
                $r = $short * 0.42;
                return $g($this->ellipse(0, 0, $r, $r, 'fill="#c98f4a" stroke="#a8702f" stroke-width="5" stroke-dasharray="5 4"')
                    . $this->ellipse(0, 0, $r * 0.78, $r * 0.78, 'fill="#f0cf72" stroke="#d9a35b" stroke-width="2"'));

            case 'cookie':
                $r = $short * 0.36;
                $chips = '';
                foreach ([[-0.35, -0.3], [0.3, -0.35], [0.05, 0.05], [-0.3, 0.35], [0.35, 0.3]] as [$dx, $dy]) {
                    $chips .= $this->ellipse($r * $dx, $r * $dy, max(2.5, $r * 0.11), max(2.5, $r * 0.11), 'fill="#5b3a29"');
                }
                return $g($this->ellipse(0, 0, $r, $r, 'fill="#dcb170" stroke="#a8702f" stroke-width="2"') . $chips);

            default:
                $s = $short * 0.68;
                return $g($this->rect(-$s / 2, -$s / 2, $s, $s, $s * 0.22, $crust));
        }
    }

    private function rect(float $x, float $y, float $w, float $h, float $rx, string $attrs): string
    {
        return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" %s/>', $this->n($x), $this->n($y), $this->n($w), $this->n($h), $this->n($rx), $attrs);
    }

    private function ellipse(float $cx, float $cy, float $rx, float $ry, string $attrs): string
    {
        return sprintf('<ellipse cx="%s" cy="%s" rx="%s" ry="%s" %s/>', $this->n($cx), $this->n($cy), $this->n($rx), $this->n($ry), $attrs);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $attrs): string
    {
        return sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" %s/>', $this->n($x1), $this->n($y1), $this->n($x2), $this->n($y2), $attrs);
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, [
            'œ' => 'oe', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ł' => 'l', 'ó' => 'o',
        ]);
    }
}
