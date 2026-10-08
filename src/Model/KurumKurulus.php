<?php

namespace BahriCanli\EYazisma\Model;

/**
 * DETSİS'te kayıtlı kamu kurumu; KKK kurumun Türkiye Cumhuriyeti Devlet Teşkilatı Numarasıdır.
 */
final class KurumKurulus implements Taraf
{
    public function __construct(
        public readonly string $kkk,
        public readonly ?string $adi = null,
        public readonly ?IletisimBilgisi $iletisimBilgisi = null,
        public readonly ?string $birimKkk = null,
    ) {
    }

    public function gorunenAd(): string
    {
        return $this->adi ?? $this->kkk;
    }

    public function kimlik(): ?string
    {
        return $this->kkk;
    }
}
