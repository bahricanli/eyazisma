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
