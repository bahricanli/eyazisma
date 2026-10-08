<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Özet bileşenlerindeki (Paket Özeti, Nihai Özet, Paraf Özeti) bir "Reference" satırı.
 */
final class OzetReferansi
{
    public const DAHILI = 'http://eyazisma.dpt/bilesen#dahili';

    public const HARICI = 'http://eyazisma.dpt/bilesen#harici';

    /**
     * @param  string  $uri  paket içindeki bileşenin adı ya da harici nesnenin tanımlayıcısı
     * @param  list<Ozet>  $ozetler  rehber iki özet ister, biri SHA-512 olmalıdır
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $ozetler,
        public readonly string $tur = self::DAHILI,
    ) {
    }

    public function dahiliMi(): bool
    {
        return $this->tur !== self::HARICI;
    }
}
