<?php

namespace App\Kitchen\core\Support;

use App\Kitchen\app\Services\Checklist\ShiftService;

/**
 * Persistance de la prise de poste.
 *
 * Les règles vivent dans ShiftService (pures, testées) ; ici on ne fait que
 * les brancher sur le cookie. Même partage que DeviceMode, et pour la même
 * raison : c'est ce qui permet de vérifier les bornes sans navigateur.
 *
 * Le cookie est signé avec le secret JWT de l'application et `HttpOnly` : le
 * JavaScript de la page ne peut ni le lire ni le fabriquer. Le forger demande
 * le secret du serveur — sans quoi n'importe qui signerait des tâches sous le
 * nom de n'importe qui, et la traçabilité ne vaudrait plus rien.
 */
final class ShiftSession
{
    public const COOKIE = 'kitchen_shift';

    private static ?ShiftService $rules = null;

    public static function rules(): ShiftService
    {
        return self::$rules ??= new ShiftService();
    }

    private static function secret(): string
    {
        // Le même secret que les jetons d'authentification : il est déjà le
        // secret du serveur, en ajouter un second ne ferait qu'un second
        // endroit d'où il peut fuir.
        return (string) (defined('JWT_SECRET_KEY') ? JWT_SECRET_KEY : '');
    }

    /** @return array{id:int,name:string,opened_at:int,touched_at:int,shop_id?:int,work_date?:string}|null */
    public static function current(?int $shopId = null, ?string $workDate = null): ?array
    {
        $claims = self::rules()->verify($_COOKIE[self::COOKIE] ?? null, time(), self::secret());
        if ($claims === null) {
            return null;
        }
        if ($shopId !== null && (($claims['shop_id'] ?? null) !== $shopId)) {
            return null;
        }
        if ($workDate !== null && (($claims['work_date'] ?? null) !== $workDate)) {
            return null;
        }

        return $claims;
    }

    /** Ouvre le poste. Le PIN a été vérifié par l'appelant, jamais ici. */
    public static function open(int $employeeId, string $name, int $shopId, string $workDate): void
    {
        $now = time();
        self::write(self::rules()->sign($employeeId, $name, $now, $now, self::secret(), $shopId, $workDate), $now + ShiftService::MAX_S);
    }

    /**
     * Repousse l'échéance glissante après une tâche validée.
     * L'ouverture, elle, ne bouge pas : c'est elle qui plafonne le poste.
     */
    public static function touch(array $claims): void
    {
        $now = time();
        self::write(
            self::rules()->sign((int) $claims['id'], (string) $claims['name'], (int) $claims['opened_at'], $now, self::secret(), (int)($claims['shop_id'] ?? 0), $claims['work_date'] ?? null),
            (int) $claims['opened_at'] + ShiftService::MAX_S
        );
    }

    public static function close(): void
    {
        self::write('', time() - 3600);
    }

    private static function write(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if ($value === '') {
            unset($_COOKIE[self::COOKIE]);
        } else {
            $_COOKIE[self::COOKIE] = $value;
        }
    }
}
