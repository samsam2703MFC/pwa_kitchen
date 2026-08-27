<?php
namespace App\Kitchen\app\Repositories\Complaint;
use App\Kitchen\core\Http\ApiClient;
/**
 * Minimalny dostęp do zamówień materiałowych — wyłącznie na potrzeby
 * tworzenia reklamacji w kitchen.
 */
class MaterialOrderForComplaintRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}
    /**
     * Pobiera dostarczone zamówienia dla sklepu (do wyboru w formularzu reklamacji).
     * API: GET /shops/{shopId}/orders?status=DELIVERED&include=products
     */
    public function getDeliveredByShop(int $shopId): array
    {
        $response = $this->apiClient->where(
            "/shops/{$shopId}/orders",
            ['status' => 'DELIVERED', 'include' => 'products']
        );
        if (!($response['success'] ?? false)) {
            return [];
        }
        $data = $response['data'] ?? [];

        // ── Deux formes possibles pour la même route ──
        // /shops/{id}/orders sert deux appelants : le carnet de production, qui
        // reçoit { date, items: [...] }, et ce formulaire, qui attend la liste
        // seule. Le tri ci-dessous supposait la seconde forme et parcourait donc
        // la chaîne « date » comme si c'était une commande — un TypeError, donc
        // une Error, que safeFetch ne rattrapait pas : l'écran « Nouvelle
        // réclamation » rendait une page blanche en 500.
        $orders = is_array($data['items'] ?? null) ? $data['items'] : $data;
        if (!is_array($orders)) {
            return [];
        }

        // On ne garde que ce qui ressemble à une commande. Une entrée mal formée
        // vaut mieux ignorée qu'en travers du tri : le formulaire proposera une
        // liste courte, pas une page d'erreur.
        $orders = array_values(array_filter($orders, 'is_array'));

        // Tri décroissant sur delivered_on, comme le panel.
        usort($orders, static function (array $a, array $b) {
            return strtotime((string)($b['delivered_on'] ?? '')) - strtotime((string)($a['delivered_on'] ?? ''));
        });

        return $orders;
    }
}
