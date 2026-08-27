<?php

namespace App\Kitchen\app\Repositories\Baking;

use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\core\Http\ApiClient;

/**
 * Accès au plan de cuisson.
 *
 * Aucun de ces endpoints n'existe encore — voir docs/ENDPOINTS_CUISSON.md.
 * `getPlan()` renvoie null quand l'API ne répond pas, jamais un tableau vide :
 * une liste vide affichée à la place d'un endpoint muet ferait croire qu'il
 * n'y a rien au four.
 */
class BakingRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * Plan de cuisson du jour.
     *
     * @return array{server_time: ?string, batches: BakingBatchModel[]}|null
     */
    public function getPlan(int $shopId, string $date): ?array
    {
        $response = $this->apiClient->where("/shops/{$shopId}/baking", ['date' => $date]);
        if (!($response['success'] ?? false)) {
            return null;
        }

        $data = $response['data'] ?? [];
        $rows = $data['batches'] ?? (is_array($data) ? $data : []);

        $batches = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $batches[] = new BakingBatchModel($row);
            }
        }

        return [
            // L'horloge du serveur prime : une tablette d'atelier dérive, et un
            // écran qui décrète un retard à cause de son propre décalage fait
            // perdre confiance en tout le reste.
            'server_time' => $data['server_time'] ?? null,
            'batches'     => $batches,
        ];
    }

    /**
     * Programme une fournée.
     *
     * C'est le serveur qui décide du four et des horaires : la PWA ne connaît
     * ni l'occupation des fours, ni les fournées des autres postes. Elle dit
     * quoi et combien ; le plan de charge, c'est le back-office.
     */
    public function createBatch(int $shopId, array $payload): array
    {
        return $this->apiClient->post("/shops/{$shopId}/baking", $payload);
    }

    /** Fait avancer une fournée d'une étape. Le serveur horodate. */
    public function advance(int $batchId, string $status, ?int $employeeId = null, ?int $allottedMinutes = null): array
    {
        $payload = ['status' => $status];
        if ($employeeId !== null) {
            $payload['id_employee'] = $employeeId;
        }
        // N'est envoyé que s'il a été touché : sans ce champ, le serveur garde
        // sa propre durée planifiée, et c'est le comportement d'avant.
        if ($allottedMinutes !== null) {
            $payload['allotted_minutes'] = $allottedMinutes;
        }
        return $this->apiClient->patch("/baking/{$batchId}", $payload);
    }

    /** Compteurs par étape, pour la pastille du menu. */
    public function getPendingCount(int $shopId): ?array
    {
        $response = $this->apiClient->get("/shops/{$shopId}/baking/pending-count");
        if (!($response['success'] ?? false)) {
            return null;
        }
        return $response['data'] ?? null;
    }
}
