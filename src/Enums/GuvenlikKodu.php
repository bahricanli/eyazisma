<?php

namespace BahriCanli\EYazisma\Enums;

enum GuvenlikKodu: string
{
    case Yok = 'YOK';
    case HizmeteOzel = 'HZO';
    /** Rehber 2.1 ile kaldırıldı; şemada ve eski paketlerde hâlâ bulunur. */
    case Ozel = 'OZL';
    case Gizli = 'GZL';
    case CokGizli = 'CGZ';
}
