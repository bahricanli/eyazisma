<?php

namespace BahriCanli\EYazisma\Enums;

enum PaketAsamasi: string
{
    /** Paket özeti hazır, elektronik imza bekleniyor. */
    case ImzaBekliyor = 'imza-bekliyor';
    /** İmzalı, nihai özet hazır, elektronik mühür bekleniyor. */
    case MuhurBekliyor = 'muhur-bekliyor';
    case Tamamlandi = 'tamamlandi';
}
