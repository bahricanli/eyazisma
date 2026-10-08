<?php

namespace BahriCanli\EYazisma\Model;

final class GercekSahis implements Taraf
{
    public function __construct(
        public readonly Kisi $kisi,
        public readonly ?string $tckn = null,
        public readonly ?string $gorev = null,
        public readonly ?IletisimBilgisi $iletisimBilgisi = null,
    ) {
    }

    public function gorunenAd(): string
    {
        return $this->kisi->tamAd();
    }

    public function kimlik(): ?string
    {
        return $this->tckn;
    }
}
