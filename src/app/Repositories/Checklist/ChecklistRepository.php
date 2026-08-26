<?php

namespace App\Kitchen\app\Repositories\Checklist;

use App\Kitchen\core\Http\ApiClient;

class ChecklistRepository
{
    public function __construct(private ApiClient $apiClient) {}

    /**
     * Pobiera checklisty aktywne dla sklepu w danym dniu.
     */
    public function getChecklistsForShop(int $shopId, string $date): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/checklists?date={$date}");
        return ($res['success'] ?? false) && isset($res['data']) ? $res['data'] : [];
    }

    /**
     * Pobiera postęp realizacji checklisty dla sklepu i dnia.
     */
    public function getChecklistProgress(int $shopId, int $checklistId, string $date): array
    {
        $res = $this->apiClient->get("/consultant/shops/{$shopId}/checklists/{$checklistId}/progress?date={$date}");
        return ($res['success'] ?? false) && isset($res['data']) ? $res['data'] : [];
    }

    /**
     * Pobiera pracowników sklepu (z polem pin do weryfikacji po stronie serwera).
     */
    public function getEmployeesForShop(int $shopId): array
    {
        $res = $this->apiClient->get("/shops/{$shopId}/employees");
        return ($res['success'] ?? false) && isset($res['data']) ? $res['data'] : [];
    }

    /** @return array<int, array{employee_id:int,name:string}>|null */
    public function getEligibleEmployeesForTask(int $shopId, int $taskId, string $date): ?array
    {
        $res = $this->apiClient->get("/shops/{$shopId}/tasks/{$taskId}/eligible-employees?date={$date}");
        if (!($res['success'] ?? false) || !is_array($res['data'] ?? null)) {
            return null;
        }

        return is_array($res['data']['employees'] ?? null) ? $res['data']['employees'] : [];
    }

    /** @return array<int, int>|null */
    public function getTaskIdsForEmployee(int $employeeId, string $date): ?array
    {
        $tasks = $this->getTasksForEmployee($employeeId, $date);
        if ($tasks === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(array $task): int => (int)($task['id'] ?? 0),
            $tasks
        ), static fn(int $id): bool => $id > 0)));
    }

    /** @return array<int, array{id:mixed,is_done?:bool}>|null */
    public function getTasksForEmployee(int $employeeId, string $date): ?array
    {
        $res = $this->apiClient->get("/employees/{$employeeId}/tasks?date={$date}");

        return ($res['success'] ?? false) && is_array($res['data'] ?? null)
            ? $res['data']
            : null;
    }

    /**
     * Oznacza zadanie jako wykonane przez pracownika.
     */
    public function markTaskDone(int $employeeId, int $taskId, array $fields, ?array $photo = null): array
    {
        $files = [];
        if ($photo && isset($photo['tmp_name']) && $photo['error'] === UPLOAD_ERR_OK) {
            $files['photo'] = $photo;
        }

        $res = $this->apiClient->postMultipart(
            "/employees/{$employeeId}/tasks/{$taskId}/mark-as-done",
            $fields,
            $files
        );
        return $res;
    }
}
