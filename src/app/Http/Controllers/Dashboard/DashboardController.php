<?php
namespace App\Kitchen\app\Http\Controllers\Dashboard;
use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Checklist\ChecklistFocusService;
use App\Kitchen\app\Services\Checklist\ChecklistService;
use App\Kitchen\core\Support\Route;
use App\Kitchen\core\Support\ShiftSession;
class DashboardController extends Controller
{
    public function __construct(
        private ChecklistService $checklistService,
        private ChecklistFocusService $focus,
        private \App\Kitchen\app\Repositories\Staff\StaffPositionRepository $positions
    ) {}
    #[Route('GET', '/dashboard')]
    public function index()
    {
        $today = date('Y-m-d');
        $checklists = $this->safeFetch(
            fn() => $this->checklistService->getChecklistsForShop($today),
            $this->errors,
            null,
            []
        );
        $tasksCompleted = 0;
        $tasksTotal     = 0;
        foreach ($checklists as $cl) {
            $tasksCompleted += (int)($cl['tasks_done']  ?? 0);
            $tasksTotal     += (int)($cl['tasks_total'] ?? 0);
        }
        $tasksTodo = max(0, $tasksTotal - $tasksCompleted);

        $employeeStats = null;
        $shopId = $this->checklistService->shopId();
        $shift = $shopId > 0 ? ShiftSession::current($shopId, $today) : null;
        $entryEmployees = [];
        if ($shift === null) {
            $employees = $this->safeFetch(
                fn() => $this->checklistService->getEmployeesForShop($today),
                $this->errors,
                null,
                []
            );
            $entryEmployees = \App\Kitchen\app\Services\Staff\StaffService::withPositions(
                $this->checklistService->roster($employees)['list'],
                fn(int $id) => $this->safeFetch(
                    fn() => $this->positions->positionName($id),
                    $this->warnings, null, null
                )
            );
        }
        if ($shift) {
            $employeeTasks = $this->safeFetch(
                fn() => $this->checklistService->getTasksForEmployee((int)$shift['id'], $today),
                $this->errors,
                null,
                null
            );

            if (is_array($employeeTasks)) {
                $employeeCompleted = count(array_filter(
                    $employeeTasks,
                    static fn(array $task): bool => ($task['is_done'] ?? false) === true
                ));
                $employeeStats = [
                    'tasks_todo' => max(0, count($employeeTasks) - $employeeCompleted),
                    'tasks_completed' => $employeeCompleted,
                ];
            }
        }

        /* ── La prochaine tâche, pour la carte « Listes de contrôle » ──
           La checklist du moment est choisie par l'heure (même règle que
           l'écran des checklists) ; sa progression donne la première tâche
           encore ouverte. Rien n'est demandé si tout est fait — et si la
           progression ne répond pas, la carte retombe sur son sous-titre :
           une carte de menu n'a pas à porter un bandeau d'erreur. */
        $clNext = null;
        $picked = $checklists ? $this->focus->pick($checklists, date('H:i')) : null;
        if ($picked !== null) {
            $progress = $this->safeFetch(
                fn() => $this->checklistService->getChecklistProgress($picked, $today),
                $this->warnings,
                null,
                null
            );
            if (is_array($progress) && is_array($progress['tasks'] ?? null)) {
                $clNext = $this->focus->nextPending($progress['tasks'], date('H:i'));
            }
        }

        $this->view("dashboard/dashboard", [
            'cl_next'  => $clNext,
            'cl_done'  => $employeeStats['tasks_completed'] ?? $tasksCompleted,
            'cl_left'  => $employeeStats['tasks_todo']      ?? $tasksTodo,
            'stats' => [
                'tasks_todo'        => $tasksTodo,
                'tasks_in_progress' => 0,
                'tasks_completed'   => $tasksCompleted,
            ],
            'employee_stats' => $employeeStats,
            'entry_employees' => $entryEmployees,
            'has_active_shift' => $shift !== null,
        ]);
    }
}
