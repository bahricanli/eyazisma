<?php

namespace BahriCanli\EYazisma\Model;

use DateTimeImmutable;

/**
 * "Core" bileşeni: paketin kendisine ilişkin üstveri.
 */
final class PaketOzellikleri
{
    public const KATEGORI_RESMI_YAZISMA = 'RESMIYAZISMA';

    public const KATEGORI_SIFRELI = 'RESMIYAZISMA/SIFRELI';

    public const KATEGORI_GUNCELLEME = 'RESMIYAZISMA/GUNCELLEME';

    public const ICERIK_TURU = 'application/eyazisma';

    /**
     * @param  string|null  $surum  paketin uyduğu e-Yazışma Teknik Rehberi sürümü
     * @param  string|null  $revizyon  paketi üreten uygulama
     */
    public function __construct(
        public readonly ?string $tanimlayici = null,
        public readonly ?string $konu = null,
        public readonly ?string $olusturan = null,
        public readonly ?DateTimeImmutable $olusturulma = null,
        public readonly ?string $kategori = null,
        public readonly ?string $icerikTuru = null,
        public readonly ?string $surum = null,
        public readonly ?string $revizyon = null,
    ) {
    }
}
