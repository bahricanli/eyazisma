<?php

namespace BahriCanli\EYazisma\Exceptions;

/**
 * Geçerli olabilecek ama bu kütüphanenin işlemediği paket: şifreli (.eyps),
 * güncelleme (.eypg) ya da 2.x öncesi sürüm.
 */
class DesteklenmeyenPaketException extends EYazismaException
{
}
