<?php

namespace App\Kitchen\app\Http\Controllers\Checklist;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Checklist\ChecklistFocusService;
use App\Kitchen\app\Services\Checklist\ChecklistService;
use App\Kitchen\core\Support\ShiftSession;

class ChecklistController extends Controller
{
    public function __construct(
        private ChecklistService $checklistService,
        private ChecklistFocusService $focus
    ) {}

    /**
     * GET /checklists
     * Widok przeglądania checklist i postępu zadań dla bieżącego sklepu.
     */
    public function index(): void
    {
        $date        = $_GET['date']         ?? date('Y-m-d');
        $checklistId = isset($_GET['checklist_id']) ? (int)$_GET['checklist_id'] : null;
        $today       = date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
            $date = $today;
        }

        $shopId = $this->checklistService->shopId();
        // Osoba przy tablecie jest globalnym kontekstem urządzenia, nie
        // kontekstem dnia. Dzięki temu także przy odczycie dnia minionego
        // widzi wyłącznie zadania swojego position-level.
        $activeWorker = $shopId > 0 ? ShiftSession::current($shopId) : null;
        // Podpis bez ponownego PIN-u pozostaje uprawnieniem wyłącznie na dziś.
        // Historyczna realizacja nadal przechodzi dotychczasową weryfikację.
        $shift = $date === $today ? $activeWorker : null;

        $checklists = $this->safeFetch(
            fn() => $this->checklistService->getChecklistsForShop($date),
            $this->errors,
            null,
            []
        );

        $progressByChecklist = [];
        $employeeMode = $activeWorker !== null;
        $employeeNoTasks = false;
        if ($activeWorker) {
            // API otrzymuje ID aktywnej osoby w ścieżce oraz oglądaną datę w
            // query stringu: /employees/{employeeId}/tasks?date={date}.
            $taskIds = $this->checklistService->getTaskIdsForEmployee((int)$activeWorker['id'], $date);
            if ($taskIds === null) {
                $this->errors[] = 'Nie można pobrać zadań przypisanych do pracownika.';
                $checklists = [];
            } else {
                $allowed = array_fill_keys($taskIds, true);
                $filtered = [];
                foreach ($checklists as $checklist) {
                    $id = (int)($checklist['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $details = $this->safeFetch(
                        fn() => $this->checklistService->getChecklistProgress($id, $date),
                        $this->errors,
                        null,
                        []
                    );
                    $details = $this->filterProgress($details, $allowed);
                    if (($details['tasks'] ?? []) === []) {
                        continue;
                    }
                    $progressByChecklist[$id] = $details;
                    $checklist['tasks_total'] = (int)($details['summary']['total'] ?? 0);
                    $checklist['tasks_done'] = (int)($details['summary']['done'] ?? 0);
                    $filtered[] = $checklist;
                }
                $checklists = $filtered;
                $employeeNoTasks = $checklists === [];
            }
        }

        // ── Ouvrir la checklist du moment, plutôt que de la demander ──
        // Le paramètre ABSENT et le paramètre VIDE ne veulent pas dire la même
        // chose : absent, c'est une arrivée sur l'écran, et on peut décider
        // pour l'équipe ; vide, c'est « — toutes — » choisi à la main, et on ne
        // repasse pas par-dessus. La règle ne vaut qu'aujourd'hui : sur une
        // date passée on vient relire, pas exécuter.
        $autoFocus = false;
        if (!array_key_exists('checklist_id', $_GET) && $date === $today && $checklists) {
            $picked = $this->focus->pick($checklists, date('H:i'));
            if ($picked !== null) {
                $checklistId = $picked;
                $autoFocus   = true;
            }
        }

        if ($checklistId !== null && $activeWorker && !$this->containsChecklist($checklists, $checklistId)) {
            $checklistId = null;
        }

        $progress = null;
        if ($checklistId && !empty($checklists)) {
            $progress = $progressByChecklist[$checklistId] ?? $this->safeFetch(
                fn() => $this->checklistService->getChecklistProgress($checklistId, $date),
                $this->errors,
                null,
                []
            );
        }

        // Qui peut signer, CE jour-là. On demande le planning de la date
        // consultée et pas celui d'aujourd'hui : une checklist se relit pour
        // hier, et c'est l'équipe d'hier qui doit y figurer.
        $employees = $this->safeFetch(
            fn() => $this->checklistService->getEmployeesForShop($date),
            $this->errors,
            null,
            []
        );
        $roster = $this->checklistService->roster($employees);

        $this->view('checklist/index', [
            'shift'                 => $shift ? [
                'name'      => $shift['name'],
                'initials'  => ShiftSession::rules()->initials($shift['name']),
                'remaining' => ShiftSession::rules()->remaining($shift, time()),
            ] : null,
            'selected_date'         => $date,
            'selected_checklist_id' => $checklistId,
            // La vue s'en sert pour deux choses : replier les filtres, et dire
            // que c'est elle qui a choisi. Une sélection qu'on n'a pas faite et
            // qu'on ne voit pas expliquée passe pour un bug.
            'auto_focus'            => $autoFocus,
            // Le nom de la checklist retenue, pour que le bandeau replié dise
            // ce qu'il cache.
            'focused_name'          => $this->nameOf($checklists, $checklistId),
            'checklists'            => $checklists,
            'progress'              => $progress,
            'today'                 => $today,
            'employees'             => $roster['list'],
            // Trois situations distinctes — voir StaffService::roster().
            'roster_mode'           => $roster['mode'],
            // La route a creer, s'il en manque une. La coque l'affiche : on ne
            // remplace plus une reponse absente par une liste plausible.
            'missing_api'           => $roster['missing'],
            'shift_entry_required'  => $date === $today && $activeWorker === null,
            'employee_mode'         => $employeeMode,
            'employee_no_tasks'     => $employeeNoTasks,
        ]);
    }

    private function filterProgress(array $progress, array $allowedTaskIds): array
    {
        if (!isset($progress['tasks']) || !is_array($progress['tasks'])) {
            return $progress;
        }

        $progress['tasks'] = array_values(array_filter(
            $progress['tasks'],
            static fn(array $task): bool => isset($allowedTaskIds[(int)($task['task_id'] ?? 0)])
        ));
        $total = count($progress['tasks']);
        $done = count(array_filter($progress['tasks'], static fn(array $task): bool => ($task['status'] ?? '') === 'DONE'));
        $progress['summary'] = ['total' => $total, 'done' => $done, 'not_done' => $total - $done];

        return $progress;
    }

    private function containsChecklist(array $checklists, int $checklistId): bool
    {
        foreach ($checklists as $checklist) {
            if ((int)($checklist['id'] ?? 0) === $checklistId) {
                return true;
            }
        }

        return false;
    }

    /** Le nom d'une checklist par son identifiant, ou null. */
    private function nameOf(array $checklists, ?int $id): ?string
    {
        if (!$id) {
            return null;
        }
        foreach ($checklists as $c) {
            if (is_array($c) && (int)($c['id'] ?? 0) === $id) {
                return (string)($c['name'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * POST /checklists/tasks/{taskId}/complete
     * Weryfikuje PIN i oznacza zadanie jako wykonane. Zwraca JSON.
     */
    public function completeTask(string $taskId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $taskId = (int)$taskId;
        if ($taskId <= 0) {
            return $this->json(['success' => false, 'message' => 'invalid_task'], 400);
        }

        $body  = $_POST;
        $date  = $body['date'] ?? date('Y-m-d');
        $note  = trim($body['note'] ?? '');
        $photo = $_FILES['photo'] ?? null;

        /* ── Faite, ou pas faite ──
           Deux issues seulement, et la liste est fermée ici : ce champ vient
           du navigateur, et un statut libre laisserait écrire n'importe quoi
           dans le relevé.

           « Non effectuée » exige une raison. Une tâche déclarée non faite
           sans explication ne dit rien à celui qui relira la checklist, et
           c'est précisément pour ce cas qu'on ouvre encore une fenêtre. */
        $taskStatus = strtoupper(trim($body['status'] ?? 'DONE'));
        if (!in_array($taskStatus, ['DONE', 'FAILED'], true)) {
            return $this->json(['success' => false, 'message' => 'invalid_status'], 400);
        }
        if ($taskStatus === 'FAILED' && $note === '') {
            return $this->json(['success' => false, 'message' => 'note_required'], 400);
        }

        /* ── Qui signe ──
           Le poste ouvert fait foi, et il vient du cookie signé par le serveur.
           On ne lit PAS l'employé envoyé par le formulaire quand un poste est
           ouvert : sinon il suffirait de changer un champ pour signer sous le
           nom d'un collègue, et la prise de poste ne protégerait rien. */
        $shopId = $this->checklistService->shopId();
        $shift = $date === date('Y-m-d') && $shopId > 0 ? ShiftSession::current($shopId, $date) : null;

        if ($shift) {
            $employeeId = (int) $shift['id'];
            $pin        = '';
        } else {
            $employeeId = isset($body['employee_id']) ? (int) $body['employee_id'] : 0;
            $pin        = trim($body['pin'] ?? '');

            if ($employeeId <= 0) {
                return $this->json(['success' => false, 'message' => 'employee_required'], 400);
            }
            if ($pin === '') {
                return $this->json(['success' => false, 'message' => 'pin_required'], 400);
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $result = $this->checklistService->completeTask(
            $taskId, $employeeId, $pin, $date, $note, $photo, $shift !== null, $taskStatus
        );

        $stayLogged = !$shift && (($body['stay_logged'] ?? '') === '1');
        if (($result['success'] ?? false) && $stayLogged) {
            $verified = $this->checklistService->verifyPin($employeeId, $pin);
            if ($verified['success'] ?? false) {
                ShiftSession::open($employeeId, $verified['name'] ?? '', $shopId, $date);
                $result['stay_logged'] = true;
            }
        }

        // Une tâche validée repousse l'échéance : c'est ce qui permet
        // d'enchaîner une checklist longue sans que le poste se referme au
        // milieu. Seulement si elle a réussi — un échec n'est pas un geste.
        if ($shift && ($result['success'] ?? false)) {
            ShiftSession::touch($shift);
        }

        $status = ($result['success'] ?? false) ? 200 : 422;
        return $this->json($result, $status);
    }

    /**
     * POST /ajax/checklists/shift — prendre son poste.
     *
     * Le seul endroit où le PIN est saisi. Ensuite, toute la checklist
     * s'enchaîne.
     */
    #[Route('POST', '/ajax/checklists/shift')]
    public function openShift(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $employeeId = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
        $pin        = trim($_POST['pin'] ?? '');

        if ($employeeId <= 0 || $pin === '') {
            return $this->json(['success' => false, 'message' => 'employee_and_pin_required'], 400);
        }

        $res = $this->checklistService->verifyPin($employeeId, $pin);
        if (!($res['success'] ?? false)) {
            return $this->json($res, 422);
        }

        $date = date('Y-m-d');
        if (!$this->checklistService->isScheduledEmployee($employeeId, $date)) {
            return $this->json(['success' => false, 'message' => 'employee_not_scheduled'], 422);
        }

        ShiftSession::open($employeeId, $res['name'] ?? '', $this->checklistService->shopId(), $date);
        $claims = ShiftSession::current();

        return $this->json([
            'success'   => true,
            'name'      => $res['name'] ?? '',
            'initials'  => ShiftSession::rules()->initials($res['name'] ?? ''),
            'remaining' => $claims ? ShiftSession::rules()->remaining($claims, time()) : 0,
        ]);
    }

    /** POST /ajax/checklists/shift/close — passer la main. */
    #[Route('POST', '/ajax/checklists/shift/close')]
    public function closeShift(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        ShiftSession::close();

        return $this->json(['success' => true]);
    }

    /**
     * GET /ajax/tablet-worker/roster — osoby dostępne dziś przy tym tablecie.
     *
     * Sesja zmiany jest globalnym kontekstem tabletu. Lista pozostaje jednak
     * ograniczona do bieżącego grafiku, tak samo jak na ekranie checklist.
     */
    public function tabletWorkerRoster(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $employees = $this->checklistService->getEmployeesForShop(date('Y-m-d'));
        $roster = $this->checklistService->roster($employees);

        if ($roster['mode'] === 'missing') {
            return $this->json(['success' => false, 'message' => 'employees_unavailable'], 502);
        }

        return $this->json([
            'success' => true,
            'employees' => $roster['list'],
        ]);
    }

    public function eligibleEmployees(string $taskId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $taskId = (int)$taskId;
        $date = $_GET['date'] ?? date('Y-m-d');
        if ($taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json(['success' => false, 'message' => 'invalid_request'], 400);
        }

        $employees = $this->checklistService->getEligibleEmployeesForTask($taskId, $date);
        if ($employees === null) {
            return $this->json(['success' => false, 'message' => 'eligible_employees_unavailable'], 502);
        }

        return $this->json(['success' => true, 'employees' => $employees]);
    }
}
