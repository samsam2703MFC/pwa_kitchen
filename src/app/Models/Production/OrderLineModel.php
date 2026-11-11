<?php

namespace App\Kitchen\app\Models\Production;

use App\Kitchen\app\Services\Production\ForecastService;
use JsonSerializable;

/**
 * Une ligne de commande à honorer aujourd'hui.
 *
 * Une commande n'est pas une prévision : elle est due. Le profil de ventes dit
 * ce qui partira probablement ; une commande dit ce qui partira certainement, à
 * une heure connue, et laisser un client repartir sans son plateau coûte plus
 * cher qu'une vitrine un peu vide. Les deux s'additionnent donc — jamais l'un à
 * la place de l'autre.
 *
 * Trois canaux, un seul traitement : magasin (commandé au comptoir), click
 * (click & collect) et livraison. Le canal ne change pas l'arithmétique, il
 * change à qui on va devoir s'excuser.
 */
class OrderLineModel implements JsonSerializable
{
    public const CH_SHOP     = 'shop';
    public const CH_CLICK    = 'click';
    public const CH_DELIVERY = 'delivery';

    public const CHANNELS = [self::CH_SHOP, self::CH_CLICK, self::CH_DELIVERY];

    private ?int $idOrder;
    private ?int $idProduct;
    private ?string $name;
    private ?string $categoryName;
    private string $channel;
    private float $quantity;
    private ?string $dueTime;
    private ?string $period;
    private ?string $reference;
    private ?string $unitName;

    public function __construct(array $d)
    {
        $this->idOrder      = isset($d['id_order']) ? (int)$d['id_order'] : (isset($d['id']) ? (int)$d['id'] : null);
        $this->idProduct    = isset($d['id_product']) ? (int)$d['id_product'] : null;
        $this->name         = $d['name'] ?? $d['product_name'] ?? null;
        $this->categoryName = $d['category_name'] ?? null;
        $this->channel      = self::readChannel($d);
        $this->quantity     = max(0.0, (float)($d['quantity'] ?? 0));
        $this->dueTime      = self::readClock($d['due_time'] ?? $d['pickup_time'] ?? $d['delivery_time'] ?? null);
        $this->period       = isset($d['period']) && $d['period'] !== null ? strtolower(trim((string)$d['period'])) : null;
        $this->reference    = $d['reference'] ?? null;
        $this->unitName     = $d['unit_name'] ?? null;
    }

    /**
     * Le canal, ramené aux trois que l'écran sait nommer.
     *
     * Un canal inconnu devient « magasin » plutôt que d'être écarté : une
     * commande mal étiquetée reste une commande à honorer, et la faire
     * disparaître du calcul serait la pire des deux erreurs possibles.
     */
    private static function readChannel(array $d): string
    {
        $raw = strtolower(trim((string)($d['channel'] ?? $d['source'] ?? '')));

        return match (true) {
            str_contains($raw, 'deliver') || str_contains($raw, 'livr') => self::CH_DELIVERY,
            str_contains($raw, 'click') || str_contains($raw, 'collect')
                || str_contains($raw, 'web') || str_contains($raw, 'online') => self::CH_CLICK,
            default => self::CH_SHOP,
        };
    }

    /** « 2026-08-01 10:30:00 » comme « 10:30 » : on ne garde que l'heure du jour. */
    private static function readClock(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return preg_match('/(\d{1,2}):(\d{2})/', $raw, $m)
            ? sprintf('%02d:%02d', (int)$m[1], (int)$m[2])
            : null;
    }

    public function getIdOrder(): ?int { return $this->idOrder; }
    public function getIdProduct(): ?int { return $this->idProduct; }
    public function getName(): ?string { return $this->name; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getChannel(): string { return $this->channel; }
    public function getQuantity(): float { return $this->quantity; }
    public function getDueTime(): ?string { return $this->dueTime; }
    public function getPeriod(): ?string { return $this->period; }
    public function getReference(): ?string { return $this->reference; }
    public function getUnitName(): ?string { return $this->unitName; }

    /**
     * L'échéance en minutes depuis minuit.
     *
     * Sans heure donnée, la commande est due tout de suite : une commande sans
     * échéance qu'on repousserait à la fin de la journée serait oubliée jusqu'à
     * ce que le client se présente.
     */
    public function getDueMinutes(): int
    {
        return $this->dueTime !== null ? ForecastService::minutesOf($this->dueTime) : 0;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id_order'      => $this->idOrder,
            'id_product'    => $this->idProduct,
            'name'          => $this->name,
            'category_name' => $this->categoryName,
            'channel'       => $this->channel,
            'quantity'      => $this->quantity,
            'due_time'      => $this->dueTime,
            'period'        => $this->period,
            'reference'     => $this->reference,
            'unit_name'     => $this->unitName,
        ];
    }
}
