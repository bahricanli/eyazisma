<?php

namespace BahriCanli\EYazisma\Enums;

enum Ivedilik: string
{
    case Normal = 'NRM';
    case Acele = 'ACL';
    /** Yalnız 2.0 öncesi paketlerde; 2.0'da yerini "Acele" aldı. */
    case Ivedi = 'IVD';
    /** Yalnız 2.0 öncesi paketlerde. */
    case CokIvedi = 'CIV';
    case Gunlu = 'GNL';
}
