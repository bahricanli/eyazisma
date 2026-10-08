<?php

namespace BahriCanli\EYazisma;

final class Guid
{
    private const DESEN = '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/';

    /**
     * Rastgele (v4) GUID; rehber paketteki bütün Id değerlerinin büyük harfle yazılmasını ister (K.80).
     */
    public static function uret(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    public static function gecerliMi(string $deger): bool
    {
        return preg_match(self::DESEN, $deger) === 1;
    }
}
