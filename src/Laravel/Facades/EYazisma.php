<?php

namespace BahriCanli\EYazisma\Laravel\Facades;

use BahriCanli\EYazisma\Laravel\EYazismaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BahriCanli\EYazisma\PaketOlusturucu yeni()
 * @method static \BahriCanli\EYazisma\Paket ac(string $yol)
 * @method static \BahriCanli\EYazisma\Paket icerikten(string $icerik)
 * @method static \BahriCanli\EYazisma\Model\Taraf|null olusturan()
 *
 * @see EYazismaManager
 */
class EYazisma extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EYazismaManager::class;
    }
}
