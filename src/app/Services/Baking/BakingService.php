<?php

namespace App\Kitchen\app\Services\Baking;

use App\Kitchen\app\Models\Baking\BakingBatchModel;
use App\Kitchen\app\Repositories\Baking\BakingRepository;
use App\Kitchen\core\Support\GlobalRegistry;

class BakingService
{
    /** Étapes affichables, dans l'ordre du cycle. */
    public const STAGES = [
        BakingBatchModel::STAGE_PREP,
        BakingBatchModel::STAGE_COOK,
        BakingBatchModel::STAGE_FINISH,
    ];

    /** Mémoire de la requête : l'écran Production lit le plan deux fois. */
    private array $planCache = [];

    public function __construct(
        private BakingRepository $bakingRepository
    ) {}

    private function getShopId(): int
    {
        return (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
    }

    /**
     * Plan de cuisson en cours.
     *
     * @return array{server_time: ?string, batches: BakingBatchModel[]}|null
     *         null = l'API ne sert pas encore le plan
     */
    public function getPlan(?string $date = null): ?array
    {
        $date = $date ?? date('Y-m-d');
        if (!array_key_exists($date, $this->planCache)) {
            $shopId = $this->getShopId();
            $this->planCache[$date] = $shopId > 0
                ? $this->bakingRepository->getPlan($shopId, $date)
                : null;
        }
        return $this->planCache[$date];
    }

    /**
     * Ce que l'écran affiche : les fournées non terminées, dans l'ordre où la
     * cuisine veut les lire — ce qui est au four d'abord, puis les finitions,
     * puis les préparations, puis ce qui n'a pas commencé.
     *
     * @param BakingBatchModel[] $batches
     * @return BakingBatchModel[]
     */
    public function active(array $batches): array
    {
        $rows = array_values(array_filter($batches, fn(BakingBatchModel $b) => !$b->isDone()));

        $rang = [
            BakingBatchModel::STAGE_COOK   => 0,
            BakingBatchModel::STAGE_FINISH => 1,
            BakingBatchModel::STAGE_PREP   => 2,
        ];

        usort($rows, function (BakingBatchModel $a, BakingBatchModel $b) use ($rang) {
            // Ce qui n'a pas commencé passe après tout le reste, quelle que
            // soit son étape théorique.
            $wa = $a->isWaiting() ? 1 : 0;
            $wb = $b->isWaiting() ? 1 : 0;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            $ra = $rang[$a->getStage()] ?? 9;
            $rb = $rang[$b->getStage()] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $a->getStageEnd() <=> $b->getStageEnd();
        });

        return $rows;
    }

    /**
     * Nombre de fournées par étape, pour les pastilles des filtres.
     *
     * @param BakingBatchModel[] $batches
     */
    public function countByStage(array $batches): array
    {
        $counts = array_fill_keys(self::STAGES, 0);
        foreach ($batches as $b) {
            if (isset($counts[$b->getStage()])) {
                $counts[$b->getStage()]++;
            }
        }
        $counts['all'] = count($batches);
        return $counts;
    }

    /**
     * @param BakingBatchModel[] $batches
     * @return BakingBatchModel[]
     */
    public function filterByStage(array $batches, string $stage): array
    {
        if (!in_array($stage, self::STAGES, true)) {
            return $batches;
        }
        return array_values(array_filter($batches, fn(BakingBatchModel $b) => $b->getStage() === $stage));
    }

    /**
     * La file des ordres : quoi faire, dans l'ordre où ça devient urgent.
     *
     * La frise répond à « où en est la matinée », les cartes à « qu'est-ce que
     * je fais sur cette fournée-là ». Il manquait la question qu'on se pose en
     * entrant dans le fournil : « je fais quoi, là, maintenant ». D'où un tri
     * par échéance seule — un four à vider dans deux minutes passe devant une
     * préparation à lancer dans vingt, quelle que soit l'étape.
     *
     * @param BakingBatchModel[] $batches
     * @return BakingBatchModel[]
     */
    public function orders(array $batches): array
    {
        $rows = array_values(array_filter(
            $batches,
            fn(BakingBatchModel $b) => $b->getActionKey() !== null
        ));

        usort($rows, function (BakingBatchModel $a, BakingBatchModel $b) {
            $c = $a->getDueTime() <=> $b->getDueTime();
            // À échéance égale, ce qui est déjà engagé passe devant : on ne
            // lance pas une préparation avec un four plein qui attend.
            return $c !== 0 ? $c : ($a->isWaiting() <=> $b->isWaiting());
        });

        return $rows;
    }

    /**
     * Ce qu'il reste à faire de la journée.
     *
     * La frise ne garde pas le validé. Une fournée entièrement faite n'appelle
     * plus rien : la laisser consomme de la hauteur et de la largeur pour du
     * révolu, et à midi la moitié du plan ne serait plus que de l'archive. Ce
     * qui se pilote, c'est ce qui vient.
     *
     * Le filtre par étape garde les fournées qui la traversent — c'est le
     * poste qu'on regarde, pas seulement l'instant.
     *
     * @param BakingBatchModel[] $batches
     * @return BakingBatchModel[]
     */
    public function dayPlan(array $batches, string $stage = 'all'): array
    {
        $rows = array_values(array_filter(
            $batches,
            fn(BakingBatchModel $b) => !$b->isFullyValidated()
                && (!in_array($stage, self::STAGES, true) || $b->hasStage($stage))
        ));

        usort($rows, fn(BakingBatchModel $a, BakingBatchModel $b) => $a->getPrepStart() <=> $b->getPrepStart());

        return $rows;
    }

    /**
     * Les fours réellement présents dans le plan.
     *
     * Pas la liste du magasin : un four à l'arrêt n'a pas à occuper une
     * pastille. Ce sont ceux sur lesquels il y a du travail aujourd'hui.
     *
     * @param BakingBatchModel[] $batches
     * @return string[]
     */
    public function ovens(array $batches): array
    {
        $seen = [];
        foreach ($batches as $b) {
            $name = $b->getOvenName();
            if ($name !== null && $name !== '') {
                $seen[$name] = true;
            }
        }
        $out = array_keys($seen);
        usort($out, 'strnatcasecmp');
        return $out;
    }

    /**
     * Fenêtre temporelle du plan de charge, arrondie à l'heure.
     *
     * Calculée sur les fournées affichées plutôt que fixée à la journée : à
     * 07 h, une frise qui va de 05 h à 19 h consacre l'essentiel de sa largeur
     * à du vide.
     *
     * @param BakingBatchModel[] $batches
     * @return array{from: int, to: int, hours: string[]}
     */
    public function window(array $batches, int $nowMinutes, bool $fromNow = false): array
    {
        $starts = array_map(fn(BakingBatchModel $b) => $b->getPrepStart(), $batches);
        $ends   = array_map(fn(BakingBatchModel $b) => $b->getShelfTime(), $batches);

        $from = $starts !== [] ? min($starts) : $nowMinutes - 60;
        $to   = $ends   !== [] ? max($ends)   : $nowMinutes + 120;

        // Cadrée sur ce qui vient : l'heure en cours et la suite. Une fournée
        // commencée à 6 h tirerait sinon la frise vers un passé qu'on ne pilote
        // plus, et écraserait l'après-midi sur trois centimètres.
        if ($fromNow) {
            $from = max($from, $nowMinutes);
        }

        // L'instant présent doit rester dans le cadre, même quand toutes les
        // fournées visibles sont derrière ou devant.
        $from = intdiv(min($from, $nowMinutes), 60) * 60;
        $to   = (int)(ceil(max($to, $nowMinutes) / 60) * 60);
        if ($to <= $from) {
            $to = $from + 60;
        }

        $hours = [];
        for ($m = $from; $m < $to; $m += 60) {
            $hours[] = BakingBatchModel::toClock($m);
        }

        return ['from' => $from, 'to' => $to, 'hours' => $hours];
    }

    /**
     * Programme une fournée pour combler un manque constaté.
     *
     * L'écran de production voit qu'il manquera 24 croissants ; ce geste crée
     * la fournée correspondante, qui apparaît aussitôt dans le plan de cuisson
     * et fait passer le produit en « préparation ». Anticiper, c'est ça : on
     * ne découvre pas la rupture au moment où la vitrine est vide.
     */
    public function planBatch(int $idProduct, float $quantity, ?int $employeeId = null): array
    {
        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return ['success' => false, 'description' => 'shop_unknown'];
        }

        $payload = [
            'id_product' => $idProduct,
            'quantity'   => $quantity,
            // Dit au serveur d'où vient la demande : une fournée programmée
            // depuis un manque constaté ne se planifie pas comme une fournée
            // de routine, elle est attendue au plus tôt.
            'source'     => 'SHORTFALL',
        ];
        if ($employeeId !== null) {
            $payload['id_employee'] = $employeeId;
        }

        return $this->bakingRepository->createBatch($shopId, $payload);
    }

    /** Fait avancer une fournée. Renvoie la réponse brute de l'API. */
    public function advance(int $batchId, string $status, ?int $employeeId = null, ?int $allottedMinutes = null): array
    {
        return $this->bakingRepository->advance($batchId, $status, $employeeId, $allottedMinutes);
    }

    /**
     * Heure courante en minutes depuis minuit, calée sur le serveur quand il
     * la donne. Une tablette d'atelier n'est presque jamais à l'heure.
     */
    public function nowMinutes(?string $serverTime = null): int
    {
        if ($serverTime !== null && preg_match('/^(\d{1,2}):(\d{2})/', $serverTime)) {
            return BakingBatchModel::toMinutes($serverTime);
        }
        return (int)date('G') * 60 + (int)date('i');
    }
}
