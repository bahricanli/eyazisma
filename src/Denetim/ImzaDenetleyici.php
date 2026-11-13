<?php

namespace BahriCanli\EYazisma\Denetim;

use BahriCanli\EYazisma\Cades\Cades;
use BahriCanli\EYazisma\Cades\Sertifika;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\ZamanDamgasi\DamgaBilgisi;
use Throwable;

/**
 * Tümleşik CAdES imzasını baştan sona denetler: imzanın içeriği imzaladığını, her imzacının
 * zaman damgasını ve sertifikasının imza anındaki zincir ve iptal durumunu.
 */
final class ImzaDenetleyici
{
    public function __construct(private readonly SertifikaDenetleyici $sertifikaDenetleyici)
    {
    }

    /**
     * @return list<ImzaciDenetimi>
     *
     * @throws EYazismaException imza bozuksa ya da içeriği imzalamamışsa
     */
    public function denetle(string $imza, string $beklenenIcerik): array
    {
        Cades::imzacilar($imza, $beklenenIcerik);
        $araSertifikalar = Cades::sertifikalar($imza);
        $sonuclar = [];

        foreach (Cades::imzaciBilgileri($imza) as $bilgi) {
            if ($bilgi['sertifika'] === null) {
                continue;
            }

            $hatalar = [];
            $damga = null;
            $damgaSunucusu = null;

            if ($bilgi['damga'] !== null) {
                try {
                    $damga = DamgaBilgisi::coz($bilgi['damga']);

                    if (! hash_equals(hash($damga->algoritma, $bilgi['imzaDegeri'], true), $damga->ozet)) {
                        $hatalar[] = 'Zaman damgası bu imzaya ait değil.';
                    }

                    if ($damga->sunucu !== null) {
                        $damgaSunucusu = $this->sertifikaDenetleyici->denetle($damga->sunucu, [...$araSertifikalar, ...array_map(Sertifika::pemden(...), $damga->sertifikalar)], $damga->zaman);
                    }
                } catch (Throwable $hata) {
                    $hatalar[] = 'Zaman damgası okunamadı: '.$hata->getMessage();
                    $damga = null;
                }
            }

            $zaman = $damga !== null && $hatalar === [] ? $damga->zaman : null;

            $sonuclar[] = new ImzaciDenetimi(
                $bilgi['sertifika'],
                $this->sertifikaDenetleyici->denetle($bilgi['sertifika'], $araSertifikalar, $zaman),
                $damga,
                $damgaSunucusu,
                $hatalar,
            );
        }

        return $sonuclar;
    }
}
