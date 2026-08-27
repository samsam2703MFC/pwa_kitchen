<?php

namespace App\Kitchen\app\Services\Production;

/**
 * L'assemblage de l'écran « État période 1 ».
 *
 * ── Pur, comme le moteur ──
 * Il ne fait aucun appel : il reçoit ce que le dépôt a rapporté des endpoints
 * et compose les lignes de l'écran — prévu / vendu / écart, par produit, par
 * section. Vérifiable sans réseau (bin/period1-test.php).
 *
 * La prévision vient de ProductionForecastService (6 semaines pondérées, même
 * jour, même tranche, ruptures exclues). Le vendu vient des ventes réelles du
 * jour sur la tranche. L'écart = prévu − vendu (décision du 27/08) — et reste
 * inconnu si la prévision l'est, jamais « −vendu ».
 *
 * ── Ce qu'il ne masque pas ──
 * Un produit sans prévision exploitable garde sa ligne, avec « prévu » à null :
 * l'atelier voit qu'il se vend (le vendu est là) mais qu'on ne sait pas encore
 * le prévoir — c'est une information, pas un trou à cacher.
 */
class Period1Service
{
    public function __construct(
        private ProductionForecastService $forecast
    ) {}

    /**
     * Les lignes de la tranche, une par produit, triées par section puis nom.
     *
     * @param array<int|string, array<int, array{date: string, quantity: float|int, stockout?: bool}>> $historyByProduct
     *        Historique de ventes de la tranche, par produit (pour la prévision).
     * @param array<int|string, float> $soldByProduct
     *        Ventes réelles du jour sur la tranche, par produit.
     * @param array<int|string, array{name?: string, section?: string}> $meta
     *        Nom et section de chaque produit.
     * @param string $targetDate  Jour affiché (Y-m-d).
     * @param int    $weeks        Fenêtre d'historique (paramètre de base).
     *
     * @return array<int, array{product_id: int|string, name: string, section: string,
     *                          prevu: ?float, vendu: float, ecart: ?float}>
     */
    public function rows(
        array $historyByProduct,
        array $soldByProduct,
        array $meta,
        string $targetDate,
        int $weeks = ProductionForecastService::DEFAULT_WEEKS
    ): array {
        $forecasts = $this->forecast->forecastMany($historyByProduct, $targetDate, $weeks);

        // L'union des produits vus : ceux qu'on prévoit ET ceux qui se sont
        // vendus. Un produit vendu sans historique doit apparaître (prévu
        // inconnu) ; un produit prévu sans vente du jour aussi (vendu = 0).
        $ids = array_keys($soldByProduct) + array_keys($historyByProduct);
        $ids = array_values(array_unique(array_merge(array_keys($soldByProduct), array_keys($historyByProduct))));

        $rows = [];
        foreach ($ids as $id) {
            $prevu = $forecasts[$id] ?? null;
            $vendu = (float)($soldByProduct[$id] ?? 0.0);
            $rows[] = [
                'product_id' => $id,
                'name'       => (string)($meta[$id]['name'] ?? ('#' . $id)),
                'section'    => (string)($meta[$id]['section'] ?? ''),
                'prevu'      => $prevu,
                'vendu'      => $vendu,
                'ecart'      => ProductionForecastService::gap($prevu, $vendu),
            ];
        }

        // Tri par section puis nom ; la section vide (« Autres ») passe en
        // dernier plutot qu'en tete.
        usort($rows, static function (array $a, array $b): int {
            $sa = $a['section'] === '' ? "\xff" : mb_strtolower($a['section']);
            $sb = $b['section'] === '' ? "\xff" : mb_strtolower($b['section']);
            return [$sa, mb_strtolower($a['name'])] <=> [$sb, mb_strtolower($b['name'])];
        });

        return $rows;
    }

    /**
     * Sépare les lignes entre « à produire ce matin » et « à préparer la
     * veille » (PDM), d'après les drapeaux du catalogue.
     *
     * Pur : un produit dont le drapeau est absent du catalogue est un produit
     * du matin — l'absence d'un drapeau n'est pas une information de
     * préparation, et le back-office reste l'endroit où la donner.
     *
     * @param array<int, array{product_id: int|string}> $rows
     * @param array<int, array{pdm: bool}> $flags  id → drapeaux du catalogue
     * @return array{morning: array<int, array>, pdm: array<int, array>}
     */
    public function splitByPdm(array $rows, array $flags): array
    {
        $morning = [];
        $pdm     = [];
        foreach ($rows as $r) {
            $isPdm = $flags[(int)$r['product_id']]['pdm'] ?? false;
            if ($isPdm) {
                $pdm[] = $r;
            } else {
                $morning[] = $r;
            }
        }

        return ['morning' => $morning, 'pdm' => $pdm];
    }

    /**
     * La liste de recuisson — le « caller » de l'après-midi.
     *
     * À tout moment de la journée : reste à vendre = prévu − vendu jusqu'ici.
     * Les ventes servies par l'endpoint du jour sont CUMULATIVES (vérifié en
     * réel le 27/08 : 8 baguettes à 7 h 39 contre 36–58 un jeudi complet), le
     * même calcul vaut donc du matin au soir. On ne garde que les manques
     * connus et positifs, du plus urgent au moins urgent — c'est une file de
     * lancement, pas un état du catalogue.
     *
     * @param array<int, array{product_id: int|string, name: string,
     *                         ecart: ?float}> $rows
     * @param array<int, array{shelf_minutes: ?int, reheat_minutes: ?int,
     *                         reheat_celsius: ?int}> $flags
     * @return array<int, array>  les lignes en manque, écart décroissant,
     *                            enrichies de shelf/reheat quand le catalogue
     *                            les donne.
     */
    public function reheatQueue(array $rows, array $flags): array
    {
        // Le besoin fait foi quand les commandes l'ont relevé ; sinon l'écart.
        $needOf = static fn(array $r): ?float => array_key_exists('need', $r) ? $r['need'] : $r['ecart'];
        $queue = array_values(array_filter(
            $rows,
            static fn(array $r): bool => $needOf($r) !== null && $needOf($r) > 0
        ));
        usort($queue, static fn(array $a, array $b): int =>
            [-$needOf($a), mb_strtolower($a['name'])] <=> [-$needOf($b), mb_strtolower($b['name'])]);

        foreach ($queue as &$r) {
            $f = $flags[(int)$r['product_id']] ?? null;
            $r['shelf_minutes']  = $f['shelf_minutes']  ?? null;
            $r['reheat_minutes'] = $f['reheat_minutes'] ?? null;
            $r['reheat_celsius'] = $f['reheat_celsius'] ?? null;
            $r['stock']          = $f['stock']          ?? null;
        }
        unset($r);

        return $queue;
    }

    /** Bornes des classes de conservation, en minutes. */
    public const SHELF_SHORT_MAX  = 720;    // ≤ 12 h : à écouler le jour même
    public const SHELF_MEDIUM_MAX = 2880;   // ≤ 48 h : report possible

    /**
     * La classe de conservation d'un produit — courte, moyenne, longue.
     *
     * Bâtie sur `shelf_life_minutes`, bien tenue au catalogue (582/583 au
     * relevé du 27/08), PAS sur `shelf_life_category`, presque uniformément
     * « SSL » (569/583) donc muette. null = tenue inconnue.
     */
    public static function shelfClass(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        if ($minutes <= self::SHELF_SHORT_MAX) {
            return 'courte';
        }

        return $minutes <= self::SHELF_MEDIUM_MAX ? 'moyenne' : 'longue';
    }

    /**
     * La vue « stock » : TOUT le catalogue, par secteur puis par classe de
     * conservation, le plus périssable en tête.
     *
     * Le secteur vient des ventes (`group_name`, fiable) : un produit jamais
     * croisé dans les ventes tombe sous « Autres » — l'écran le dit plutôt
     * que d'inventer un rayon. Chaque ligne porte ce que le jour sait d'elle
     * (vendu, besoin, réservé) quand elle est dans l'assemblage du jour.
     *
     * @param array<int, array{name: string, shelf_minutes: ?int}> $catalog
     *        Le catalogue (drapeaux + nom), par identifiant produit.
     * @param array<int, string> $sectionOf  id → secteur connu des ventes.
     * @param array<int, array> $todayById   id → ligne du jour (si vue).
     * @return array<int, array{section: string, counts: array<string, int>,
     *                          rows: array<int, array>}>
     */
    public function stockPanels(array $catalog, array $sectionOf, array $todayById): array
    {
        $panels = [];
        foreach ($catalog as $id => $c) {
            $section = $sectionOf[$id] ?? '';
            $class   = self::shelfClass($c['shelf_minutes'] ?? null);
            $today   = $todayById[$id] ?? null;
            $row = [
                'product_id'    => $id,
                'name'          => $c['name'] ?? ('#' . $id),
                'shelf_minutes' => $c['shelf_minutes'] ?? null,
                'class'         => $class,
                'vendu'         => $today['vendu'] ?? null,
                'need'          => $today['need'] ?? ($today['ecart'] ?? null),
                'reserved'      => $today['reserved'] ?? 0,
            ];
            if (!isset($panels[$section])) {
                $panels[$section] = ['section' => $section, 'counts' => [], 'rows' => []];
            }
            $key = $class ?? 'inconnue';
            $panels[$section]['counts'][$key] = ($panels[$section]['counts'][$key] ?? 0) + 1;
            $panels[$section]['rows'][] = $row;
        }

        // Dans chaque secteur : courte, puis moyenne, longue, inconnue — et à
        // classe égale, la tenue la plus courte d'abord.
        $rank = ['courte' => 0, 'moyenne' => 1, 'longue' => 2];
        foreach ($panels as &$p) {
            usort($p['rows'], static fn(array $a, array $b): int =>
                [$rank[$a['class']] ?? 3, $a['shelf_minutes'] ?? PHP_INT_MAX, mb_strtolower($a['name'])]
                <=> [$rank[$b['class']] ?? 3, $b['shelf_minutes'] ?? PHP_INT_MAX, mb_strtolower($b['name'])]);
        }
        unset($p);

        $panels = array_values($panels);
        usort($panels, static fn(array $a, array $b): int =>
            [$a['section'] === '' ? "\xff" : mb_strtolower($a['section'])]
            <=> [$b['section'] === '' ? "\xff" : mb_strtolower($b['section'])]);

        return $panels;
    }

    /**
     * Applique les commandes clients aux lignes du jour.
     *
     * Une commande ferme est une demande CERTAINE : le besoin de cuisson
     * devient max(prévision restante, réservé) — on ne cuit jamais moins que
     * ce que les clients viennent chercher. Et la vente libre restante, c'est
     * ce qui reste après avoir mis les commandes de côté.
     *
     *   need     — combien il faut encore cuire (null si rien ne l'exige)
     *   reserved — pièces commandées, pas encore retirées
     *   libre    — reste pour la vente libre (écart − réservé, plancher 0)
     *
     * Un produit commandé mais absent de la prévision entre dans la liste :
     * la commande est un fait, pas une estimation.
     *
     * @param array<int, array{product_id: int|string, name: string, section: string,
     *                         prevu: ?float, vendu: float, ecart: ?float}> $rows
     * @param array<int, array{id: int, name: string, qty: float}> $orderLines
     * @return array<int, array>
     */
    public function applyReservations(array $rows, array $orderLines): array
    {
        $reserved = [];
        $names    = [];
        foreach ($orderLines as $l) {
            $reserved[(int)$l['id']] = ($reserved[(int)$l['id']] ?? 0.0) + (float)$l['qty'];
            $names[(int)$l['id']]    = (string)$l['name'];
        }

        $seen = [];
        foreach ($rows as &$r) {
            $id  = (int)$r['product_id'];
            $res = $reserved[$id] ?? 0.0;
            $seen[$id]     = true;
            $r['reserved'] = $res;
            $r['libre']    = $r['ecart'] === null ? null : max(0.0, $r['ecart'] - $res);
            $r['need']     = $res > 0
                ? max($r['ecart'] ?? 0.0, $res)
                : $r['ecart'];
        }
        unset($r);

        // Les produits commandés que la prévision ne connaît pas.
        foreach ($reserved as $id => $qty) {
            if (isset($seen[$id]) || $qty <= 0) {
                continue;
            }
            $rows[] = [
                'product_id' => $id,
                'name'       => $names[$id] ?? ('#' . $id),
                'section'    => '',
                'prevu'      => null,
                'vendu'      => 0.0,
                'ecart'      => null,
                'reserved'   => $qty,
                'libre'      => null,
                'need'       => $qty,
            ];
        }

        return $rows;
    }

    /**
     * La liste du soir : prévision de demain ET commandes fermes de demain,
     * pour les produits à préparer la veille.
     *
     * Le besoin par produit = max(prévision, commandé) — une commande de 20
     * quand la prévision en voit 14 impose 20. Un produit PDM commandé pour
     * demain mais sans prévision entre dans la liste, avec la commande pour
     * seul besoin.
     *
     * @param array<int, array> $pdmRows  les lignes PDM de demain (prévision)
     * @param array<int, array{id: int, name: string, qty: float}> $orderLines
     * @param array<int, array{pdm: bool}> $flags
     * @return array<int, array>  besoin décroissant.
     */
    public function tomorrowPrep(array $pdmRows, array $orderLines, array $flags): array
    {
        $ordered = [];
        $names   = [];
        foreach ($orderLines as $l) {
            $ordered[(int)$l['id']] = ($ordered[(int)$l['id']] ?? 0.0) + (float)$l['qty'];
            $names[(int)$l['id']]   = (string)$l['name'];
        }

        $seen = [];
        foreach ($pdmRows as &$r) {
            $id  = (int)$r['product_id'];
            $qty = $ordered[$id] ?? 0.0;
            $seen[$id]    = true;
            $r['ordered'] = $qty;
            $r['need']    = max($r['ecart'] ?? 0.0, $qty);
        }
        unset($r);

        foreach ($ordered as $id => $qty) {
            if (isset($seen[$id]) || $qty <= 0 || !($flags[$id]['pdm'] ?? false)) {
                continue;
            }
            $pdmRows[] = [
                'product_id' => $id,
                'name'       => $names[$id] ?? ('#' . $id),
                'section'    => '',
                'prevu'      => null,
                'vendu'      => 0.0,
                'ecart'      => null,
                'ordered'    => $qty,
                'need'       => $qty,
            ];
        }

        $pdmRows = array_values(array_filter($pdmRows, static fn(array $r): bool => ($r['need'] ?? 0) > 0));
        usort($pdmRows, static fn(array $a, array $b): int =>
            [-$a['need'], mb_strtolower($a['name'])] <=> [-$b['need'], mb_strtolower($b['name'])]);

        return $pdmRows;
    }

    /**
     * Le plan « par four » : les fournées mixtes que les groupes de batch
     * autorisent.
     *
     * Deux produits qui partagent un groupe de batch (même four, même
     * réglage) peuvent cuire ENSEMBLE — c'est exactement ce que le back
     * impose dans les gammes. On remplit chaque fournée par urgence
     * (le plus gros besoin d'abord) jusqu'à la capacité du groupe.
     *
     * @param array<int, array> $queue  la file enrichie (prep + besoin).
     * @return array<int, array{group: string, capacity: int,
     *                          items: array<int, array{name: string, take: float, need: float}>,
     *                          total: float, fournees: int}>
     */
    public function ovenPlan(array $queue): array
    {
        $groups = [];
        foreach ($queue as $r) {
            $need = $r['need'] ?? $r['ecart'] ?? null;
            $prep = $r['prep'] ?? null;
            if ($need === null || $need <= 0 || !is_array($prep)) {
                continue;
            }
            foreach ($prep['steps'] ?? [] as $st) {
                if (empty($st['oven']) || empty($st['batch_group'])) {
                    continue;
                }
                $g = (string)$st['batch_group'];
                $cap = (int)($st['batch_capacity'] ?? 0);
                if (!isset($groups[$g])) {
                    $groups[$g] = ['group' => $g, 'capacity' => $cap, 'items' => [], 'total' => 0.0];
                }
                $groups[$g]['capacity'] = max($groups[$g]['capacity'], $cap);
                $groups[$g]['items'][] = ['name' => (string)$r['name'], 'need' => (float)$need];
                $groups[$g]['total'] += (float)$need;
                break;   // un produit compte une fois par groupe
            }
        }

        foreach ($groups as &$g) {
            usort($g['items'], static fn(array $a, array $b): int =>
                [-$a['need'], mb_strtolower($a['name'])] <=> [-$b['need'], mb_strtolower($b['name'])]);
            // La première fournée mixte : remplir par urgence jusqu'à la capacité.
            $left = $g['capacity'] > 0 ? (float)$g['capacity'] : $g['total'];
            foreach ($g['items'] as &$it) {
                $it['take'] = min($it['need'], max(0.0, $left));
                $left -= $it['take'];
            }
            unset($it);
            $g['fournees'] = $g['capacity'] > 0 ? (int)ceil($g['total'] / $g['capacity']) : 1;
        }
        unset($g);

        $out = array_values($groups);
        usort($out, static fn(array $a, array $b): int => [-$a['total']] <=> [-$b['total']]);

        return $out;
    }

    /**
     * Le bilan de la journée, côté chiffres servis.
     *
     * Les gestes locaux (cuit, jeté, reporté) vivent sur la tablette : le
     * navigateur les ajoute lui-même. Ici, la part vérifiable sans réseau :
     * vendu et prévu du jour.
     *
     * @param array<int, array{prevu: ?float, vendu: float}> $rows
     * @return array{vendu: float, prevu: float}
     */
    public function dayReportBase(array $rows): array
    {
        $vendu = 0.0;
        $prevu = 0.0;
        foreach ($rows as $r) {
            $vendu += $r['vendu'];
            if ($r['prevu'] !== null) {
                $prevu += $r['prevu'];
            }
        }

        return ['vendu' => $vendu, 'prevu' => $prevu];
    }

    /**
     * La chronologie d'une gamme : quand chaque étape finit, et quand chaque
     * fournée sort, si on lance MAINTENANT.
     *
     * Pur : tout est en secondes depuis le lancement, le contrôleur y ajoute
     * l'heure courante. Les fournées passent au four EN SÉQUENCE (un four) :
     * la fournée k occupe le four sitôt la k−1 sortie, et refait les étapes
     * d'après-four. D'où : prête(k) = avant-four + k × four + après-four.
     * Sans étape de four, une seule sortie : la durée totale de la gamme.
     *
     * @param array<int, array{seconds: ?int, oven: bool}> $steps  la gamme servie
     * @param int $fournees  combien de fournées à lancer (≥ 1)
     * @return array{step_ends: array<int, ?int>, batch_ready: array<int, int>}
     *         step_ends : fin de chaque étape (fournée 1), null si durée inconnue ;
     *         batch_ready : sortie de chaque fournée.
     */
    public function ovenTimeline(array $steps, int $fournees): array
    {
        $fournees = max(1, $fournees);

        // Fin de chaque étape pour la première fournée — cumul simple. Une
        // durée absente rend la fin inconnue, et toutes les suivantes avec.
        $stepEnds = [];
        $cum = 0;
        $known = true;
        foreach ($steps as $st) {
            $sec = $st['seconds'] ?? null;
            if ($sec === null) {
                $known = false;
            }
            $cum += (int)($sec ?? 0);
            $stepEnds[] = $known ? $cum : null;
        }

        // Position du four dans la gamme : avant / four / après.
        $pre = 0; $oven = 0; $post = 0; $seen = false;
        foreach ($steps as $st) {
            $sec = (int)($st['seconds'] ?? 0);
            if (!$seen && !empty($st['oven'])) {
                $oven = $sec;
                $seen = true;
            } elseif (!$seen) {
                $pre += $sec;
            } else {
                $post += $sec;
            }
        }

        $ready = [];
        if (!$seen || $oven <= 0) {
            // Pas de four : une seule « sortie », la fin de la gamme.
            $ready[] = $cum;
        } else {
            for ($k = 1; $k <= $fournees; $k++) {
                $ready[] = $pre + $k * $oven + $post;
            }
        }

        return ['step_ends' => $stepEnds, 'batch_ready' => $ready];
    }

    /**
     * Les volumes du jour, pour le bandeau de la recuisson.
     *
     * Trois familles de chiffres, toutes issues du même assemblage :
     * les STATS (prévu connu, vendu, % réalisé), le RESTE (somme des manques
     * connus et positifs — ce que la file porte), et le PLAN de recuisson
     * (fournées suggérées, et pièces correspondantes quand le four a une
     * capacité). Pur : rien d'autre que les lignes déjà assemblées.
     *
     * @param array<int, array{prevu: ?float, vendu: float, ecart: ?float}> $rows
     *        TOUTES les lignes du jour (pas seulement la file).
     * @param array<int, array{ecart: ?float, fournees?: ?int,
     *                         prep?: ?array{oven_capacity: ?int}}> $queue
     *        La file de recuisson enrichie.
     * @return array{prevu: float, vendu: float, pct: ?int, reste: float,
     *               fournees: int, pieces: float}
     */
    public function volumesOfDay(array $rows, array $queue): array
    {
        $prevu = 0.0;
        $vendu = 0.0;
        foreach ($rows as $r) {
            if ($r['prevu'] !== null) {
                $prevu += $r['prevu'];
            }
            $vendu += $r['vendu'];
        }

        $reste = 0.0;
        $fournees = 0;
        $pieces = 0.0;
        foreach ($queue as $r) {
            if (($r['ecart'] ?? null) !== null && $r['ecart'] > 0) {
                $reste += $r['ecart'];
            }
            $n = $r['fournees'] ?? null;
            if ($n !== null && $n > 0) {
                $fournees += $n;
                $cap = $r['prep']['oven_capacity'] ?? null;
                if ($cap !== null && $cap > 0) {
                    $pieces += $n * $cap;
                }
            }
        }

        return [
            'prevu'    => $prevu,
            'vendu'    => $vendu,
            'pct'      => $prevu > 0 ? (int)round(100 * $vendu / $prevu) : null,
            'reste'    => $reste,
            'fournees' => $fournees,
            'pieces'   => $pieces,
        ];
    }

    /**
     * Les candidats à la vente flash : tenue courte ET encore au-dessus de la
     * prévision.
     *
     * Sans lots horodatés côté API (aucun GET de mouvements produits), on ne
     * sait pas QUEL plateau expire quand. Ce qu'on sait de source sûre : la
     * tenue du produit (catalogue) et ce qui reste à vendre (prévision −
     * vendu). Un produit qui tient 12 h et traîne au-dessus de sa prévision
     * est le premier à solder — c'est cette liste-là.
     *
     * @param array<int, array{ecart: ?float, shelf_minutes?: ?int}> $rows
     *        Les lignes de la file de recuisson (déjà enrichies des drapeaux).
     * @param int $maxShelfMinutes  Tenue maximale pour être « courte ».
     * @return array<int, array>  tenue croissante puis écart décroissant.
     */
    public function flashCandidates(array $rows, int $maxShelfMinutes = 720): array
    {
        $out = array_values(array_filter(
            $rows,
            static fn(array $r): bool => ($r['ecart'] ?? null) !== null && $r['ecart'] > 0
                && ($r['shelf_minutes'] ?? null) !== null && $r['shelf_minutes'] <= $maxShelfMinutes
        ));
        usort($out, static fn(array $a, array $b): int =>
            [$a['shelf_minutes'], -$a['ecart']] <=> [$b['shelf_minutes'], -$b['ecart']]);

        return $out;
    }

    /**
     * Le total de la tranche : combien il reste à produire, section par section.
     *
     * On ne somme que les écarts POSITIFS connus : un produit en avance
     * (écart négatif) ne compense pas un produit en retard — on ne détruit pas
     * ce qui est déjà en vitrine. Un écart inconnu ne compte pas.
     *
     * @param array<int, array{section: string, ecart: ?float}> $rows
     * @return array{by_section: array<string, float>, total: float, unknown: int}
     */
    public function toProduce(array $rows): array
    {
        $bySection = [];
        $total     = 0.0;
        $unknown   = 0;
        foreach ($rows as $r) {
            if ($r['ecart'] === null) {
                $unknown++;
                continue;
            }
            if ($r['ecart'] > 0) {
                $sec = $r['section'];
                $bySection[$sec] = ($bySection[$sec] ?? 0.0) + $r['ecart'];
                $total += $r['ecart'];
            }
        }

        return ['by_section' => $bySection, 'total' => $total, 'unknown' => $unknown];
    }
}
