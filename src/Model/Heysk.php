<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Hizmet Envanteri Yönetim Sistemi kodu.
 */
final class Heysk
{
    public function __construct(
        public readonly int $kod,
        public readonly string $ad,
        public readonly ?string $tanim = null,
    ) {
    }
}
