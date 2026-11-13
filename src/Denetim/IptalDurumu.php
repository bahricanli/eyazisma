<?php

namespace BahriCanli\EYazisma\Denetim;

enum IptalDurumu: string
{
    case Gecerli = 'gecerli';
    case IptalEdildi = 'iptal';
    case Bilinmiyor = 'bilinmiyor';
}
