<?php
namespace App\Kitchen\app\Http\Controllers\Complaint;
use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Repositories\Complaint\MaterialOrderForComplaintRepository;
use App\Kitchen\app\Services\Complaint\ComplaintService;
use App\Kitchen\core\Support\GlobalRegistry;
use App\Kitchen\core\Support\Route;
class ComplaintController extends Controller
{
    public function __construct(
        private ComplaintService                    $complaintService,
        private MaterialOrderForComplaintRepository $materialOrderRepository
    ) {}
    /**
     * Lista reklamacji sklepu.
     * GET /complaints
     */
    #[Route('GET', '/complaints')]
    public function overview(): void
    {
        $complaints = $this->safeFetch(
            fn() => $this->complaintService->getAll(),
            $this->errors,
            null,
            []
        );
        $this->view('complaints/overview', [
            'complaints'   => $complaints,
            'filterStatus' => $_GET['status'] ?? 'all',
        ]);
    }
    /**
     * Formularz nowej reklamacji (GET) + zapis (POST).
     * GET  /complaints/new
     * POST /complaints/new
     */
    #[Route('GET', '/complaints/new')]
    #[Route('POST', '/complaints/new')]
    public function create(): void
    {
        $reasons = $this->safeFetch(
            fn() => $this->complaintService->getAllReasons(),
            $this->errors,
            null,
            []
        );
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        $deliveredOrders = $this->safeFetch(
            fn() => $this->materialOrderRepository->getDeliveredByShop($shopId),
            $this->warnings,
            null,
            []
        );
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post  = $_POST  ?? [];
            $files = $_FILES ?? [];
            $res = $this->safeFetch(
                fn() => $this->complaintService->insert($post, $files),
                $this->errors,
                null,
                null
            );
            if ($res !== null) {
                $complaintOk   = $res['complaint']['success'] ?? false;
                $attachmentsOk = $res['attachments'] === false
                    || $res['attachments'] === null
                    || ($res['attachments']['success'] ?? false);
                if ($complaintOk && $attachmentsOk) {
                    header('Location: ' . ROOT . '/complaints');
                    exit;
                }
                if (!$complaintOk) {
                    $this->errors[] = $res['complaint']['description'] ?? 'Błąd zapisu reklamacji.';
                }
                if (is_array($res['attachments']) && !($res['attachments']['success'] ?? false)) {
                    $this->warnings[] = $res['attachments']['description'] ?? 'Błąd wysyłania załączników.';
                }
            }
        }
        $this->view('complaints/create', [
            'complaint_reasons' => $reasons,
            'delivered_orders'  => $deliveredOrders,
        ]);
    }
    /**
     * Presigned URL dla załącznika (AJAX).
     * GET /ajax/complaints/attachment/{id}/presigned-url
     */
    #[Route('GET', '/ajax/complaints/attachment/{id:[^/]+}/presigned-url')]
    public function ajaxPresignedUrl(string $id): void
    {
        $result = $this->safeFetch(
            fn() => $this->complaintService->getPresignedUrl($id),
            $this->errors,
            null,
            null
        );
        header('Content-Type: application/json');
        if ($result === null || !($result['success'] ?? false)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => "Le lien de la pièce jointe n'a pas pu être obtenu."]);
            exit;
        }
        echo json_encode([
            'ok'  => true,
            'url' => $result['data']['url'] ?? null,
        ]);
        exit;
    }
}
