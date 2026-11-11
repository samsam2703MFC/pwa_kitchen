<?php

namespace App\Kitchen\app\Http\Controllers\Baking;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\app\Services\Baking\BakingService;
use App\Kitchen\app\Services\Staff\StaffService;

class BakingController extends Controller
{
    public function __construct(
        private BakingService $bakingService,
        private StaffService $staffService
    ) {}

    /**
     * GET /baking[?stage=prep|cook|finish]
     *
     * Toujours pour aujourd'hui : pas de sélecteur de date, une cuisine ne
     * cuit pas pour hier.
     */
    /**
     * GET /baking — conservé, mais redirige.
     *
     * Le planning est devenu un onglet de Production : un seul module, quatre
     * questions. L'ancienne adresse reste vivante parce qu'elle circule déjà —
     * les tuiles de période y pointaient, et un lien mort vaut moins qu'une
     * redirection d'une ligne.
     */
    public function index(): void
    {
        $params = ['view' => 'planning'];
        foreach (['stage', 'focus'] as $k) {
            if (!empty($_GET[$k])) {
                $params[$k] = (string)$_GET[$k];
            }
        }

        header('Location: ' . ROOT . '/production?' . http_build_query($params), true, 302);
        exit;
    }

    /**
     * GET /ajax/baking
     *
     * Même contenu que l'écran, en JSON : le plan bouge en continu, et une
     * fournée sortie par un collègue doit disparaître de la tablette d'à côté
     * sans que personne ne rafraîchisse.
     */
    public function ajaxPlan(): void
    {
        $stage = $this->readStage();
        $plan  = $this->bakingService->getPlan();

        if ($plan === null) {
            $this->json(['success' => false, 'plan_available' => false], 502)->send();
            return;
        }

        $now    = $this->bakingService->nowMinutes($plan['server_time']);
        $active = $this->bakingService->active($plan['batches']);
        $shown  = $this->bakingService->filterByStage($active, $stage);

        $this->json([
            'success'        => true,
            'plan_available' => true,
            'now'            => $now,
            'now_clock'      => BakingBatchModel::toClock($now),
            'counts'         => $this->bakingService->countByStage($active),
            'window'         => $this->bakingService->window($shown ?: $active, $now),
            'batches'        => array_map(fn(BakingBatchModel $b) => $this->expose($b, $now), $shown),
        ])->send();
    }

    /**
     * POST /ajax/baking
     *
     * Corps : { id_product, quantity, id_employee? }
     *
     * Programme une fournée. Appelé depuis l'écran de production quand une
     * tuile annonce un manque : la fournée entre au plan, et le produit passe
     * aussitôt en « préparation ». C'est le serveur qui choisit le four et les
     * horaires — la PWA ne connaît pas l'occupation des fours.
     */
    public function ajaxCreate(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);

        $idProduct = (int)($input['id_product'] ?? 0);
        $quantity  = (float)($input['quantity'] ?? 0);

        if ($idProduct <= 0 || $quantity <= 0) {
            $this->json(['success' => false, 'description' => 'invalid_payload'], 400)->send();
            return;
        }

        $response = $this->bakingService->planBatch(
            $idProduct,
            $quantity,
            isset($input['id_employee']) ? (int)$input['id_employee'] : null
        );

        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /**
     * PATCH /ajax/baking/{id}
     *
     * Corps : { status } — ou rien, et l'étape suivante est déduite.
     */
    public function ajaxAdvance(int $id): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $input = is_array($input) ? $input : [];

        $status = isset($input['status']) ? strtoupper((string)$input['status']) : null;
        if ($status === null || $status === '') {
            $this->json(['success' => false, 'description' => 'status_required'], 400)->send();
            return;
        }

        $response = $this->bakingService->advance(
            $id,
            $status,
            isset($input['id_employee']) ? (int)$input['id_employee'] : null,
            // Le temps imparti ajusté à l'écran : le serveur s'en sert pour
            // replanifier la suite de la journée. Absent = le plan tient.
            isset($input['allotted_minutes']) ? max(0, (int)$input['allotted_minutes']) : null
        );

        $this->json($response, ($response['success'] ?? false) ? 200 : 502)->send();
    }

    /**
     * Forme d'une fournée pour le rafraîchissement : tout ce que la vue
     * calcule est calculé ici aussi, pour que le JavaScript n'ait pas à
     * refaire l'arithmétique des étapes de son côté.
     */
    private function expose(BakingBatchModel $b, int $now): array
    {
        return $b->jsonSerialize() + [
            'stage_end'      => $b->getStageEndClock(),
            'progress'       => $b->getProgressPercent($now),
            'next_status'    => $b->getNextStatus(),
            'is_waiting'     => $b->isWaiting(),
            'action'         => $b->getActionKey(),
            'due'            => $b->getDueClock(),
            'due_min'        => $b->getDueTime(),
            'left'           => $b->getMinutesLeft($now),
            'planned_min'    => $b->getPlannedMinutes(),
            'is_ready_bake'  => $b->isReadyToBake(),
            'prep_start_min' => $b->getPrepStart(),
            'prep_end_min'   => $b->getPrepEnd(),
            'cook_start_min' => $b->getCookStart(),
            'cook_end_min'   => $b->getCookEnd(),
            'finish_end_min' => $b->getFinishEnd(),
            'shelf_min'      => $b->getShelfTime(),
        ];
    }

    /** Fournée à mettre en avant, quand on arrive d'un autre écran. */
    private function readFocus(): ?int
    {
        $focus = (int)($_GET['focus'] ?? 0);
        return $focus > 0 ? $focus : null;
    }

    private function readStage(): string
    {
        $stage = (string)($_GET['stage'] ?? '');
        return in_array($stage, BakingService::STAGES, true) ? $stage : 'all';
    }
}
