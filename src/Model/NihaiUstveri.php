<?php

namespace BahriCanli\EYazisma\Model;

use DateTimeImmutable;

/**
 * "Nihai Üstveri" bileşeni: belgenin ancak son imzayla belli olan bilgileri.
 */
final class NihaiUstveri
{
    /**
     * @param  string  $belgeNo  belgenin sayısı, resmî yazışma mevzuatındaki biçimde
     * @param  list<Imza>  $imzalar  en az bir imza bilgisi gerekir
     */
    public function __construct(
        public readonly DateTimeImmutable $tarih,
        public readonly string $belgeNo,
        public readonly array $imzalar,
    ) {
    }
}
