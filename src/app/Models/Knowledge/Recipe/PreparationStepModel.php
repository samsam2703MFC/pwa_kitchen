<?php

namespace App\Kitchen\app\Models\Knowledge\Recipe;

use JsonSerializable;

/**
 * Une étape de préparation de la fiche technique.
 *
 * `step_number` et `step_description` existent depuis toujours. Les autres champs viennent de
 * l'assistant de la carte produit (docs/carte-produit-pas-a-pas.md) : tous facultatifs. Absents,
 * le pas à pas les retrouve dans le texte de l'étape ; présents, ils font foi.
 */
class PreparationStepModel implements JsonSerializable
{
    private $step_number;
    private $step_description;

    private ?string $step_title;
    private ?string $step_kind;
    private ?int $duration_second;
    private ?int $duration_max_second;
    private ?float $temperature_celsius;
    private ?float $temperature_max_celsius;
    private ?string $speed;
    private bool $reverse;
    private ?string $equipment;
    private ?array $tray;
    private array $ingredients;
    private ?string $photo_path;
    private ?string $critical_note;
    private array $checks;
    private ?array $oven_program;

    public function __construct($data)
    {
        $this->step_number = $data['step_number'] ?? null;
        $this->step_description = $data['step_description'] ?? null;

        $this->step_title = self::str($data['step_title'] ?? null);
        $this->step_kind = self::str($data['step_kind'] ?? null);
        $this->duration_second = self::int($data['duration_second'] ?? null);
        $this->duration_max_second = self::int($data['duration_max_second'] ?? null);
        $this->temperature_celsius = self::num($data['temperature_celsius'] ?? null);
        $this->temperature_max_celsius = self::num($data['temperature_max_celsius'] ?? null);
        $this->speed = self::str($data['speed'] ?? null);
        $this->reverse = !empty($data['reverse']);
        $this->equipment = self::str($data['equipment'] ?? null);
        $this->tray = is_array($data['tray'] ?? null) ? $data['tray'] : null;
        $this->ingredients = is_array($data['ingredients'] ?? null) ? array_values($data['ingredients']) : [];
        $this->photo_path = self::str($data['photo_path'] ?? null);
        $this->critical_note = self::str($data['critical_note'] ?? null);
        $this->checks = [];
        foreach ((array) ($data['checks'] ?? []) as $check) {
            if (is_array($check)) {
                $this->checks[] = new VisualCheckModel($check);
            }
        }
        $this->oven_program = is_array($data['oven_program'] ?? null) ? $data['oven_program'] : null;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'step_number' => $this->step_number,
            'step_description' => $this->step_description,
            'step_title' => $this->step_title,
            'step_kind' => $this->step_kind,
            'duration_second' => $this->duration_second,
            'duration_max_second' => $this->duration_max_second,
            'temperature_celsius' => $this->temperature_celsius,
            'temperature_max_celsius' => $this->temperature_max_celsius,
            'speed' => $this->speed,
            'reverse' => $this->reverse,
            'equipment' => $this->equipment,
            'tray' => $this->tray,
            'ingredients' => $this->ingredients,
            'photo_path' => $this->photo_path,
            'critical_note' => $this->critical_note,
            'checks' => $this->checks,
            'oven_program' => $this->oven_program,
        ];
    }

    public function getStepNumber() { return $this->step_number; }
    public function getStepDescription() { return $this->step_description; }
    public function getStepTitle(): ?string { return $this->step_title; }
    public function getStepKind(): ?string { return $this->step_kind; }
    public function getDurationSecond(): ?int { return $this->duration_second; }
    public function getDurationMaxSecond(): ?int { return $this->duration_max_second; }
    public function getTemperatureCelsius(): ?float { return $this->temperature_celsius; }
    public function getTemperatureMaxCelsius(): ?float { return $this->temperature_max_celsius; }
    public function getSpeed(): ?string { return $this->speed; }
    public function isReverse(): bool { return $this->reverse; }
    public function getEquipment(): ?string { return $this->equipment; }
    /** @return array{rows?:int, cols?:int, tray_name?:string, piece_shape?:string}|null */
    public function getTray(): ?array { return $this->tray; }
    /** @return array<int, array{ingredient_id?:int, recipe_id?:int, quantity?:float}> */
    public function getIngredients(): array { return $this->ingredients; }
    public function getPhotoPath(): ?string { return $this->photo_path; }
    public function getCriticalNote(): ?string { return $this->critical_note; }
    /** @return VisualCheckModel[] */
    public function getChecks(): array { return $this->checks; }
    public function getOvenProgram(): ?array { return $this->oven_program; }

    private static function str($v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return $v === '' ? null : $v;
    }

    private static function int($v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function num($v): ?float
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        return is_numeric($v) ? (float) $v : null;
    }
}
