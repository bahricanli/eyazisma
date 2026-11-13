<?php

namespace BahriCanli\EYazisma\ZamanDamgasi;

use BahriCanli\EYazisma\Cades\Cades;
use BahriCanli\EYazisma\Cades\Der;
use BahriCanli\EYazisma\Cades\Sertifika;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use DateTimeImmutable;

/**
 * Zaman damgasının (RFC 3161 TimeStampToken) okunmuş hâli. Damganın imzası doğrulanmıştır;
 * damga sunucusu sertifikasının zinciri SertifikaDenetleyici ile ayrıca denetlenir.
 */
final class DamgaBilgisi
{
    private const TST_INFO = '1.2.840.113549.1.9.16.1.4';

    /**
     * @param  list<string>  $sertifikalar  damga bloğundaki sertifikalar (PEM)
     */
    public function __construct(
        public readonly DateTimeImmutable $zaman,
        public readonly string $algoritma,
        public readonly string $ozet,
        public readonly string $seriNo,
        public readonly ?string $nonce,
        public readonly ?Sertifika $sunucu,
        public readonly array $sertifikalar,
    ) {
    }

    public static function coz(string $damga): self
    {
        ['icerik' => $tstInfo, 'sertifikalar' => $sertifikalar] = Cades::ac($damga);

        if (! str_contains($damga, Der::oid(self::TST_INFO))) {
            throw new EYazismaException('Verilen blok bir zaman damgası değil.');
        }

        $alanlar = Der::ogeler(Der::tek($tstInfo)['icerik']);

        if (count($alanlar) < 5) {
            throw new EYazismaException('Zaman damgası bilgisi eksik.');
        }

        $iz = Der::ogeler($alanlar[2]['icerik']);
        $algoritmaOid = Der::oidCoz(Der::ogeler($iz[0]['icerik'])[0]['ham']);
        $nonce = null;

        foreach (array_slice($alanlar, 5) as $alan) {
            if ($alan['etiket'] === 0x02) {
                $nonce = $alan['icerik'];
            }
        }

        $sunucu = $sertifikalar === [] ? null : Sertifika::pemden(self::imzalayan($sertifikalar));

        return new self(
            zaman: Der::zaman($alanlar[4]),
            algoritma: array_search($algoritmaOid, Rfc3161::ALGORITMALAR, true) ?: $algoritmaOid,
            ozet: $iz[1]['icerik'],
            seriNo: bin2hex($alanlar[3]['icerik']),
            nonce: $nonce,
            sunucu: $sunucu,
            sertifikalar: $sertifikalar,
        );
    }

    /**
     * Bloktaki sertifikalardan, başka bir sertifikayı yayımlamamış olanı (yaprak) seçer.
     *
     * @param  list<string>  $sertifikalar
     */
    private static function imzalayan(array $sertifikalar): string
    {
        foreach ($sertifikalar as $pem) {
            $konu = openssl_x509_parse($pem)['subject'] ?? [];
            $yayinladiklari = array_filter($sertifikalar, fn (string $diger) => $diger !== $pem && (openssl_x509_parse($diger)['issuer'] ?? null) === $konu);

            if ($yayinladiklari === []) {
                return $pem;
            }
        }

        return $sertifikalar[0];
    }
}
