<?php

namespace BahriCanli\EYazisma\Cades;

use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * Tümleşik CAdES imza bloklarıyla yapılan iki iş: bir imzanın verilen içeriği gerçekten
 * imzaladığını görmek ve aynı içeriğe atılmış imzaları tek bloğa toplamak.
 *
 * Rehbere göre pakette tek "Elektronik İmza" bileşeni bulunur; birden çok imzacı aynı CAdES
 * bloğunun içinde yer alır. Her imzacı ayrı imza atıp sonra bloklar birleştirilirse sonuç,
 * imzacıların aynı içeriğe paralel attığı imzadır.
 *
 * Doğrulama yalnız imzanın matematiğine ve imzalanan içeriğe bakar: sertifika zinciri, iptal
 * durumu, zaman damgası ve CAdES profili denetlenmez. ext-openssl gerekir.
 */
final class Cades
{
    private const SIGNED_DATA = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02";

    /**
     * İmzanın beklenen içeriği imzaladığını doğrular ve imzacıların sertifikalarını döndürür.
     *
     * @return list<Sertifika>
     */
    public static function imzacilar(string $imza, string $beklenenIcerik): array
    {
        self::yapisi($imza);

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

            if (file_get_contents($icerik) !== $beklenenIcerik) {
                throw new EYazismaException('İmza beklenen içeriği imzalamamış.');
            }

            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($sertifikalar), $eslesmeler);

            return array_map(fn (string $pem) => Sertifika::pemden($pem), $eslesmeler[0]);
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
        $surum = max(array_map(fn (array $parca) => $parca['surum'], $parcalar));

        $imzaliVeri = Der::kodla(0x02, chr($surum))
            .Der::kume(0x31, $topla('ozetAlgoritmalari'))
            .$ilk['icerik']
            .(($sertifikalar = $topla('sertifikalar')) ? Der::kume(0xA0, $sertifikalar) : '')
            .(($iptaller = $topla('iptaller')) ? Der::kume(0xA1, $iptaller) : '')
            .Der::kume(0x31, $topla('imzacilar'));

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
