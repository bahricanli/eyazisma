<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Şemasıyla birlikte verilen tanımlayıcı (ör. schemeID="MERSIS", schemeID="GUID").
 */
final class Tanimlayici
{
    public function __construct(
        public readonly string $deger,
        public readonly ?string $semaId = null,
    ) {
    }
}
