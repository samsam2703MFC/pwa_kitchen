<?php
namespace App\Kitchen\app\Http\Controllers\Dashboard;
use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Checklist\ChecklistService;
use App\Kitchen\core\Support\Route;
use App\Kitchen\core\Support\ShiftSession;
class DashboardController extends Controller
{
    public function __construct(
        private ChecklistService $checklistService
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
            $entryEmployees = $this->checklistService->roster($employees)['list'];
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

        $this->view("dashboard/dashboard", [
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
