<?php

namespace App\Kitchen\app\Services\Staff;

use App\Kitchen\app\Repositories\Staff\StaffRepository;
use App\Kitchen\core\Support\GlobalRegistry;

/**
 * Qui travaille aujourd'hui.
 *
 * Une seule source : `GET /shops/{id}/schedule?date=…`. Qui est au planning
 * travaille ; qui n'y est pas ne travaille pas, sa fiche existât-elle. Le
 * planning porte les personnes, il n'y a donc rien à croiser.
 *
 * ── Rien n'est inventé ── (révision du 13/08/2026)
 * Si l'une des deux routes ne répond pas, on ne propose PERSONNE et l'écran
 * nomme la route à créer. On rendait auparavant toute l'équipe active « pour ne
 * pas bloquer » : c'était confortable et trompeur — un trou passait alors pour
 * un fonctionnement normal, et le back n'était jamais réclamé.
 *
 * Reste une distinction qui compte : un planning servi et VIDE n'est pas une
 * panne, c'est une réponse. Personne n'est de service ce jour-là, et l'écran le
 * dit dans ces mots — pas dans ceux d'une API manquante.
 *
 * Le PIN ne sort jamais d'ici : il ne sert qu'à la vérification serveur, dans
 * ChecklistService::verifyPin(), qui interroge sa propre source. Un écran n'a
 * besoin que d'un nom et de deux initiales.
 *
 * Le croisement et les filtres sont purs et vérifiés sans réseau —
 * bin/staff-test.php. Voir docs/ENDPOINTS_EMPLOYES_PLANNING.md.
 */
class StaffService
{
    public function __construct(
        private StaffRepository $staffRepository
    ) {}

    /**
     * La route qui n'a pas répondu au dernier appel, ou null.
     *
     * L'écran l'affiche telle quelle. Depuis le 13/08/2026, on ne remplace plus
     * une réponse manquante par une liste plausible : on nomme la route.
     */
    private ?string $missing = null;

    public function missingApi(): ?string
    {
        return $this->missing;
    }

    private function getShopId(): int
    {
        return (int)(GlobalRegistry::get('user')['shop_id'] ?? 0);
    }

    /**
     * L'équipe active, chacun marqué présent ou non au planning de `$date`.
     *
     * @param string|null $date  Jour consulté (Y-m-d). Sans date, on ne demande
     *                           pas le planning : la question « qui est de
     *                           service » n'a pas de sens hors d'un jour.
     *
     * @return array<int, array{id: mixed, name: string, initials: string, on_schedule: ?bool}>|null
     *         null = liste non servie
     */
    public function getEmployees(?string $date = null): ?array
    {
        $this->missing = null;

        $shopId = $this->getShopId();
        if ($shopId <= 0) {
            return null;
        }

        // Sans date, la question « qui est de service » n'a pas de sens : ce
        // n'est pas une route manquante, c'est un appel qui ne la pose pas.
        $date = ($date === null || $date === '') ? date('Y-m-d') : $date;

        $rows = $this->staffRepository->getSchedule($shopId, $date);
        if ($rows === null) {
            $this->missing = 'GET /shops/{id}/schedule?date=' . $date;
            return null;
        }

        $people = self::peopleOf($rows, $date);

        // Des lignes de planning dont aucune ne donne un nom : la route répond,
        // mais pas dans la forme attendue. C'est un problème d'API, pas un jour
        // sans personne — et le dire évite de chercher au back-office ce qui se
        // règle chez le développeur.
        if ($people === [] && $rows !== []) {
            $this->missing = 'GET /shops/{id}/schedule — réponse sans nom d\'employé';
        }

        return $people;
    }

    /**
     * Les personnes d'un planning, dédoublonnées.
     *
     * ── Ce qu'on lit, et pourquoi si largement ──
     * Une ligne de planning porte tantôt l'employé à plat, tantôt une fiche
     * imbriquée, et le nom sous trois ou quatre orthographes. On accepte donc
     * plusieurs formes — ce n'est pas de la complaisance : chacune correspond à
     * une façon dont la liste se viderait en silence, et une liste vide rend la
     * checklist inachevable.
     *
     * ── Deux exigences fermes ──
     * Un identifiant ET un nom. Un badge sans nom ne se choisit pas, et signer
     * sous « #47 » ne vaut pas mieux que ne pas signer.
     *
     * ── Le dédoublonnage n'est pas cosmétique ──
     * Quelqu'un qui fait deux services dans la journée a deux lignes. Sans lui,
     * il apparaîtrait deux fois dans la modale — et on douterait d'avoir touché
     * le bon.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id: mixed, name: string, initials: string, on_schedule: ?bool}>
     */
    public static function peopleOf(array $rows, string $date): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Une ligne datée d'un autre jour est écartée même si l'endpoint
            // est censé filtrer : une ligne de la veille laissée passer ferait
            // signer quelqu'un qui n'était pas là.
            foreach (['date', 'day', 'work_date', 'scheduled_for_date'] as $k) {
                if (!empty($row[$k]) && is_string($row[$k])) {
                    if (substr(trim($row[$k]), 0, 10) !== $date) {
                        continue 2;
                    }
                    break;
                }
            }

            $fiche = (isset($row['employee']) && is_array($row['employee'])) ? $row['employee'] : $row;

            // Une fiche explicitement désactivée ne signe pas, même inscrite au
            // planning. On n'écarte que ce qui est EXPLICITEMENT inactif.
            if (self::activeOnly([$fiche]) === []) {
                continue;
            }

            $id = self::idOf($row, $fiche);
            if ($id === null) {
                continue;
            }
            $name = self::nameOf($fiche);
            if ($name === '') {
                continue;
            }

            // Le poste du jour vient du planning (la ligne), à défaut de la
            // fiche : c'est l'affectation qui compte, pas l'intitulé général.
            $role = self::roleOf($row);
            if ($role === '') {
                $role = self::roleOf($fiche);
            }

            // Deux services dans la journée : une seule personne. Si une ligne
            // porte le poste et l'autre non, on garde celui qui est renseigné —
            // sans quoi la dernière ligne, muette, l'effacerait.
            if (isset($out[$id]) && $role === '' && ($out[$id]['role'] ?? '') !== '') {
                $role = $out[$id]['role'];
            }
            $out[$id] = self::card(['id' => $id, 'name' => $name, 'role' => $role], true);
        }

        return array_values($out);
    }

    /** @return array{id: mixed, name: string, initials: string, role: string, on_schedule: ?bool} */
    private static function card(array $e, ?bool $onSchedule): array
    {
        return [
            'id'          => $e['id'] ?? null,
            'name'        => (string)($e['name'] ?? ''),
            'initials'    => self::initials((string)($e['name'] ?? '')),
            // Le poste, s'il est servi. Vide sinon : l'écran n'invente pas de
            // sous-titre. Confirmé disponible par endpoint (GET /positions,
            // /employees/{id}/positions, /position-levels) ; côté planning le
            // champ exact est à confirmer au jeton, d'où la lecture large.
            'role'        => (string)($e['role'] ?? ''),
            'on_schedule' => $onSchedule,
        ];
    }

    /**
     * Le poste affichable d'une ligne de planning ou d'une fiche, ou ''.
     *
     * Lecture large — le champ n'est pas confirmé au swagger (planning vide
     * sans jeton) — mais bornée à des noms plausibles. Un objet {name} est
     * accepté (le back sert parfois le poste imbriqué).
     */
    private static function roleOf(array $e): string
    {
        foreach (['position_name', 'position', 'workstation_name', 'workstation',
                  'stanowisko', 'role_name', 'role', 'job_title'] as $k) {
            if (!empty($e[$k])) {
                if (is_string($e[$k])) {
                    return trim($e[$k]);
                }
                if (is_array($e[$k]) && !empty($e[$k]['name']) && is_string($e[$k]['name'])) {
                    return trim($e[$k]['name']);
                }
            }
        }

        return '';
    }

    /** L'identifiant, en chaîne : l'API rend tantôt 12, tantôt « 12 ». */
    private static function idOf(array $row, array $fiche): ?string
    {
        foreach (['employee_id', 'franchisee_employee_id', 'id_employee',
                  'id_franchisee_employee', 'user_id'] as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return (string)$row[$k];
            }
        }
        // La fiche imbriquée porte son id sous « id » ; la ligne de planning,
        // elle, garde « id » pour le service lui-même — on ne le lit donc que
        // sur la fiche.
        if ($fiche !== $row && isset($fiche['id']) && $fiche['id'] !== '') {
            return (string)$fiche['id'];
        }

        return null;
    }

    /** Le nom affichable, ou une chaîne vide si la ligne n'en porte aucun. */
    private static function nameOf(array $e): string
    {
        foreach (['name', 'full_name', 'employee_name', 'display_name', 'label'] as $k) {
            if (!empty($e[$k]) && is_string($e[$k])) {
                return trim($e[$k]);
            }
        }

        $prenom = '';
        $nom    = '';
        foreach (['first_name', 'firstname', 'prenom', 'given_name'] as $k) {
            if (!empty($e[$k]) && is_string($e[$k])) { $prenom = trim($e[$k]); break; }
        }
        foreach (['last_name', 'lastname', 'nom', 'family_name', 'surname'] as $k) {
            if (!empty($e[$k]) && is_string($e[$k])) { $nom = trim($e[$k]); break; }
        }

        return trim($prenom . ' ' . $nom);
    }

    /**
     * Qui proposer, et sous quelle réserve.
     *
     * @param array<int, array{on_schedule: ?bool}>|null $employees
     * @return array{list: array<int, array>, mode: string, missing: ?string}
     *         mode = scheduled — le planning désigne ces personnes
     *              | empty     — planning servi, personne de service ce jour-là
     *              | missing    — une route n'a pas répondu ; `missing` la nomme
     *              | none       — aucun employé actif
     */
    public function roster(?array $employees): array
    {
        if ($this->missing !== null) {
            // La route ne répond pas : on ne propose personne et on dit
            // laquelle créer. Proposer toute l'équipe ferait passer un trou
            // pour un fonctionnement normal.
            return ['list' => [], 'mode' => 'missing', 'missing' => $this->missing];
        }

        if ($employees === null || $employees === []) {
            return ['list' => [], 'mode' => 'none', 'missing' => null];
        }

        $onDuty = array_values(array_filter($employees, fn(array $e) => $e['on_schedule'] === true));

        // Planning servi et vide : ce n'est pas une panne, c'est une réponse.
        // Personne n'est de service ce jour-là, et l'écran le dit.
        return [
            'list'    => $onDuty,
            'mode'    => $onDuty === [] ? 'empty' : 'scheduled',
            'missing' => null,
        ];
    }

    /**
     * Le personnel de service, liste seule.
     *
     * Conservé pour la cuisson, qui affiche une équipe sans avoir à expliquer
     * pourquoi. L'écran des checklists, lui, doit dire sous quelle réserve il
     * propose sa liste : il passe par roster().
     *
     * @param array<int, array{on_schedule: ?bool}>|null $employees
     * @return array<int, array>
     */
    public function onDuty(?array $employees): array
    {
        return $this->roster($employees)['list'];
    }

    /** Le planning a-t-il répondu ? Sert aux écrans qui n'affichent pas la raison. */
    public function scheduleServed(): bool
    {
        return $this->missing === null;
    }

    /**
     * Écarte les employés désactivés.
     *
     * Prudence volontaire : on n'écarte que ce qui est EXPLICITEMENT inactif.
     * Une fiche sans indicateur reste dans la liste — sortir quelqu'un sur une
     * absence d'information ferait disparaître l'équipe entière le jour où le
     * champ change de nom.
     *
     * @param array<int, array<string, mixed>> $employees
     * @return array<int, array<string, mixed>>
     */
    public static function activeOnly(array $employees): array
    {
        return array_values(array_filter($employees, static function (array $e): bool {
            foreach (['is_active', 'active', 'enabled'] as $k) {
                if (array_key_exists($k, $e) && $e[$k] !== null && $e[$k] !== '') {
                    return filter_var($e[$k], FILTER_VALIDATE_BOOLEAN);
                }
            }
            foreach (['deleted_at', 'archived_at'] as $k) {
                if (!empty($e[$k])) {
                    return false;
                }
            }
            if (isset($e['status']) && is_string($e['status'])) {
                $s = strtoupper(trim($e['status']));
                if (in_array($s, ['INACTIVE', 'DISABLED', 'ARCHIVED', 'DELETED', 'LEFT'], true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    }

}
