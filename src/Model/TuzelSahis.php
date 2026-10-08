<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Kamu kurumu olmayan tüzel kişi (dernek, vakıf, şirket...).
 */
final class TuzelSahis implements Taraf
{
    public function __construct(
        public readonly Tanimlayici $id,
        public readonly ?string $adi = null,
        public readonly ?IletisimBilgisi $iletisimBilgisi = null,
    ) {
    }

    /**
     * Rehber, Türkiye'de faaliyet gösteren tüzel kişiler için MERSİS numarasını ister.
     */
    public static function mersis(string $mersisNo, ?string $adi = null, ?IletisimBilgisi $iletisimBilgisi = null): self
    {
        return new self(new Tanimlayici($mersisNo, 'MERSIS'), $adi, $iletisimBilgisi);
    }

    public function gorunenAd(): string
    {
        return $this->adi ?? $this->id->deger;
    }

    public function kimlik(): ?string
    {
        return $this->id->deger;
    }
}
