<?php

namespace App\Kitchen\app\Models\Production;

use JsonSerializable;

/**
 * Une période de la journée d'atelier : matin, midi, après-midi.
 *
 * Les bornes viennent de l'API quand elle les sert, sinon de
 * PRODUCTION_PERIODS. Le libellé, lui, reste traduit côté vue : une clé
 * (`morning`) traverse les langues, un libellé non.
 */
class PeriodModel implements JsonSerializable
{
    private string $key;
    private string $start;
    private string $end;
    private ?string $label;

    public function __construct(array $d)
    {
        $this->key   = (string)($d['key'] ?? '');
        $this->start = self::normaliseTime($d['start'] ?? '00:00');
        $this->end   = self::normaliseTime($d['end'] ?? '23:59');
        $this->label = $d['label'] ?? null;
    }

    /** « 6:00 », « 06:00:00 » et « 06:00 » désignent la même chose. */
    private static function normaliseTime(string $t): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return '00:00';
    }

    public function getKey(): string { return $this->key; }
    public function getStart(): string { return $this->start; }
    public function getEnd(): string { return $this->end; }
    public function getLabel(): ?string { return $this->label; }

    /** Borne de début incluse, borne de fin exclue : 11:00 appartient à midi. */
    public function contains(string $time): bool
    {
        $t = self::normaliseTime($time);
        return $t >= $this->start && $t < $this->end;
    }

    public function jsonSerialize(): mixed
    {
        return ['key' => $this->key, 'start' => $this->start, 'end' => $this->end, 'label' => $this->label];
    }
}
