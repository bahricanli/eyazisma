<?php

namespace BahriCanli\EYazisma\Cades;

use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * CMS yapısını gezmeye ve yeniden kurmaya yetecek kadar DER: yalnız tek baytlık etiket ve
 * belirli uzunluk. Belirsiz uzunluklu (BER) kodlama reddedilir.
 *
 * @internal
 */
final class Der
{
    /**
     * Verilen içerikteki ardışık öğeleri ham kodlamalarıyla döndürür.
     *
     * @return list<array{etiket: int, ham: string, icerik: string}>
     */
    public static function ogeler(string $veri): array
    {
        $ogeler = [];
        $konum = 0;

        while ($konum < strlen($veri)) {
            $ogeler[] = self::oge($veri, $konum);
        }

        return $ogeler;
    }

    /**
     * @return array{etiket: int, ham: string, icerik: string}
     */
    public static function tek(string $veri): array
    {
        $konum = 0;
        $oge = self::oge($veri, $konum);

        if ($konum !== strlen($veri)) {
            throw new EYazismaException('İmza bloğunun sonunda fazladan veri var.');
        }

        return $oge;
    }

    public static function kodla(int $etiket, string $icerik): string
    {
        $uzunluk = strlen($icerik);

        if ($uzunluk < 0x80) {
            return chr($etiket).chr($uzunluk).$icerik;
        }

        $baytlar = ltrim(pack('J', $uzunluk), "\0");

        return chr($etiket).chr(0x80 | strlen($baytlar)).$baytlar.$icerik;
    }

    /**
     * Noktalı gösterimdeki nesne kimliğini (OID) kodlar.
     */
    public static function oid(string $oid): string
    {
        $parcalar = array_map('intval', explode('.', $oid));
        $icerik = chr($parcalar[0] * 40 + $parcalar[1]);

        foreach (array_slice($parcalar, 2) as $parca) {
            $baytlar = chr($parca & 0x7F);

            while ($parca >>= 7) {
                $baytlar = chr(0x80 | ($parca & 0x7F)).$baytlar;
            }

            $icerik .= $baytlar;
        }

        return self::kodla(0x06, $icerik);
    }

    /**
     * Kodlanmış OID'i noktalı gösterime çevirir.
     */
    public static function oidCoz(string $ham): string
    {
        $icerik = self::tek($ham)['icerik'];
        $ilk = ord($icerik[0] ?? "\0");
        $parcalar = [intdiv(min($ilk, 80), 40), $ilk - 40 * intdiv(min($ilk, 80), 40)];
        $deger = 0;

        for ($i = 1; $i < strlen($icerik); $i++) {
            $deger = ($deger << 7) | (ord($icerik[$i]) & 0x7F);

            if ((ord($icerik[$i]) & 0x80) === 0) {
                $parcalar[] = $deger;
                $deger = 0;
            }
        }

        return implode('.', $parcalar);
    }

    /**
     * İşaretsiz büyük-uçlu baytları DER INTEGER olarak kodlar.
     */
    public static function tamsayi(string $baytlar): string
    {
        $baytlar = ltrim($baytlar, "\0");

        if ($baytlar === '' || ord($baytlar[0]) & 0x80) {
            $baytlar = "\0".$baytlar;
        }

        return self::kodla(0x02, $baytlar);
    }

    /**
     * UTCTime ya da GeneralizedTime öğesini okur.
     */
    public static function zaman(array $oge): \DateTimeImmutable
    {
        $metin = rtrim($oge['icerik'], 'Z');
        $metin = preg_replace('/\.\d+$/', '', $metin);
        $bicim = $oge['etiket'] === 0x17 ? 'ymdHis' : 'YmdHis';

        return \DateTimeImmutable::createFromFormat('!'.$bicim, $metin, new \DateTimeZone('UTC'))
            ?: throw new EYazismaException('Tarih alanı okunamadı.');
    }

    /**
     * DER "SET OF" öğelerini kodlamalarına göre sıralar.
     *
     * @param  list<string>  $hamlar
     */
    public static function kume(int $etiket, array $hamlar): string
    {
        $hamlar = array_values(array_unique($hamlar));
        sort($hamlar, SORT_STRING);

        return self::kodla($etiket, implode('', $hamlar));
    }

    /**
     * @return array{etiket: int, ham: string, icerik: string}
     */
    private static function oge(string $veri, int &$konum): array
    {
        $baslangic = $konum;
        $toplam = strlen($veri);

        if ($konum + 2 > $toplam) {
            throw new EYazismaException('İmza bloğu eksik: öğe başlığı okunamadı.');
        }

        $etiket = ord($veri[$konum++]);

        if (($etiket & 0x1F) === 0x1F) {
            throw new EYazismaException('İmza bloğunda desteklenmeyen çok baytlı etiket var.');
        }

        $uzunluk = ord($veri[$konum++]);

        if ($uzunluk === 0x80) {
            throw new EYazismaException('İmza bloğu belirsiz uzunluklu (BER) kodlanmış; DER bekleniyor.');
        }

        if ($uzunluk > 0x80) {
            $bayt = $uzunluk & 0x7F;

            if ($bayt > 4 || $konum + $bayt > $toplam) {
                throw new EYazismaException('İmza bloğunda geçersiz uzunluk var.');
            }

            $uzunluk = 0;
            for ($i = 0; $i < $bayt; $i++) {
                $uzunluk = ($uzunluk << 8) | ord($veri[$konum++]);
            }
        }

        if ($konum + $uzunluk > $toplam) {
            throw new EYazismaException('İmza bloğu eksik: öğe içeriği kesik.');
        }

        $icerik = substr($veri, $konum, $uzunluk);
        $konum += $uzunluk;

        return ['etiket' => $etiket, 'ham' => substr($veri, $baslangic, $konum - $baslangic), 'icerik' => $icerik];
    }
}
