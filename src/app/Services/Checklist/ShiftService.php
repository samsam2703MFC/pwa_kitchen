<?php

namespace App\Kitchen\app\Services\Checklist;

/**
 * La prise de poste : on se déclare une fois, on enchaîne toute la checklist.
 *
 * ── Pourquoi ──
 * Une checklist d'ouverture compte cinq à quinze tâches. Redemander le nom et
 * quatre chiffres à chacune, c'est cinquante à cent cinquante frappes pour un
 * travail qu'une personne fait d'affilée, seule, en vingt minutes. Le code
 * cessait d'être une signature pour devenir un péage.
 *
 * On garde la signature, on enlève le péage : le PIN est vérifié UNE FOIS, à
 * la prise de poste. Les tâches suivantes s'enchaînent, et chacune reste
 * attribuée nominativement — l'écran continue d'écrire « ✓ Nathan · 06:12 ».
 *
 * ── Ce que ce n'est pas ──
 * Ce n'est pas une connexion : la tablette reste connectée sous son compte
 * partagé. C'est une déclaration de qui tient le poste, et elle ne donne accès
 * à rien de plus — elle ne fait qu'éviter de retaper le même code.
 *
 * ── Deux bornes, et chacune répond à un risque ──
 * Le glissement (30 min) protège de la tablette qu'on pose et qu'on oublie :
 * quelqu'un d'autre ne peut pas signer sous un nom laissé ouvert une heure
 * plus tôt. Le plafond (8 h) protège du cas où la tablette sert en continu :
 * sans lui, un poste ouvert le matin signerait encore le soir.
 *
 * Cette classe ne connaît ni cookie, ni requête, ni horloge : l'instant lui est
 * passé. C'est ce qui la rend vérifiable sans navigateur — bin/shift-test.php.
 */
class ShiftService
{
    /** Sans geste pendant ce délai, le poste se referme. */
    public const SLIDING_S = 1800;      // 30 min

    /** Durée maximale d'un poste, quoi qu'il arrive. */
    public const MAX_S = 8 * 3600;      // 8 h

    /**
     * Signe la prise de poste.
     *
     * `o` est l'ouverture — elle ne bouge jamais, c'est elle qui plafonne.
     * `t` est le dernier geste — elle est repoussée à chaque tâche validée,
     * c'est elle qui glisse. Les confondre donnerait soit un poste qui se
     * referme en pleine checklist, soit un poste éternel.
     */
    public function sign(int $employeeId, string $name, int $openedAt, int $touchedAt, string $secret, ?int $shopId = null, ?string $workDate = null): string
    {
        $claims = [
            'id' => $employeeId,
            'n'  => $name,
            'o'  => $openedAt,
            't'  => $touchedAt,
        ];
        if ($shopId !== null && $shopId > 0) {
            $claims['s'] = $shopId;
        }
        if ($workDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $claims['d'] = $workDate;
        }
        $payload = json_encode($claims, JSON_UNESCAPED_UNICODE);

        $body = $this->b64($payload);

        return $body . '.' . $this->b64(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Relit un jeton de poste, ou null s'il est absent, forgé ou périmé.
     *
     * Les trois cas rendent null volontairement : côté écran, « personne n'est
     * en poste » est la seule réponse utile. Distinguer « forgé » de « périmé »
     * n'apprendrait rien à l'équipe et renseignerait qui essaie.
     *
     * @return array{id:int,name:string,opened_at:int,touched_at:int,shop_id?:int,work_date?:string}|null
     */
    public function verify(?string $token, int $now, string $secret): ?array
    {
        $token = (string) $token;
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }

        [$body, $sig] = explode('.', $token, 2);

        // hash_equals : la comparaison de signatures se fait à temps constant,
        // sinon la durée de la réponse renseigne sur les octets déjà justes.
        if (!hash_equals($this->b64(hash_hmac('sha256', $body, $secret, true)), $sig)) {
            return null;
        }

        $d = json_decode($this->unb64($body), true);
        if (!is_array($d) || !isset($d['id'], $d['o'], $d['t'])) {
            return null;
        }

        $id = (int) $d['id'];
        $o  = (int) $d['o'];
        $t  = (int) $d['t'];

        if ($id <= 0) {
            return null;
        }
        // Un jeton daté du futur est un jeton dont l'horloge a été touchée :
        // on ne lui fait pas crédit.
        if ($o > $now || $t > $now) {
            return null;
        }
        if ($now - $t > self::SLIDING_S || $now - $o > self::MAX_S) {
            return null;
        }

        $claims = [
            'id'         => $id,
            'name'       => (string) ($d['n'] ?? ''),
            'opened_at'  => $o,
            'touched_at' => $t,
        ];
        if (isset($d['s']) && (int)$d['s'] > 0) {
            $claims['shop_id'] = (int)$d['s'];
        }
        if (isset($d['d']) && is_string($d['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['d'])) {
            $claims['work_date'] = $d['d'];
        }

        return $claims;
    }

    /**
     * Secondes restantes avant fermeture — la plus proche des deux bornes.
     * Sert à l'écran, qui l'écrit : un poste qui se referme sans prévenir
     * passerait pour une panne.
     */
    public function remaining(array $claims, int $now): int
    {
        $bySliding = self::SLIDING_S - ($now - (int) $claims['touched_at']);
        $byMax     = self::MAX_S - ($now - (int) $claims['opened_at']);

        return max(0, min($bySliding, $byMax));
    }

    /** Initiales pour la pastille, sans dépendre de l'API. */
    public function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = mb_substr($parts[0] ?? '', 0, 1);
        $b = mb_substr($parts[1] ?? '', 0, 1);

        return mb_strtoupper($a . $b) ?: '—';
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
