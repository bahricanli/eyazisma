<?php

namespace BahriCanli\EYazisma\Enums;

enum EkTuru: string
{
    /** Dosyası paketin içinde bulunan ek. */
    case DahiliElektronikDosya = 'DED';
    /** Paket dışındaki bir adresi gösteren ek. */
    case HariciReferans = 'HRF';
    /** Elektronik olarak ifade edilemeyen ek (kitap, CD...). */
    case FizikselNesne = 'FZK';
}
