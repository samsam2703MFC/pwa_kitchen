<?php

namespace App\Kitchen\app\Services\Knowledge\Preparation;

use App\Kitchen\app\Repositories\Knowledge\Preparation\PreparationPathRepository;

/**
 * Le parcours de préparation, mis en forme pour l'écran.
 *
 * ── Rien n'est inventé ──
 * Si la route ne répond pas, le service ne rend AUCUNE étape et nomme la
 * route ; l'écran l'affiche dans son bandeau. Il ne recompose pas un parcours
 * depuis la fiche technique : deux sources qui se contredisent sur une
 * tablette, c'est précisément le défaut qu'on cherche à retirer.
 *
 * ── Trois états, et ils ne se ressemblent pas ──
 *   served     — le produit a un parcours, le voici
 *   unconfigured — la route répond, ce produit n'a pas de parcours. Un fait,
 *                  qui se règle au back-office.
 *   missing    — la route n'a pas répondu. Se règle chez le développeur.
 *
 * ── La forme est CONNUE, les lecteurs sont refermés ──
 * Confirmée le 26/08/2026 au swagger de test (test.tfbuddy.com/docs, schéma
 * ProductPreparationStep) : id, sort_order, description, duration_seconds,
 * uses_oven, batch_group_id, batch_group_name, batch_capacity,
 * products_per_tray, trays_per_oven, photo_1_url..photo_3_url. Les
 * orthographes de repli acceptées pendant que la forme était inconnue ont été
 * retirées — une liste ouverte finit par masquer un changement de contrat.
 * Une étape sans description reste écartée ET comptée : un parcours tronqué
 * qui a l'air complet est pire qu'un parcours qui manque.
 *
 * Les règles de lecture sont pures et vérifiées sans réseau : bin/preparation-test.php.
 */
class PreparationPathService
{
    public function __construct(
        private PreparationPathRepository $repository
    ) {}

    /** La route qui n'a pas répondu au dernier appel, ou null. */
    private ?string $missing = null;

    public function missingApi(): ?string
    {
        return $this->missing;
    }

    /**
     * Le parcours d'un produit, prêt à afficher.
     *
     * @return array{state: string, steps: array<int, array>, total_seconds: int,
     *               total: ?string, unreadable: int, missing: ?string}
     */
    public function forProduct(int $productId): array
    {
        $this->missing = null;

        $data = $this->repository->get($productId);
        if ($data === null) {
            $this->missing = 'GET /products/' . $productId . '/preparation-path';
            return self::none('missing', $this->missing);
        }

        // `configured: false` est une réponse, pas un trou. On la lit telle
        // quelle plutôt que de la déduire d'une liste vide : un parcours
        // configuré mais sans étape existe, et ne dit pas la même chose.
        if (array_key_exists('configured', $data) && !$data['configured']) {
            return self::none('unconfigured', null);
        }

        $steps = self::steps($data);
        if ($steps === [] && !array_key_exists('configured', $data)) {
            // Ni `configured`, ni étape lisible : la route répond, mais pas
            // dans la forme attendue. Le dire évite de chercher au back-office
            // une configuration qui y est peut-être déjà.
            $this->missing = 'GET /products/' . $productId
                . '/preparation-path — réponse sans étape ni indicateur « configured »';
            return self::none('missing', $this->missing);
        }

        return [
            'state'         => $steps === [] ? 'unconfigured' : 'served',
            'steps'         => $steps,
            'total_seconds' => $total = array_sum(array_column($steps, 'seconds')),
            'total'         => self::humanDuration($total),
            'unreadable'    => self::unreadable($data),
            'missing'       => null,
        ];
    }

    /**
     * Le résumé du parcours de chaque produit d'une liste, pour la production.
     *
     * L'écran des besoins n'a pas la place d'un parcours entier : il veut une
     * ligne — la durée totale, et la capacité du four. On ne demande le
     * parcours QU'AUX produits que la route des identifiants déclare
     * configurés : un appel réseau par produit configuré de la liste, jamais
     * un appel par produit affiché.
     *
     * @param array<int, int> $productIds
     * @return array{available: bool, missing: ?string,
     *               map: array<int, array{total: ?string, oven: ?string, capacity: ?int}>}
     *         available=false : la route des identifiants n'a pas répondu, et
     *         `missing` la nomme. map n'invente rien : un produit configuré
     *         dont le parcours ne répond pas est simplement absent de la map.
     */
    public function summaries(array $productIds): array
    {
        $configured = $this->repository->configuredProductIds();
        if ($configured === null) {
            return ['available' => false,
                    'missing'   => 'GET /preparation-paths/configured-product-ids',
                    'map'       => []];
        }

        $map = [];
        foreach (array_intersect(array_map('intval', $productIds), $configured) as $id) {
            $path = $this->forProduct($id);
            if ($path['state'] !== 'served') {
                continue;
            }
            $oven = null;
            $capacity = null;
            foreach ($path['steps'] as $step) {
                if ($step['oven']) {
                    $capacity = $step['batch_capacity'];
                    if ($step['per_tray'] !== null && $step['trays'] !== null) {
                        $oven = $step['per_tray'] . ' × ' . $step['trays'];
                    }
                    break;
                }
            }
            $map[$id] = ['total' => $path['total'], 'oven' => $oven, 'capacity' => $capacity];
        }

        return ['available' => true, 'missing' => null, 'map' => $map];
    }

    /** @return array{state: string, steps: array, total_seconds: int, total: ?string, unreadable: int, missing: ?string} */
    private static function none(string $state, ?string $missing): array
    {
        return ['state' => $state, 'steps' => [], 'total_seconds' => 0, 'total' => null,
                'unreadable' => 0, 'missing' => $missing];
    }

    /**
     * Les étapes d'une réponse, dans l'ordre, numérotées.
     *
     * L'ordre vient du back — il a une route dédiée pour le persister
     * (`PATCH …/steps/order`). On respecte donc l'ordre servi et on ne trie
     * que si les lignes portent un rang explicite : réordonner de sa propre
     * initiative ferait exécuter les gestes dans le mauvais sens.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{n: int, text: string, seconds: ?int, duration: ?string, oven: bool,
     *                          batch_group: ?string, batch_capacity: ?int,
     *                          per_tray: ?int, trays: ?int, photos: array<int, string>}>
     */
    public static function steps(array $data): array
    {
        $rows = self::rowsOf($data);

        $ranked = [];
        $hasRank = false;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rank = isset($row['sort_order']) && is_numeric($row['sort_order'])
                ? (int)$row['sort_order'] : null;
            $hasRank = $hasRank || $rank !== null;
            $ranked[] = ['rank' => $rank ?? $i, 'seq' => $i, 'row' => $row];
        }

        if ($hasRank) {
            usort($ranked, fn($a, $b) => [$a['rank'], $a['seq']] <=> [$b['rank'], $b['seq']]);
        }

        $out = [];
        $n   = 0;
        foreach ($ranked as $entry) {
            $row  = $entry['row'];
            $text = self::textOf($row);
            if ($text === '') {
                // Une étape sans instruction lisible n'est pas affichable : la
                // montrer vide ferait croire à un geste sans consigne. Elle est
                // comptée par unreadable(), et l'écran dit combien.
                continue;
            }

            $out[] = [
                'n'              => ++$n,
                'text'           => $text,
                'seconds'        => $seconds = self::intOf($row, ['duration_seconds']),
                // Rendue ici plutôt que dans le gabarit : « 90 s » ou « 1 h 05 »
                // est une décision de lecture, et elle se vérifie sans navigateur.
                'duration'       => self::humanDuration($seconds),
                'oven'           => self::ovenOf($row),
                'batch_group'    => self::batchGroupOf($row),
                'batch_capacity' => self::intOf($row, ['batch_capacity']),
                'per_tray'       => self::intOf($row, ['products_per_tray']),
                'trays'          => self::intOf($row, ['trays_per_oven']),
                'photos'         => self::photosOf($row),
            ];
        }

        return $out;
    }

    /** Combien d'étapes servies n'ont pas pu être lues. */
    public static function unreadable(array $data): int
    {
        $rows = array_filter(self::rowsOf($data), 'is_array');

        return count($rows) - count(array_filter($rows, fn(array $r) => self::textOf($r) !== ''));
    }

    /** @return array<int, mixed> */
    private static function rowsOf(array $data): array
    {
        return isset($data['steps']) && is_array($data['steps'])
            ? array_values($data['steps'])
            : [];
    }

    /** L'instruction de travail, ou une chaîne vide. */
    private static function textOf(array $row): string
    {
        return !empty($row['description']) && is_string($row['description'])
            ? trim($row['description']) : '';
    }

    private static function intOf(array $row, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                return (int)$row[$k];
            }
        }

        return null;
    }

    /**
     * L'étape passe-t-elle au four ?
     *
     * Un drapeau explicite fait foi. Sinon on le déduit des paramètres de
     * four : le back impose qu'une étape de four porte plaques et pièces par
     * plaque, leur présence suffit donc à conclure. On ne conclut jamais
     * l'inverse d'une absence — une étape sans drapeau ni paramètre est
     * simplement une étape sans four.
     */
    private static function ovenOf(array $row): bool
    {
        // `uses_oven` est REQUIS par le schéma : on le lit, on ne le déduit
        // plus des paramètres de plaque.
        return filter_var($row['uses_oven'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Le nom du groupe de batch si le back le sert, son identifiant sinon. */
    private static function batchGroupOf(array $row): ?string
    {
        if (!empty($row['batch_group_name']) && is_string($row['batch_group_name'])) {
            return trim($row['batch_group_name']);
        }
        if (isset($row['batch_group_id']) && $row['batch_group_id'] !== '' && $row['batch_group_id'] !== null) {
            return '#' . $row['batch_group_id'];
        }

        return null;
    }

    /**
     * Les clés d'image de l'étape, trois au plus.
     *
     * Le back en garantit trois au maximum ; on referme quand même la liste
     * ici, parce qu'une quatrième déborderait la rangée sans qu'on sache
     * d'où elle vient.
     *
     * @return array<int, string>
     */
    private static function photosOf(array $row): array
    {
        // Trois emplacements nommés, en URL complètes — c'est le schéma, pas
        // une convention locale. Le plafond de trois est donc structurel.
        $keys = [];
        foreach ([1, 2, 3] as $slot) {
            $v = $row["photo_{$slot}_url"] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $keys[] = trim($v);
            }
        }

        return array_values(array_unique($keys));
    }

    /** « 90 s », « 4 min », « 1 h 05 » — jamais « 0 s » pour une durée absente. */
    public static function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return $s === 0 ? $m . ' min' : $m . ' min ' . $s . ' s';
        }

        return intdiv($seconds, 3600) . ' h ' . str_pad((string)intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    }
}
