<?php

namespace BahriCanli\EYazisma\Enums;

/**
 * Paketin uyduğu e-Yazışma Teknik Rehberi kuşağı.
 */
enum Surum: string
{
    /** 2.0 öncesi (1.0–1.3): tarih ve sayı üstveride, tek özet, mühür isteğe bağlı. Yalnız okunur. */
    case V1 = '1.x';
    /** 2.0 ve 2.1: nihai üstveri, çift özet, zorunlu mühür. */
    case V2 = '2.x';
}
