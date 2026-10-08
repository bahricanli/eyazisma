<?php

namespace BahriCanli\EYazisma\Contracts;

/**
 * Verilen içeriği imzalayıp CAdES tümleşik (içeriği kendi içinde taşıyan) imzayı döndürür.
 *
 * Elektronik imza için "Paket Özeti", elektronik mühür için "Nihai Özet" bileşeni imzalanır.
 * Rehber imzada P4 (CAdES-X Long), mühürde P4 (CAdES-A) profilini şart koşar; profilin
 * sağlanması uygulayanın sorumluluğundadır, kütüphane imza üretmez.
 */
interface Imzalayici
{
    public function imzala(string $icerik): string;
}
