<?php

namespace App\Kitchen\app\Services\Checklist;

/**
 * Quelle checklist ouvrir quand personne n'a rien demandé.
 *
 * ── Pourquoi ──
 * L'écran s'ouvrait sur « — toutes — » : un sélecteur de date, un bouton, un
 * bandeau, puis trois tuiles à comparer. Le premier bouton « Exécuter » tombait
 * à un millier de pixels, sous la ligne de flottaison d'une tablette. Or l'écran
 * n'a qu'un usage : ouvrir la checklist du moment et enchaîner ses tâches.
 *
 * On connaît l'heure planifiée de chacune. À 6 h 10, « Ouverture du magasin »
 * est la seule réponse plausible ; la demander est une question dont on a déjà
 * la réponse. On la présélectionne donc, et le choix reste offert juste
 * au-dessus, replié.
 *
 * ── Ce que la règle refuse de faire ──
 * Elle ne présélectionne jamais une checklist terminée : proposer d'ouvrir un
 * travail fini est pire que ne rien proposer, car il faut alors comprendre
 * pourquoi tout est vert avant de chercher ailleurs. Quand tout est fait, elle
 * répond null et l'écran retombe sur « toutes » — la vue d'ensemble, qui est
 * alors la bonne.
 *
 * Elle ne s'applique pas non plus à une date passée : « maintenant » n'y veut
 * rien dire, et on y vient pour relire, pas pour exécuter. C'est l'appelant qui
 * en décide, pas cette classe — elle ne connaît pas le calendrier.
 *
 * Aucune requête, aucune horloge : l'instant lui est passé. C'est ce qui la
 * rend vérifiable sans navigateur — bin/checklist-test.php.
 */
class ChecklistFocusService
{
    /**
     * @param array $checklists  Telles que servies par l'API : id, name,
     *                           execution_time (« HH:MM:SS » ou null),
     *                           tasks_done, tasks_total.
     * @param string $now        Heure courante, « HH:MM ».
     *
     * @return int|null L'identifiant à ouvrir, ou null s'il n'y a rien à ouvrir.
     */
    public function pick(array $checklists, string $now): ?int
    {
        $pending = [];
        foreach ($checklists as $c) {
            if (!is_array($c) || (int)($c['id'] ?? 0) <= 0) {
                continue;
            }
            $total = (int)($c['tasks_total'] ?? 0);
            $done  = (int)($c['tasks_done'] ?? 0);

            // Une checklist sans tâche n'est pas « terminée », elle est vide :
            // l'ouvrir montrerait un écran blanc.
            if ($total <= 0 || $done >= $total) {
                continue;
            }
            $pending[] = $c;
        }

        if ($pending === []) {
            return null;
        }

        $nowM = $this->minutes($now);

        // Celle qui est en cours : la dernière dont l'heure est passée. La plus
        // ancienne en retard serait un autre choix défendable, mais elle ferait
        // remonter l'oubli de la veille au-dessus du service en cours.
        $current = null;
        $best    = -1;
        // Et à défaut, la prochaine à venir : avant l'ouverture, c'est elle
        // qu'on prépare.
        $next     = null;
        $bestNext = PHP_INT_MAX;

        foreach ($pending as $c) {
            $t = $this->minutes((string)($c['execution_time'] ?? ''));
            if ($t === null) {
                continue;
            }
            if ($nowM !== null && $t <= $nowM && $t > $best) {
                $best    = $t;
                $current = (int)$c['id'];
            }
            if ($nowM !== null && $t > $nowM && $t < $bestNext) {
                $bestNext = $t;
                $next     = (int)$c['id'];
            }
        }

        // Sans heure nulle part, on ne devine pas : la première non terminée.
        return $current ?? $next ?? (int)$pending[0]['id'];
    }

    /** « 06:00 » ou « 06:00:00 » → 360. Null si ce n'est pas une heure. */
    private function minutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }
    /**
     * La prochaine tâche à faire, pour la carte du tableau de bord.
     *
     * ── La règle ──
     * La première tâche encore ouverte, dans l'ordre des heures prévues. Une
     * tâche sans heure passe après celles qui en ont une : elle n'est pas
     * moins due, mais elle n'a pas de rendez-vous — et la carte annonce un
     * rendez-vous. `late` dit si l'heure est passée : l'écran le montre en
     * rubis, parce qu'un retard sur une tâche d'hygiène ne se rattrape pas en
     * silence.
     *
     * Même contrat que pick() : aucune requête, aucune horloge — l'instant est
     * passé en argument, et la règle se vérifie sans navigateur.
     *
     * @param array  $tasks Telles que servies par la progression : name,
     *                      execution_time (« HH:MM:SS » ou null), status.
     * @param string $now   Heure courante, « HH:MM ».
     *
     * @return array{name: string, time: ?string, late: bool}|null
     *         null = plus rien à faire, ou rien d'affichable.
     */
    public function nextPending(array $tasks, string $now): ?array
    {
        $best = null;
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            // DONE, SKIPPED et FAILED sont réglées ; tout le reste attend.
            if (in_array(strtoupper((string)($t['status'] ?? '')), ['DONE', 'SKIPPED', 'FAILED'], true)) {
                continue;
            }
            $name = trim((string)($t['name'] ?? ''));
            if ($name === '') {
                // Une tâche sans nom ne s'annonce pas : « prochaine : ? » ne
                // dit rien, et elle reste comptée dans le total.
                continue;
            }
            $time = null;
            if (!empty($t['execution_time']) && is_string($t['execution_time'])) {
                $time = substr($t['execution_time'], 0, 5);
            }

            // Ordre : les tâches datées d'abord, par heure ; les sans-heure
            // ensuite, dans l'ordre servi.
            $key = $time ?? '99:99';
            if ($best === null || $key < $best['key']) {
                $best = ['key' => $key, 'name' => $name, 'time' => $time];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'name' => $best['name'],
            'time' => $best['time'],
            'late' => $best['time'] !== null && $best['time'] < $now,
        ];
    }
}
