<?php

namespace App\Kitchen\app\Repositories\Production;

use App\Kitchen\core\Http\ApiClient;

/**
 * Le pilotage de production, lu depuis les endpoints qui existent VRAIMENT.
 *
 * ── Ce qui est confirmé (relevé en direct sur la prod, 27/08/2026) ──
 * `GET /shops/{id}/statistics/production-planning` répond, et son enveloppe est
 * connue :
 *   { summary:{date_from,date_to,week_days,distribution_mode,…},
 *     products:[…],
 *     time_distribution:[ {label, code, time_from, time_to,
 *                          sold_quantity, full_product_equivalent}, … ] }
 *
 * Le `time_distribution` porte les TRANCHES (périodes) : on les lit ici, plutôt
 * que d'appeler `/admin/sales-dayparts` qui demande un jeton admin qu'une
 * tablette n'a pas. La période 1 est la première tranche de la journée.
 *
 * ── Ce qui reste À CONFIRMER AU JETON ──
 * La forme des ENTRÉES de `products[]` n'a pas pu être relevée : sans jeton, la
 * boutique n'est pas résolue et `products` revient vide. La méthode
 * historyFromPlanning() est donc la SEULE zone spéculative du module : elle lit
 * plusieurs orthographes plausibles et, si aucune ne rend d'échantillon,
 * renvoie [] — l'écran nomme alors la route au lieu d'inventer un tableau. Le
 * jour où un jeton confirme la forme, c'est ici, et ici seulement, qu'on ajuste.
 *
 * ── Aucun repli ──
 * Une route qui ne répond pas rend null. On ne fabrique pas de données.
 */
class ProductionPlanningRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * La réponse brute de production-planning, ou null si non servie.
     *
     * @param int      $shopId
     * @param string   $dateFrom  début de la fenêtre d'historique (Y-m-d)
     * @param string   $dateTo    fin (le jour courant)
     * @param int[]    $weekDays  jours de semaine à retenir (1=lundi … 7)
     * @return array<string, mixed>|null
     */
    public function planning(int $shopId, string $dateFrom, string $dateTo, array $weekDays = []): ?array
    {
        if ($shopId <= 0) {
            return null;
        }
        $params = [
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            // On demande la ventilation par tranche : le mode exact est à
            // confirmer au jeton (whole_day est le défaut observé).
            'distribution_mode' => 'daypart',
        ];
        if ($weekDays !== []) {
            $params['week_days'] = implode(',', $weekDays);
        }

        $res = $this->apiClient->where("/shops/{$shopId}/statistics/production-planning", $params);
        if (!($res['success'] ?? false) || !is_array($res['data'] ?? null)) {
            return null;
        }

        return $res['data'];
    }

    /**
     * Les tranches (périodes) de la journée, dans l'ordre horaire.
     *
     * Lues depuis `time_distribution` — forme confirmée. La période 1 est la
     * première. Une tranche « journée entière » (WHOLE_DAY) seule signale que
     * le serveur n'a pas ventilé : on la rend telle quelle, l'écran le dira.
     *
     * @return array<int, array{code: string, label: string, from: string, to: string}>
     */
    public function dayparts(?array $planning): array
    {
        $td = $planning['time_distribution'] ?? null;
        if (!is_array($td)) {
            return [];
        }
        $out = [];
        foreach ($td as $t) {
            if (!is_array($t) || empty($t['code'])) {
                continue;
            }
            $out[] = [
                'code'  => (string)$t['code'],
                'label' => (string)($t['label'] ?? $t['code']),
                'from'  => substr((string)($t['time_from'] ?? ''), 0, 5),
                'to'    => substr((string)($t['time_to'] ?? ''), 0, 5),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['from'], $b['from']));

        return $out;
    }

    /**
     * L'historique de ventes par produit sur UNE tranche, en Sample[].
     *
     * ⚠️ ZONE À CONFIRMER AU JETON — voir l'en-tête. On lit `products[]` de
     * façon défensive ; toute forme non reconnue rend [] et l'écran nomme la
     * route. On n'invente rien.
     *
     * @return array<int|string, array<int, array{date: string, quantity: float}>>
     */
    public function historyFromPlanning(?array $planning, string $daypartCode): array
    {
        $products = $planning['products'] ?? null;
        if (!is_array($products) || $products === []) {
            return [];
        }

        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = $p['id_product'] ?? $p['product_id'] ?? $p['id'] ?? null;
            if ($id === null) {
                continue;
            }
            // Les ventes par tranche et par date : forme la plus probable, à
            // confirmer. On cherche une série datée sous la tranche demandée.
            $series = $p['time_distribution'] ?? $p['dayparts'] ?? $p['history'] ?? null;
            if (!is_array($series)) {
                continue;
            }
            foreach ($series as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = (string)($row['code'] ?? $row['daypart'] ?? '');
                if ($code !== $daypartCode) {
                    continue;
                }
                $date = (string)($row['date'] ?? '');
                $qty  = $row['sold_quantity'] ?? $row['quantity'] ?? $row['qty'] ?? null;
                if ($date === '' || !is_numeric($qty)) {
                    continue;
                }
                $out[$id][] = ['date' => $date, 'quantity' => (float)$qty];
            }
        }

        return $out;
    }

    /**
     * Nom et section de chaque produit, depuis la même réponse.
     *
     * @return array<int|string, array{name: string, section: string}>
     */
    public function metaFromPlanning(?array $planning): array
    {
        $products = $planning['products'] ?? null;
        if (!is_array($products)) {
            return [];
        }
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = $p['id_product'] ?? $p['product_id'] ?? $p['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $out[$id] = [
                'name'    => (string)($p['name'] ?? $p['product_name'] ?? ('#' . $id)),
                'section' => (string)($p['section'] ?? $p['category_name'] ?? $p['category'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Les ventes réelles du jour, par produit, sur une tranche.
     *
     * `GET /shops/{id}/statistics/sales/hourly-distribution/{date}` — répond,
     * forme des entrées à confirmer au jeton. On agrège les heures qui tombent
     * dans [from, to). Route muette → null (à distinguer de « rien vendu »).
     *
     * @return array<int|string, float>|null
     */
    public function soldToday(int $shopId, string $date, string $from, string $to): ?array
    {
        if ($shopId <= 0) {
            return null;
        }
        $res = $this->apiClient->get("/shops/{$shopId}/statistics/sales/hourly-distribution/{$date}");
        if (!($res['success'] ?? false)) {
            return null;
        }
        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hour = substr((string)($row['hour'] ?? $row['time'] ?? $row['time_from'] ?? ''), 0, 5);
            if ($hour === '' || $hour < $from || $hour >= $to) {
                continue;
            }
            $items = $row['products'] ?? $row['items'] ?? [$row];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $id  = $it['id_product'] ?? $it['product_id'] ?? $it['id'] ?? null;
                $qty = $it['quantity'] ?? $it['qty'] ?? $it['sold_quantity'] ?? null;
                if ($id === null || !is_numeric($qty)) {
                    continue;
                }
                $out[$id] = ($out[$id] ?? 0.0) + (float)$qty;
            }
        }

        return $out;
    }
}
