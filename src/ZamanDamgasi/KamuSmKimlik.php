<?php

namespace BahriCanli\EYazisma\ZamanDamgasi;

use BahriCanli\EYazisma\Cades\Der;
use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * Kamu SM zaman damgası sunucusunun istediği "identity" başlığı:
 *
 *     ESYAReqEx ::= SEQUENCE {
 *         userid                  INTEGER,
 *         salt                    OCTET STRING,
 *         iterationCount          INTEGER,
 *         iv                      OCTET STRING,
 *         encryptedMessageImprint OCTET STRING }
 *
 * Anahtar parolayla PBKDF2-HMAC-SHA256 (100 tur, 32 bayt) üretilir; damgalanacak özet
 * AES-256-CBC ile şifrelenir. Yapı DER kodlanıp onaltılık yazılır.
 */
final class KamuSmKimlik
{
    public const TUR = 100;

    public static function baslik(?string $musteriNo, string $parola, string $ozet): string
    {
        if ($musteriNo === null || ! ctype_digit($musteriNo)) {
            throw new EYazismaException('Kamu SM zaman damgasında kullanıcı, müşteri numarasıdır ve yalnız rakamdan oluşur.');
        }

        $tuz = random_bytes(16);
        $iv = random_bytes(16);
        $anahtar = hash_pbkdf2('sha256', $parola, $tuz, self::TUR, 32, true);
        $sifreli = openssl_encrypt($ozet, 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv)
            ?: throw new EYazismaException('Kamu SM kimlik bilgisi şifrelenemedi.');

        return bin2hex(Der::kodla(0x30,
            Der::tamsayi(self::sayidanBaytlar($musteriNo))
            .Der::kodla(0x04, $tuz)
            .Der::tamsayi(chr(self::TUR))
            .Der::kodla(0x04, $iv)
            .Der::kodla(0x04, $sifreli),
        ));
    }

    private static function sayidanBaytlar(string $sayi): string
    {
        $hex = function_exists('gmp_init') ? gmp_strval(gmp_init($sayi, 10), 16) : dechex((int) $sayi);

        return hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
    }
}
