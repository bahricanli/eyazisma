<?php

namespace BahriCanli\EYazisma\Model;

/**
 * Belgeyi oluşturan, dağıtımda yer alan ya da belgeyle ilgili taraf:
 * kamu kurumu (KurumKurulus), gerçek kişi (GercekSahis) veya tüzel kişi (TuzelSahis).
 */
interface Taraf
{
    /** Listelerde ve "Core" bileşeninin creator alanında gösterilecek ad. */
    public function gorunenAd(): string;

    /** Tarafı tanımlayan kod: KKK, T.C. kimlik no ya da tüzel kişi kimliği. */
    public function kimlik(): ?string;
}
