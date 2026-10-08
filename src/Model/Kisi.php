<?php

namespace BahriCanli\EYazisma\Model;

final class Kisi
{
    public function __construct(
        public readonly string $ilkAdi,
        public readonly string $soyadi,
        public readonly ?string $ikinciAdi = null,
        public readonly ?string $unvan = null,
        public readonly ?string $onEk = null,
    ) {
    }

    public function tamAd(): string
    {
        return implode(' ', array_filter([$this->ilkAdi, $this->ikinciAdi, $this->soyadi], fn ($parca) => $parca !== null && $parca !== ''));
    }
}
