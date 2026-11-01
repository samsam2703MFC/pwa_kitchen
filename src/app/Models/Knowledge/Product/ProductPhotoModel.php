<?php

namespace App\Kitchen\app\Models\Knowledge\Product;

use JsonSerializable;

class ProductPhotoModel implements JsonSerializable
{
    private $main_photo_path;
    private $photo_1_path;
    private $photo_2_path;
    private $photo_3_path;

    public function __construct($data)
    {
        $this->main_photo_path = $data['main_photo_path'] ?? null;
        $this->photo_1_path = $data['photo_1_path'] ?? null;
        $this->photo_2_path = $data['photo_2_path'] ?? null;
        $this->photo_3_path = $data['photo_3_path'] ?? null;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'main_photo_path' => $this->main_photo_path,
            'photo_1_path' => $this->photo_1_path,
            'photo_2_path' => $this->photo_2_path,
            'photo_3_path' => $this->photo_3_path
        ];
    }

    public function getMainPhotoPath() { return $this->main_photo_path; }
    public function getPhoto1Path() { return $this->photo_1_path; }
    public function getPhoto2Path() { return $this->photo_2_path; }
    public function getPhoto3Path() { return $this->photo_3_path; }

    public function hasMainPhoto(): bool { return !empty($this->main_photo_path); }
    public function hasPhoto1(): bool { return !empty($this->photo_1_path); }
    public function hasPhoto2(): bool { return !empty($this->photo_2_path); }
    public function hasPhoto3(): bool { return !empty($this->photo_3_path); }

    /**
     * Converts r2://path to full URL using SHARED_FILES_URL base
     */
    private function resolveUrl(?string $path): ?string
    {
        return self::urlFor($path);
    }

    /** Le même calcul, pour les autres photos de la fiche (étapes, do / don't). */
    public static function urlFor(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'r2://')) {
            $relativePath = substr($path, 5); // strip "r2://"
            return rtrim(SHARED_FILES_URL, '/') . '/' . ltrim($relativePath, '/');
        }
        return $path;
    }

    public function getMainPhotoUrl(): ?string { return $this->resolveUrl($this->main_photo_path); }
    public function getPhoto1Url(): ?string { return $this->resolveUrl($this->photo_1_path); }
    public function getPhoto2Url(): ?string { return $this->resolveUrl($this->photo_2_path); }
    public function getPhoto3Url(): ?string { return $this->resolveUrl($this->photo_3_path); }
}

