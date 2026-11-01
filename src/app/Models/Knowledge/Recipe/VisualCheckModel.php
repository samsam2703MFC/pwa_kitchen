<?php

namespace App\Kitchen\app\Models\Knowledge\Recipe;

use App\Kitchen\app\Models\Knowledge\Product\ProductPhotoModel;
use JsonSerializable;

/**
 * Une photo de vérification « à faire / à éviter » (do / don't) : ce à quoi le produit doit
 * ressembler, ou ne doit pas ressembler, à une étape (cuisson, façonnage, garnissage…)
 * ou au contrôle final avant la mise en vitrine.
 */
class VisualCheckModel implements JsonSerializable
{
    private string $verdict;
    private ?string $photo_path;
    private ?string $caption;

    public function __construct(array $data)
    {
        $v = strtolower(trim((string) ($data['verdict'] ?? '')));
        // « dont », « don't », « ko », « nok », « bad » → à éviter ; tout le reste → à faire
        $this->verdict = in_array($v, ['dont', "don't", 'ko', 'nok', 'bad', 'no'], true) ? 'dont' : 'do';
        $path = trim((string) ($data['photo_path'] ?? ''));
        $this->photo_path = $path === '' ? null : $path;
        $caption = trim((string) ($data['caption'] ?? ''));
        $this->caption = $caption === '' ? null : $caption;
    }

    public function jsonSerialize(): mixed
    {
        return ['verdict' => $this->verdict, 'photo_path' => $this->photo_path, 'caption' => $this->caption];
    }

    public function getVerdict(): string { return $this->verdict; }
    public function isDo(): bool { return $this->verdict === 'do'; }
    public function getPhotoPath(): ?string { return $this->photo_path; }
    public function getPhotoUrl(): ?string { return ProductPhotoModel::urlFor($this->photo_path); }
    public function getCaption(): ?string { return $this->caption; }
    public function isEmpty(): bool { return $this->photo_path === null && $this->caption === null; }
}
