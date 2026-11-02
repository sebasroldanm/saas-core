<?php

namespace App\Support;

use DateTimeZone;

/**
 * Zonas horarias IANA para los selectores: agrupadas por región y con el offset actual en la
 * etiqueta ("(GMT-05:00) America/Bogota"). Se guarda siempre el nombre IANA, nunca el offset:
 * el nombre aplica el horario de verano solo.
 */
class Timezones
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return once(function (): array {
            $groups = [];

            foreach (DateTimeZone::listIdentifiers() as $identifier) {
                $region = str_contains($identifier, '/') ? strstr($identifier, '/', true) : 'Otros';
                $groups[$region][$identifier] = static::label($identifier);
            }

            ksort($groups);

            return $groups;
        });
    }

    public static function label(string $identifier): string
    {
        $offset = (new DateTimeZone($identifier))->getOffset(now()->toDateTimeImmutable());
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60), str_replace('_', ' ', $identifier));
    }

    public static function isValid(?string $identifier): bool
    {
        return $identifier !== null && in_array($identifier, DateTimeZone::listIdentifiers(), true);
    }
}
