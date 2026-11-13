<?php

namespace BahriCanli\EYazisma\Contracts;

/**
 * Sertifika denetimi ve zaman damgası için gereken en küçük HTTP istemcisi. Uygulama kendi
 * istemcisini (örneğin Laravel'in Http istemcisini) bağlayabilir; hata durumunda
 * EYazismaException fırlatılmalıdır.
 */
interface HttpIstemci
{
    public function getir(string $adres): string;

    /**
     * @param  array<string, string>  $basliklar
     */
    public function gonder(string $adres, string $govde, array $basliklar = []): string;
}
