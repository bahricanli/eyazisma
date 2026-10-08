<?php

namespace BahriCanli\EYazisma\Model;

use DateTimeImmutable;

/**
 * Belgenin üzerindeki bir imzaya ilişkin bilgi (imzanın kendisi değil).
 */
final class Imza
{
    public function __construct(
        public readonly GercekSahis $imzalayan,
        public readonly ?string $makam = null,
        public readonly ?string $amac = null,
        public readonly ?string $aciklama = null,
        public readonly ?DateTimeImmutable $tarih = null,
        public readonly ?GercekSahis $yetkiDevreden = null,
        public readonly ?GercekSahis $vekaletVeren = null,
    ) {
    }
}
