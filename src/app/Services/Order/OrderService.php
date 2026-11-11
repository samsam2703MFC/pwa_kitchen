<?php

namespace App\Kitchen\app\Services\Order;

use App\Kitchen\app\Models\Order\OrderModel;
use App\Kitchen\app\Repositories\Order\OrderRepository;
use App\Kitchen\core\Support\GlobalRegistry;

class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository
    ) {}

    /**
     * Pobiera listę zamówień dla sklepu zalogowanego urządzenia.
     *
     * @param string|null $date        Dzień odbioru (Y-m-d). Domyślnie: dziś.
     * @param string|null $clientName  Fragment nazwy klienta do wyszukiwania.
     * @param bool        $pendingOnly Tylko oczekujące na realizację.
     * @return OrderModel[]
     */
    public function getOrders(?string $date = null, ?string $clientName = null, bool $pendingOnly = true): array
    {
        $shopId = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);

        if ($shopId <= 0) {
            return [];
        }

        return $this->orderRepository->getByShop($shopId, $date, $clientName, $pendingOnly);
    }

    /**
     * Filtre une liste de commandes sur le mode de remise.
     *
     * Le tri se fait ici, en PHP, et non par un paramètre d'API : l'API ne
     * connaît pas encore ce champ (voir docs/ENDPOINTS_COMMANDES_WEB.md). Le
     * jour où elle le filtrera elle-même, ce tri deviendra redondant mais pas
     * faux.
     *
     * @param OrderModel[] $orders
     * @return OrderModel[]
     */
    public function filterByFulfilment(array $orders, string $mode): array
    {
        if ($mode !== OrderModel::MODE_COLLECT && $mode !== OrderModel::MODE_DELIVERY) {
            return $orders;
        }

        return array_values(array_filter(
            $orders,
            fn(OrderModel $o) => $o->getFulfilmentMode() === $mode
        ));
    }

    /**
     * Vrai si au moins une commande porte un mode de remise.
     *
     * Sert à distinguer « aucune commande en Click & Collect aujourd'hui » de
     * « l'API ne renvoie pas encore l'information ». Sans cette nuance, le
     * filtre paraîtrait cassé : il viderait la liste sans rien expliquer.
     *
     * @param OrderModel[] $orders
     */
    public function hasFulfilmentData(array $orders): bool
    {
        foreach ($orders as $o) {
            if ($o->getFulfilmentMode() !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pobiera szczegóły zamówienia.
     *
     * @param int $id ID zamówienia
     * @return OrderModel|null
     */
    public function getById(int $id): ?OrderModel
    {
        return $this->orderRepository->getById($id);
    }

    public function insert(array $data): array
    {
        $data['id_shop'] = (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
        return $this->orderRepository->insert($data);
    }

    public function update(int $id, array $data): array
    {
        return $this->orderRepository->update($id, $data);
    }

    public function delete(int $id): array
    {
        return $this->orderRepository->delete($id);
    }
}

