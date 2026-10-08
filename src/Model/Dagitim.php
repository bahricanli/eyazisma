<?php

namespace BahriCanli\EYazisma\Model;

use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\Ivedilik;

final class Dagitim
{
    /**
     * @param  string|null  $miat  ISO 8601 süre (ör. "P3D")
     * @param  list<string>  $konulmamisEkIdleri  bu alıcıya gönderilmeyen eklerin Id değerleri
     */
    public function __construct(
        public readonly Taraf $taraf,
        public readonly DagitimTuru $dagitimTuru = DagitimTuru::Geregi,
        public readonly Ivedilik $ivedilik = Ivedilik::Normal,
        public readonly ?string $miat = null,
        public readonly array $konulmamisEkIdleri = [],
    ) {
    }
}
