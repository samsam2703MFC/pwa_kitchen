<?php

namespace App\Kitchen\app\Repositories\Staff;

use App\Kitchen\core\Http\ApiClient;

/**
 * L'équipe d'un magasin, et qui y travaille aujourd'hui.
 *
 * ── Une seule source ── (révision du 13/08/2026)
 * `GET /shops/{id}/schedule?date=…` — le planning du jour. Il répond, et il
 * porte les personnes : c'est donc lui, et lui seul, qui dit qui peut signer
 * une tâche ce jour-là.
 *
 * `/franchisee-employees` a été retiré. Il servait à obtenir les noms, que le
 * planning devait ensuite désigner par identifiant — deux appels, un
 * croisement, et un point de panne de plus pour une question à laquelle une
 * seule route sait répondre. Et la bonne : quelqu'un qui n'est pas au planning
 * ne travaille pas, sa fiche existât-elle.
 *
 * Le même endpoint sert les checklists et la cuisson : savoir qui est en
 * atelier n'appartient à aucun des deux modules.
 *
 * ── Aucun repli ──
 * S'il ne répond pas, on rend null. L'écran nomme alors la route au lieu de
 * proposer une liste tirée d'ailleurs : une liste plausible venue d'une autre
 * source masque précisément le trou qu'on cherche.
 */
class StaffRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * Le planning d'un jour.
     *
     * @return array<int, array<string, mixed>>|null
     *         null = planning non servi — à ne pas confondre avec [], qui veut
     *         dire « personne n'est de service ce jour-là ».
     */
    public function getSchedule(int $shopId, string $date): ?array
    {
        return $this->rows($this->apiClient->where("/shops/{$shopId}/schedule", ['date' => $date]));
    }

    /**
     * Extrait la liste d'une réponse, quelle que soit la façon dont elle est
     * emballée : `data` peut être la liste, ou la porter sous `items`,
     * `employees`, `schedule`… On ne devine pas au-delà de ces noms — un
     * emballage inconnu rend null, et l'écran le dit.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function rows(array $res): ?array
    {
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        foreach (['items', 'employees', 'schedule', 'rows', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }

        // Une liste, pas un objet : des clés 0,1,2… et des entrées tableau.
        $list = array_values(array_filter($data, 'is_array'));

        return $list === [] && $data !== [] ? null : $list;
    }
}
