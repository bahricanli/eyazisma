<?php

namespace BahriCanli\EYazisma\Enums;

enum Seviye: string
{
    /** Rehberin bir kuralı çiğnenmiş; paket geçerli değil. */
    case Hata = 'hata';
    /** Paketi geçersiz kılmayan ama dikkat edilmesi gereken durum. */
    case Uyari = 'uyari';
}
