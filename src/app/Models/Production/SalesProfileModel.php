<?php

namespace App\Kitchen\app\Models\Production;

/**
 * Le profil de ventes du jour : combien se vend chaque produit, créneau par
 * créneau, en moyenne sur les mêmes jours de semaine précédents.
 *
 * Ce n'est pas une prévision — c'est de l'histoire agrégée. La projection est
 * faite par ForecastService. Voir docs/ENDPOINTS_PRODUCTION.md §4.
 */
class SalesProfileModel
{
    private int $granularityMinutes;
    private int $weeks;
    private int $samples;
    private bool $weekdayOnly;
    /** @var string[] heures de début de créneau, « HH:MM » */
    private array $slots;
    /** @var array<int, float[]> id_product => ventes moyennes par créneau */
    private array $expected = [];

    public function __construct(array $d)
    {
        $this->granularityMinutes = max(1, (int)($d['granularity_minutes'] ?? 30));
        $this->weeks              = (int)($d['weeks'] ?? 0);
        $this->samples            = (int)($d['samples'] ?? 0);
        $this->weekdayOnly        = (bool)($d['weekday_only'] ?? true);
        $this->slots              = array_values(array_map('strval', $d['slots'] ?? []));

        foreach ($d['products'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['id_product'])) {
                continue;
            }
            $this->expected[(int)$row['id_product']] = array_map('floatval', $row['expected'] ?? []);
        }
    }

    public function getGranularityMinutes(): int { return $this->granularityMinutes; }
    public function getWeeks(): int { return $this->weeks; }
    /** Journées réellement agrégées ; peut être inférieur à getWeeks(). */
    public function getSamples(): int { return $this->samples; }
    public function isWeekdayOnly(): bool { return $this->weekdayOnly; }
    /** @return string[] */
    public function getSlots(): array { return $this->slots; }

    public function hasProduct(int $idProduct): bool
    {
        return isset($this->expected[$idProduct]);
    }

    /**
     * Ventes attendues d'un produit entre deux heures de la journée.
     *
     * Renvoie null quand le produit est absent du profil : « jamais vendu à
     * cette heure-là » et « inconnu du profil » n'appellent pas la même
     * conclusion, et traiter le second comme un zéro proposerait des
     * recuissons sur des produits dont on ne sait rien.
     *
     * Les créneaux partiellement couverts sont pris au prorata : une fenêtre
     * qui démarre à 06h15 sur des créneaux de 30 minutes ne prend que la
     * moitié de celui de 06h00.
     */
    public function expectedBetween(int $idProduct, int $fromMinutes, int $toMinutes): ?float
    {
        if (!isset($this->expected[$idProduct])) {
            return null;
        }
        if ($toMinutes <= $fromMinutes) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($this->expected[$idProduct] as $i => $value) {
            $slotStart = $this->slotStartMinutes($i);
            if ($slotStart === null) {
                continue;
            }
            $slotEnd = $slotStart + $this->granularityMinutes;

            $overlap = min($toMinutes, $slotEnd) - max($fromMinutes, $slotStart);
            if ($overlap <= 0) {
                continue;
            }
            $total += $value * ($overlap / $this->granularityMinutes);
        }
        return $total;
    }

    private function slotStartMinutes(int $index): ?int
    {
        if (!isset($this->slots[$index])) {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $this->slots[$index], $m)) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }
}
