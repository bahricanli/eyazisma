<?php

namespace BahriCanli\EYazisma\Denetim;

use BahriCanli\EYazisma\Cades\Sertifika;
use BahriCanli\EYazisma\ZamanDamgasi\DamgaBilgisi;

/**
 * İmza bloğundaki bir imzacının denetimi: sertifikası, (varsa) imza zaman damgası ve
 * sertifikanın imza anındaki durumu. İmza anı, damga varsa damganın zamanıdır.
 */
final class ImzaciDenetimi
{
    /**
     * @param  list<string>  $hatalar
     */
    public function __construct(
        public readonly Sertifika $sertifika,
        public readonly SertifikaDenetimi $sertifikaDenetimi,
        public readonly ?DamgaBilgisi $damga,
        public readonly ?SertifikaDenetimi $damgaSunucusu,
        public readonly array $hatalar = [],
    ) {
    }

    public function damgaGecerli(): bool
    {
        return $this->damga !== null && $this->hatalar === [] && ($this->damgaSunucusu?->zincirGecerli ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function dizi(): array
    {
        return [
            'ad_soyad' => $this->sertifika->adSoyad,
            'tckn' => $this->sertifika->tckn(),
            'yayinci' => $this->sertifika->yayinci,
            'seri_no' => $this->sertifika->seriNoHex(),
            'gecerlilik_bitisi' => $this->sertifika->gecerlilikBitisi->format(DATE_ATOM),
            'gecerli' => $this->sertifikaDenetimi->gecerli(),
            'ozet' => $this->sertifikaDenetimi->ozet(),
            'denetim' => $this->sertifikaDenetimi->dizi(),
            'zaman_damgasi' => $this->damga === null ? null : [
                'zaman' => $this->damga->zaman->format(DATE_ATOM),
                'sunucu' => $this->damga->sunucu?->adSoyad,
                'gecerli' => $this->damgaGecerli(),
            ],
            'hatalar' => $this->hatalar,
        ];
    }
}
