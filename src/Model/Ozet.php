<?php

namespace BahriCanli\EYazisma\Model;

use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;

/**
 * Bir içeriğin özet (hash) değeri.
 */
final class Ozet
{
    /**
     * @param  string  $algoritma  algoritmanın URI'si
     * @param  string  $deger  base64 kodlanmış özet
     */
    public function __construct(
        public readonly string $algoritma,
        public readonly string $deger,
    ) {
    }

    public static function hesapla(string $icerik, OzetAlgoritmasi $algoritma = OzetAlgoritmasi::Sha256): self
    {
        return new self($algoritma->value, $algoritma->ozetle($icerik));
    }
}
