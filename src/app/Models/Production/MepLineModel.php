<?php

namespace App\Kitchen\app\Models\Production;

use JsonSerializable;

/**
 * Une ligne de MEP : ce qui a été préparé la veille pour aujourd'hui.
 *
 * Sa validation est la première action de la journée — c'est elle qui fait
 * passer la quantité en production, donc en stock, donc en vente.
 */
class MepLineModel implements JsonSerializable
{
    public const STATUS_PREPARED  = 'PREPARED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_SKIPPED   = 'SKIPPED';

    private ?int $id;
    private ?int $idProduct;
    private ?string $name;
    private ?string $categoryName;
    private ?string $period;
    private float $quantityPlanned;
    private ?float $quantityValidated;
    private float $quantityShelved;
    private ?string $unitName;
    private string $status;

    public function __construct(array $d)
    {
        $this->id                = isset($d['id']) ? (int)$d['id'] : null;
        $this->idProduct         = isset($d['id_product']) ? (int)$d['id_product'] : null;
        $this->name              = $d['name'] ?? null;
        $this->categoryName      = $d['category_name'] ?? null;
        $this->period            = isset($d['period']) ? strtolower((string)$d['period']) : null;
        $this->quantityPlanned   = (float)($d['quantity_planned'] ?? 0);
        // null ≠ 0 : « pas encore validé » n'est pas « validé à zéro ».
        $this->quantityValidated = isset($d['quantity_validated']) && $d['quantity_validated'] !== null
            ? (float)$d['quantity_validated']
            : null;
        // Ce qui est déjà passé en rayon. Produire et mettre en vente sont
        // deux gestes : une MEP validée dit ce qui est sorti du four, pas ce
        // que la caisse peut vendre. Voir docs/ENDPOINTS_PRODUCTION.md.
        $this->quantityShelved   = max(0.0, (float)($d['quantity_shelved'] ?? 0));
        $this->unitName          = $d['unit_name'] ?? null;
        $this->status            = self::readStatus($d);
    }

    private static function readStatus(array $d): string
    {
        $raw = strtoupper((string)($d['status'] ?? ''));
        if (in_array($raw, [self::STATUS_PREPARED, self::STATUS_VALIDATED, self::STATUS_SKIPPED], true)) {
            return $raw;
        }
        // Sans statut, la quantité validée fait foi.
        return isset($d['quantity_validated']) && $d['quantity_validated'] !== null
            ? self::STATUS_VALIDATED
            : self::STATUS_PREPARED;
    }

    public function getId(): ?int { return $this->id; }
    public function getIdProduct(): ?int { return $this->idProduct; }
    public function getName(): ?string { return $this->name; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getPeriod(): ?string { return $this->period; }
    public function getQuantityPlanned(): float { return $this->quantityPlanned; }
    public function getQuantityValidated(): ?float { return $this->quantityValidated; }
    public function getQuantityShelved(): float { return $this->quantityShelved; }

    /**
     * Ce qui reste à porter en rayon.
     *
     * Zéro tant que la MEP n'est pas validée : on ne met pas en vente une
     * quantité que personne n'a encore constatée.
     */
    public function getRemainingToShelf(): float
    {
        return max(0.0, ($this->quantityValidated ?? 0.0) - $this->quantityShelved);
    }
    public function getUnitName(): ?string { return $this->unitName; }
    public function getStatus(): string { return $this->status; }

    public function isValidated(): bool { return $this->status === self::STATUS_VALIDATED; }
    public function isSkipped(): bool { return $this->status === self::STATUS_SKIPPED; }
    public function isPending(): bool { return $this->status === self::STATUS_PREPARED; }

    /** Quantité proposée à la saisie : le validé s'il existe, sinon le prévu. */
    public function getDefaultQuantity(): float
    {
        return $this->quantityValidated ?? $this->quantityPlanned;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'id_product' => $this->idProduct,
            'name' => $this->name,
            'category_name' => $this->categoryName,
            'period' => $this->period,
            'quantity_planned' => $this->quantityPlanned,
            'quantity_validated' => $this->quantityValidated,
            'quantity_shelved' => $this->quantityShelved,
            'unit_name' => $this->unitName,
            'status' => $this->status,
        ];
    }
}
