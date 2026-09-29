<?php

namespace App\Kitchen\app\Models\Production;

use JsonSerializable;

/**
 * Un produit du catalogue de production : sa catégorie, ses périodes, sa
 * taille de fournée.
 *
 * Distinct de Knowledge\Product\ProductModel, qui décrit la fiche technique
 * (allergènes, conservation, réchauffe). Ici on ne retient que ce qui sert à
 * décider quoi enfourner.
 */
class ProductionProductModel implements JsonSerializable
{
    private ?int $idProduct;
    private ?string $name;
    private ?int $idCategory;
    private ?string $categoryName;
    /** @var string[] */
    private array $periods;
    private ?float $batchSize;
    private ?string $unitName;
    private int $leadMinutes;
    private bool $isActive;
    private bool $isPdb;
    private bool $isPdm;
    /** @var array<string, float> */
    private array $minimums;
    private ?string $sector;
    private ?string $sectorName;
    private ?string $mainPhotoPath;

    public function __construct(array $d)
    {
        $this->idProduct    = isset($d['id_product']) ? (int)$d['id_product'] : (isset($d['id']) ? (int)$d['id'] : null);
        $this->name         = $d['name'] ?? null;
        $this->idCategory   = isset($d['id_category']) ? (int)$d['id_category'] : null;
        $this->categoryName = $d['category_name'] ?? null;
        $this->periods      = self::readPeriods($d);
        // batch_size absent : le produit se traite à l'unité. La vue le
        // signale, parce qu'une proposition « 17 pièces » sur un four qui sort
        // des plaques de 24 n'est pas exécutable.
        $this->batchSize    = isset($d['batch_size']) && (float)$d['batch_size'] > 0 ? (float)$d['batch_size'] : null;
        $this->unitName     = $d['unit_name'] ?? null;
        $this->leadMinutes  = max(0, (int)($d['production_lead_minutes'] ?? 0));
        $this->isActive     = !isset($d['is_active']) || (bool)$d['is_active'];
        $this->isPdb        = self::readPdb($d);
        $this->isPdm        = self::readFlag($d, ['is_pdm', 'has_shop_minimum', 'is_min_stock']);
        $this->minimums     = self::readMinimums($d);
        [$this->sector, $this->sectorName] = self::readSector($d);
        $this->mainPhotoPath = $d['main_photo_path'] ?? ($d['photos']['main_photo_path'] ?? null);
    }

    /**
     * Le secteur de production : boulangerie, traiteur, et ce que le
     * back-office déclarera ensuite.
     *
     * Deux ateliers ne travaillent pas le même produit ni au même rythme, mais
     * ils tiennent les mêmes écrans — d'où un filtre en tête plutôt qu'un
     * second module. La clé est libre : l'écran liste ce que le catalogue
     * porte, il ne connaît pas de liste fermée. Absente, le magasin n'a qu'un
     * secteur et le sélecteur ne s'affiche pas.
     *
     * @return array{0: ?string, 1: ?string} clé, libellé
     */
    private static function readSector(array $d): array
    {
        $key = null;
        foreach (['sector', 'sector_key', 'production_sector', 'id_sector'] as $k) {
            if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) {
                $key = strtolower(trim((string)$d[$k]));
                break;
            }
        }
        if ($key === null) {
            return [null, null];
        }

        $name = $d['sector_name'] ?? $d['sector_label'] ?? null;
        return [$key, $name !== null ? (string)$name : null];
    }

    /**
     * Le minimum à tenir en magasin, période par période.
     *
     * Un minimum n'est pas une prévision : c'est un plancher de présentation.
     * Une vitrine de boulangerie avec deux baguettes ne vend pas, même si deux
     * baguettes suffisent à la demande de 16 h. Le back-office peut le donner
     * par période (`pdm_minimums`) ou en une valeur pour la journée
     * (`pdm_min`) ; on accepte les deux, la seconde valant pour toutes.
     *
     * @return array<string, float> indexé par clé de période, plus '*' pour la
     *         valeur générale
     */
    private static function readMinimums(array $d): array
    {
        $out = [];

        foreach (['pdm_minimums', 'shop_minimums', 'min_stock_by_period'] as $k) {
            if (is_array($d[$k] ?? null)) {
                foreach ($d[$k] as $period => $value) {
                    if (is_numeric($value)) {
                        $out[strtolower(trim((string)$period))] = max(0.0, (float)$value);
                    }
                }
                break;
            }
        }

        foreach (['pdm_min', 'min_stock', 'shop_minimum'] as $k) {
            if (isset($d[$k]) && is_numeric($d[$k])) {
                $out['*'] = max(0.0, (float)$d[$k]);
                break;
            }
        }

        return $out;
    }

    /** Premier des alias présent et non nul, sinon false. */
    private static function readFlag(array $d, array $keys): bool
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) {
                return (bool)$d[$k];
            }
        }
        return false;
    }

    /**
     * « Préparé la veille » — pdb, prep day before.
     *
     * Le catalogue de la base de connaissances porte déjà
     * `is_prepared_before_sales`, qui dit la même chose ; on l'accepte en
     * repli plutôt que d'exiger un nouveau champ là où l'information existe.
     * Voir docs/ENDPOINTS_PRODUCTION.md.
     */
    private static function readPdb(array $d): bool
    {
        return self::readFlag($d, ['is_pdb', 'is_prep_day_before', 'prep_day_before', 'is_prepared_before_sales']);
    }

    /**
     * Accepte « periods: [...] » comme « period: "morning" » : un produit
     * mono-période reste plus naturel à écrire au singulier côté serveur.
     *
     * @return string[]
     */
    private static function readPeriods(array $d): array
    {
        $raw = $d['periods'] ?? $d['period'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn($p) => is_string($p) ? strtolower(trim($p)) : null,
            $raw
        )));
    }

    public function getIdProduct(): ?int { return $this->idProduct; }
    public function getName(): ?string { return $this->name; }
    public function getIdCategory(): ?int { return $this->idCategory; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    /** @return string[] */
    public function getPeriods(): array { return $this->periods; }
    public function getBatchSize(): ?float { return $this->batchSize; }
    /** Batch utilisable pour un calcul : 1 quand le serveur ne le donne pas. */
    public function getEffectiveBatchSize(): float { return $this->batchSize ?? 1.0; }
    public function hasBatchSize(): bool { return $this->batchSize !== null; }
    public function getUnitName(): ?string { return $this->unitName; }
    public function getLeadMinutes(): int { return $this->leadMinutes; }
    public function isActive(): bool { return $this->isActive; }
    /** Se prépare la veille : seuls ces produits entrent dans la MEP de demain. */
    public function isPdb(): bool { return $this->isPdb; }
    /** Tenu à un minimum en magasin — pdm, « présence de minimum ». */
    public function isPdm(): bool { return $this->isPdm; }
    public function getSector(): ?string { return $this->sector; }
    /** Libellé du secteur, avec repli sur la clé — jamais vide quand il y a un secteur. */
    public function getSectorName(): ?string
    {
        return $this->sectorName ?? ($this->sector !== null ? ucfirst($this->sector) : null);
    }

    /**
     * Le minimum à tenir en rayon sur une période.
     *
     * null quand rien n'est déclaré : c'est différent de zéro. Zéro veut dire
     * « on n'en tient pas sur cette période » — un sandwich le matin — et se
     * lit comme une décision ; null veut dire qu'on ne sait pas, et l'écran
     * l'écrit.
     */
    public function getMinimum(string $periodKey): ?float
    {
        $key = strtolower($periodKey);
        if (array_key_exists($key, $this->minimums)) {
            return $this->minimums[$key];
        }
        return $this->minimums['*'] ?? null;
    }

    /** @return array<string, float> */
    public function getMinimums(): array { return $this->minimums; }
    public function hasMinimums(): bool { return $this->minimums !== []; }

    public function inSector(?string $sector): bool
    {
        return $sector === null || $sector === '' || $this->sector === $sector;
    }

    public function belongsTo(string $periodKey): bool
    {
        return in_array(strtolower($periodKey), $this->periods, true);
    }

    public function hasMainPhoto(): bool { return !empty($this->mainPhotoPath); }

    public function getMainPhotoUrl(): ?string
    {
        if (empty($this->mainPhotoPath)) {
            return null;
        }
        if (str_starts_with($this->mainPhotoPath, 'r2://')) {
            return rtrim(SHARED_FILES_URL, '/') . '/' . ltrim(substr($this->mainPhotoPath, 5), '/');
        }
        return $this->mainPhotoPath;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id_product' => $this->idProduct,
            'name' => $this->name,
            'id_category' => $this->idCategory,
            'category_name' => $this->categoryName,
            'periods' => $this->periods,
            'batch_size' => $this->batchSize,
            'unit_name' => $this->unitName,
            'production_lead_minutes' => $this->leadMinutes,
            'is_active' => $this->isActive,
            'is_pdb' => $this->isPdb,
            'is_pdm' => $this->isPdm,
            'pdm_minimums' => $this->minimums,
            'sector' => $this->sector,
            'sector_name' => $this->sectorName,
            'main_photo_path' => $this->mainPhotoPath,
        ];
    }
}
