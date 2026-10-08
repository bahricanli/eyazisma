<?php

namespace BahriCanli\EYazisma\Dogrulama;

use BahriCanli\EYazisma\Enums\Seviye;

final class Bulgu
{
    /**
     * @param  string|null  $kural  e-Yazışma Teknik Rehberi kurallar listesindeki numara (ör. "K.33")
     */
    public function __construct(
        public readonly Seviye $seviye,
        public readonly string $mesaj,
        public readonly ?string $kural = null,
    ) {
    }

    public function __toString(): string
    {
        return ($this->kural === null ? '' : "[{$this->kural}] ").$this->mesaj;
    }
}
