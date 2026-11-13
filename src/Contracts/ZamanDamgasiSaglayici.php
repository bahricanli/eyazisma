<?php

namespace BahriCanli\EYazisma\Contracts;

/**
 * Verilen özete zaman damgası alır ve RFC 3161 TimeStampToken'ı (DER ContentInfo) döndürür.
 */
interface ZamanDamgasiSaglayici
{
    /**
     * @param  string  $ozet  ham (ikili) özet değeri
     * @param  string  $algoritma  "sha256", "sha384" ya da "sha512"
     */
    public function damga(string $ozet, string $algoritma = 'sha256'): string;
}
