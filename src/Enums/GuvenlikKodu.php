<?php

namespace BahriCanli\EYazisma\Enums;

enum GuvenlikKodu: string
{
    case Yok = 'YOK';
    /** Yalnız 2.0 öncesi paketlerde; 2.0'da yerini "Yok" aldı. */
    case TasnifDisi = 'TSD';
    /** Yalnız 2.0 öncesi paketlerde. */
    case KisiyeOzel = 'KSO';
    case HizmeteOzel = 'HZO';
    /** Rehber 2.1 ile kaldırıldı; şemada ve eski paketlerde hâlâ bulunur. */
    case Ozel = 'OZL';
    case Gizli = 'GZL';
    case CokGizli = 'CGZ';
}
