<?php

namespace App\Kitchen\app\Repositories\Staff;

use App\Kitchen\core\Http\ApiClient;

/**
 * Le poste d'une personne, depuis l'endpoint dédié.
 *
 * `GET /employees/{id}/positions` — contrat CONFIRMÉ au swagger (documenté, pas
 * runtime) : un tableau d'EmployeePosition
 *   { id, name, description?, level_id, level_name, level_description?, level_order }.
 *
 * Une personne peut porter plusieurs postes ; pour l'écran on retient le
 * premier par ordre de niveau (level_order croissant), c'est-à-dire le poste
 * de plus bas niveau — le métier de base. À défaut d'ordre, l'ordre servi.
 *
 * ── Aucun repli ──
 * Route muette → null. On n'invente pas de poste, et l'écran n'affiche alors
 * pas de sous-titre.
 */
class StaffPositionRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * Le nom du poste principal d'une personne, ou null.
     */
    public function positionName(int $employeeId): ?string
    {
        if ($employeeId <= 0) {
            return null;
        }

        $res = $this->apiClient->get("/employees/{$employeeId}/positions");
        if (!($res['success'] ?? false) || !is_array($res['data'] ?? null)) {
            return null;
        }

        return self::pick($res['data']);
    }

    /**
     * Choisit le poste à montrer parmi ceux d'une personne.
     *
     * Pur : vérifié sans réseau. Le poste de plus bas `level_order` d'abord ;
     * un nom vide est ignoré ; rien d'exploitable → null.
     *
     * @param array<int, mixed> $positions
     */
    public static function pick(array $positions): ?string
    {
        $best = null;
        $bestOrder = null;
        foreach ($positions as $p) {
            if (!is_array($p) || empty($p['name']) || !is_string($p['name'])) {
                continue;
            }
            $order = isset($p['level_order']) && is_numeric($p['level_order'])
                ? (int)$p['level_order'] : PHP_INT_MAX;
            if ($best === null || $order < $bestOrder) {
                $best = trim($p['name']);
                $bestOrder = $order;
            }
        }

        return $best;
    }
}
