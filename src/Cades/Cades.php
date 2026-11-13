<?php

namespace BahriCanli\EYazisma\Cades;

use BahriCanli\EYazisma\Contracts\ZamanDamgasiSaglayici;
use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * Tümleşik CAdES imza bloklarıyla yapılan iki iş: bir imzanın verilen içeriği gerçekten
 * imzaladığını görmek ve aynı içeriğe atılmış imzaları tek bloğa toplamak.
 *
 * Rehbere göre pakette tek "Elektronik İmza" bileşeni bulunur; birden çok imzacı aynı CAdES
 * bloğunun içinde yer alır. Her imzacı ayrı imza atıp sonra bloklar birleştirilirse sonuç,
 * imzacıların aynı içeriğe paralel attığı imzadır.
 *
 * imzacilar() yalnız imzanın matematiğine ve imzalanan içeriğe bakar. Sertifika zinciri ve
 * iptal durumu SertifikaDenetleyici, zaman damgası zamanDamgasiEkle() ile ele alınır; CAdES-X
 * Long profili üretilmez. ext-openssl gerekir.
 */
final class Cades
{
    private const SIGNED_DATA = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02";

    /** id-aa-signatureTimeStampToken */
    public const ZAMAN_DAMGASI = '1.2.840.113549.1.9.16.2.14';

    /**
     * İmzanın beklenen içeriği imzaladığını doğrular ve imzacıların sertifikalarını döndürür.
     *
     * @return list<Sertifika>
     */
    public static function imzacilar(string $imza, string $beklenenIcerik): array
    {
        self::yapisi($imza);
        ['icerik' => $icerik, 'sertifikalar' => $sertifikalar] = self::ac($imza);

        if ($icerik !== $beklenenIcerik) {
            throw new EYazismaException('İmza beklenen içeriği imzalamamış.');
        }

        return array_map(fn (string $pem) => Sertifika::pemden($pem), $sertifikalar);
    }

    /**
     * CMS SignedData yapısının imzalarını (zincire bakmadan) doğrular; imzalanan içeriği ve
     * bloktaki sertifikaları PEM olarak döndürür.
     *
     * @return array{icerik: string, sertifikalar: list<string>}
     *
     * @internal
     */
    public static function ac(string $imza): array
    {
        $dizin = sys_get_temp_dir();
        $girdi = tempnam($dizin, 'cades');
        $sertifikalar = tempnam($dizin, 'cades');
        $icerik = tempnam($dizin, 'cades');

        try {
            file_put_contents($girdi, $imza);
            $gecerli = openssl_cms_verify(
                $girdi,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY,
                $sertifikalar,
                [],
                null,
                $icerik,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );

            while (openssl_error_string() !== false);

            if ($gecerli !== true) {
                throw new EYazismaException('İmza doğrulanamadı: imza bozuk ya da içerikle uyuşmuyor.');
            }

            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($sertifikalar), $eslesmeler);

            return ['icerik' => (string) file_get_contents($icerik), 'sertifikalar' => $eslesmeler[0]];
        } finally {
            @unlink($girdi);
            @unlink($sertifikalar);
            @unlink($icerik);
        }
    }

    /**
     * Aynı içeriğe atılmış tümleşik imzaları tek imza bloğunda toplar. İmzacı bilgileri,
     * sertifikalar ve iptal listeleri birleşir; imzaların kendisine dokunulmaz.
     */
    public static function birlestir(string $imza, string ...$digerleri): string
    {
        if ($digerleri === []) {
            return $imza;
        }

        $parcalar = array_map(self::yapisi(...), [$imza, ...$digerleri]);
        $ilk = $parcalar[0];

        foreach ($parcalar as $parca) {
            if ($parca['icerik'] !== $ilk['icerik']) {
                throw new EYazismaException('Birleştirilen imzalar aynı içeriği taşımıyor.');
            }
        }

        $topla = fn (string $alan) => array_merge(...array_column($parcalar, $alan));

        return self::kur([
            'surum' => max(array_column($parcalar, 'surum')),
            'ozetAlgoritmalari' => $topla('ozetAlgoritmalari'),
            'icerik' => $ilk['icerik'],
            'sertifikalar' => $topla('sertifikalar'),
            'iptaller' => $topla('iptaller'),
            'imzacilar' => $topla('imzacilar'),
        ]);
    }

    /**
     * Zaman damgası olmayan her imzacının imza değerine zaman damgası alır ve imzacının
     * imzasız özniteliklerine ekler (CAdES-T). Damgası olan imzacıya dokunulmaz.
     */
    public static function zamanDamgasiEkle(string $imza, ZamanDamgasiSaglayici $saglayici, string $algoritma = 'sha256'): string
    {
        $parca = self::yapisi($imza);

        $parca['imzacilar'] = array_map(function (string $imzaci) use ($saglayici, $algoritma) {
            $alanlar = Der::ogeler(Der::tek($imzaci)['icerik']);

            if (self::imzacininDamgasi($alanlar) !== null) {
                return $imzaci;
            }

            $sonAlan = end($alanlar);
            $imzasizlar = $sonAlan['etiket'] === 0xA1 ? array_column(Der::ogeler(array_pop($alanlar)['icerik']), 'ham') : [];
            $imzaDegeri = end($alanlar)['icerik'];

            $damga = $saglayici->damga(hash($algoritma, $imzaDegeri, true), $algoritma);
            $imzasizlar[] = Der::kodla(0x30, Der::oid(self::ZAMAN_DAMGASI).Der::kodla(0x31, $damga));

            return Der::kodla(0x30, implode('', array_column($alanlar, 'ham')).Der::kume(0xA1, $imzasizlar));
        }, $parca['imzacilar']);

        return self::kur($parca);
    }

    /**
     * Her imzacının sertifikası ve (varsa) imza zaman damgası; sıra imza bloğundaki sıradır.
     *
     * @return list<array{sertifika: ?Sertifika, damga: ?string, imzaDegeri: string}>
     */
    public static function imzaciBilgileri(string $imza): array
    {
        $parca = self::yapisi($imza);
        $sertifikalar = self::sertifikalar($imza);

        return array_map(function (string $imzaci) use ($sertifikalar) {
            $alanlar = Der::ogeler(Der::tek($imzaci)['icerik']);
            $kimlik = $alanlar[1];
            $sertifika = null;

            if ($kimlik['etiket'] === 0x30) {
                $seri = Der::ogeler($kimlik['icerik'])[1]['icerik'];

                foreach ($sertifikalar as $aday) {
                    if (ltrim($aday->seriBaytlari(), "\0") === ltrim($seri, "\0")) {
                        $sertifika = $aday;
                    }
                }
            } elseif ($kimlik['etiket'] === 0x80) {
                foreach ($sertifikalar as $aday) {
                    if ($aday->anahtarKimligi() === $kimlik['icerik']) {
                        $sertifika = $aday;
                    }
                }
            }

            $imzaDegeri = $alanlar[count($alanlar) - (end($alanlar)['etiket'] === 0xA1 ? 2 : 1)]['icerik'];

            return ['sertifika' => $sertifika, 'damga' => self::imzacininDamgasi($alanlar), 'imzaDegeri' => $imzaDegeri];
        }, $parca['imzacilar']);
    }

    /**
     * İmza bloğundaki tüm sertifikalar (imzacılar ve ara sertifikalar).
     *
     * @return list<Sertifika>
     */
    public static function sertifikalar(string $imza): array
    {
        return array_map(fn (string $der) => Sertifika::pemden(self::pem($der)), array_values(array_filter(self::yapisi($imza)['sertifikalar'], fn (string $der) => $der[0] === "\x30")));
    }

    public static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END CERTIFICATE-----'."\n";
    }

    /**
     * @param  list<array{etiket: int, ham: string, icerik: string}>  $alanlar
     */
    private static function imzacininDamgasi(array $alanlar): ?string
    {
        $son = end($alanlar);

        if ($son['etiket'] !== 0xA1) {
            return null;
        }

        $oid = Der::oid(self::ZAMAN_DAMGASI);

        foreach (Der::ogeler($son['icerik']) as $oznitelik) {
            $parcalar = Der::ogeler($oznitelik['icerik']);

            if (($parcalar[0]['ham'] ?? null) === $oid) {
                return Der::ogeler($parcalar[1]['icerik'])[0]['ham'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array{surum: int, ozetAlgoritmalari: list<string>, icerik: string, sertifikalar: list<string>, iptaller: list<string>, imzacilar: list<string>}  $parca
     */
    private static function kur(array $parca): string
    {
        $imzaliVeri = Der::kodla(0x02, chr($parca['surum']))
            .Der::kume(0x31, $parca['ozetAlgoritmalari'])
            .$parca['icerik']
            .($parca['sertifikalar'] ? Der::kume(0xA0, $parca['sertifikalar']) : '')
            .($parca['iptaller'] ? Der::kume(0xA1, $parca['iptaller']) : '')
            .Der::kume(0x31, $parca['imzacilar']);

        return Der::kodla(0x30, self::SIGNED_DATA.Der::kodla(0xA0, Der::kodla(0x30, $imzaliVeri)));
    }

    /**
     * @return array{surum: int, ozetAlgoritmalari: list<string>, icerik: string, sertifikalar: list<string>, iptaller: list<string>, imzacilar: list<string>}
     */
    private static function yapisi(string $imza): array
    {
        $bilgi = Der::tek($imza);
        $ogeler = $bilgi['etiket'] === 0x30 ? Der::ogeler($bilgi['icerik']) : [];

        if (count($ogeler) !== 2 || $ogeler[0]['ham'] !== self::SIGNED_DATA || $ogeler[1]['etiket'] !== 0xA0) {
            throw new EYazismaException('İmza bir CMS SignedData yapısı değil.');
        }

        $imzaliVeri = Der::tek($ogeler[1]['icerik']);
        $alanlar = $imzaliVeri['etiket'] === 0x30 ? Der::ogeler($imzaliVeri['icerik']) : [];
        $sonuc = ['sertifikalar' => [], 'iptaller' => []];

        if (count($alanlar) < 4 || $alanlar[0]['etiket'] !== 0x02 || $alanlar[1]['etiket'] !== 0x31 || $alanlar[2]['etiket'] !== 0x30) {
            throw new EYazismaException('İmza bloğunun SignedData alanları beklenen sırada değil.');
        }

        $icerikBilgisi = Der::ogeler($alanlar[2]['icerik']);

        if (count($icerikBilgisi) < 2) {
            throw new EYazismaException('İmza tümleşik değil: imzalanan içerik imza bloğunda yok.');
        }

        foreach (array_slice($alanlar, 3, -1) as $alan) {
            match ($alan['etiket']) {
                0xA0 => $sonuc['sertifikalar'] = array_column(Der::ogeler($alan['icerik']), 'ham'),
                0xA1 => $sonuc['iptaller'] = array_column(Der::ogeler($alan['icerik']), 'ham'),
                default => throw new EYazismaException('İmza bloğunda tanınmayan alan var.'),
            };
        }

        $imzacilar = end($alanlar);

        if ($imzacilar['etiket'] !== 0x31) {
            throw new EYazismaException('İmza bloğunda imzacı bilgisi yok.');
        }

        return [
            'surum' => ord(substr($alanlar[0]['icerik'], -1)),
            'ozetAlgoritmalari' => array_column(Der::ogeler($alanlar[1]['icerik']), 'ham'),
            'icerik' => $alanlar[2]['ham'],
            ...$sonuc,
            'imzacilar' => array_column(Der::ogeler($imzacilar['icerik']), 'ham'),
        ];
    }
}
