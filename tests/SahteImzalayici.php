<?php

namespace BahriCanli\EYazisma\Tests;

use BahriCanli\EYazisma\Contracts\Imzalayici;

/**
 * Gerçek imza atmaz; CAdES tümleşik imza gibi içeriği kendi içinde taşıyan bir blok döndürür.
 */
final class SahteImzalayici implements Imzalayici
{
    public function imzala(string $icerik): string
    {
        return "\x30\x82".$icerik."\x00imza";
    }
}
