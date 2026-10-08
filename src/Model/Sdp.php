<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Standart dosya planı kodu.
 */
final class Sdp
{
    public function __construct(
        public readonly string $kod,
        public readonly string $ad,
        public readonly ?string $aciklama = null,
    ) {
    }
}
