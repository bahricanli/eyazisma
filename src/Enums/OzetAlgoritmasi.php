<?php

namespace BahriCanli\EYazisma\Enums;

enum OzetAlgoritmasi: string
{
    /** Yalnız 2.0 öncesi paketleri doğrulamak için; 2.0 ile kaldırıldı. */
    case Sha1 = 'http://www.w3.org/2000/09/xmldsig#sha1';
    case Sha256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    case Sha384 = 'http://www.w3.org/2001/04/xmldsig-more#sha384';
    case Sha512 = 'http://www.w3.org/2001/04/xmlenc#sha512';

    public function phpAdi(): string
    {
        return match ($this) {
            self::Sha1 => 'sha1',
            self::Sha256 => 'sha256',
            self::Sha384 => 'sha384',
            self::Sha512 => 'sha512',
        };
    }

    /** Verinin özeti, paket bileşenlerinde yazıldığı gibi base64 olarak. */
    public function ozetle(string $veri): string
    {
        return base64_encode(hash($this->phpAdi(), $veri, true));
    }
}
