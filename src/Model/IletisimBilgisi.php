<?php

namespace BahriCanli\EYazisma\Model;

final class IletisimBilgisi
{
    public function __construct(
        public readonly ?string $telefon = null,
        public readonly ?string $telefonDiger = null,
        public readonly ?string $ePosta = null,
        public readonly ?string $kepAdresi = null,
        public readonly ?string $faks = null,
        public readonly ?string $webAdresi = null,
        public readonly ?string $adres = null,
        public readonly ?string $il = null,
        public readonly ?string $ilce = null,
        public readonly ?string $ulke = null,
    ) {
    }
}
