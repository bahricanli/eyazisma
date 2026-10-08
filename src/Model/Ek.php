<?php

namespace BahriCanli\EYazisma\Model;

use BahriCanli\EYazisma\Enums\EkTuru;

/**
 * Belgenin eki. Dahili elektronik dosyanın içeriği paketin içindedir (Paket::ekDosyasi()).
 */
final class Ek
{
    /**
     * @param  bool|null  $eyazismaIdMi  ek daha önce e-Yazışma için üretilmiş bir nesneyse true (K.63)
     * @param  bool|null  $imzaliMi  ekin özeti paket özetinde yer alıyor mu
     * @param  Ozet|null  $ozet  harici referansın gösterdiği dosyanın özeti
     */
    public function __construct(
        public readonly string $id,
        public readonly EkTuru $tur,
        public readonly int $siraNo,
        public readonly ?string $ad = null,
        public readonly ?string $aciklama = null,
        public readonly ?string $belgeNo = null,
        public readonly ?string $dosyaAdi = null,
        public readonly ?string $mimeTuru = null,
        public readonly ?string $referans = null,
        public readonly ?Tanimlayici $ozId = null,
        public readonly ?bool $imzaliMi = null,
        public readonly ?Ozet $ozet = null,
        public readonly ?bool $eyazismaIdMi = null,
    ) {
    }
}
