<?php

namespace BahriCanli\EYazisma\Model;

use DateTimeImmutable;

/**
 * Belgenin ilgi tuttuğu yazı ("İlgi: a) ...").
 */
final class Ilgi
{
    /**
     * @param  string  $etiket  ilginin harfi (a, b, ...)
     * @param  string|null  $ekId  ilgi yazı aynı zamanda pakete eklenmişse o ekin Id değeri
     */
    public function __construct(
        public readonly string $id,
        public readonly string $etiket,
        public readonly ?string $belgeNo = null,
        public readonly ?DateTimeImmutable $tarih = null,
        public readonly ?string $ad = null,
        public readonly ?string $aciklama = null,
        public readonly ?string $ekId = null,
        public readonly ?Tanimlayici $ozId = null,
        public readonly ?bool $eyazismaIdMi = null,
    ) {
    }
}
