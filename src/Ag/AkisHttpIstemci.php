<?php

namespace BahriCanli\EYazisma\Ag;

use BahriCanli\EYazisma\Contracts\HttpIstemci;
use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * PHP akışlarıyla çalışan varsayılan istemci; ek eklenti gerektirmez.
 */
final class AkisHttpIstemci implements HttpIstemci
{
    public function __construct(private readonly int $zamanAsimi = 15)
    {
    }

    public function getir(string $adres): string
    {
        return $this->istek($adres, ['method' => 'GET']);
    }

    public function gonder(string $adres, string $govde, array $basliklar = []): string
    {
        $satirlar = [];

        foreach ($basliklar as $ad => $deger) {
            $satirlar[] = $ad.': '.$deger;
        }

        return $this->istek($adres, ['method' => 'POST', 'header' => implode("\r\n", $satirlar), 'content' => $govde]);
    }

    /**
     * @param  array<string, mixed>  $secenekler
     */
    private function istek(string $adres, array $secenekler): string
    {
        if (! preg_match('#^https?://#i', $adres)) {
            throw new EYazismaException("Desteklenmeyen adres: $adres");
        }

        $baglam = stream_context_create(['http' => $secenekler + [
            'timeout' => $this->zamanAsimi,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ]]);

        $govde = @file_get_contents($adres, false, $baglam);
        $durum = 0;

        foreach (function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []) as $satir) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $satir, $eslesme)) {
                $durum = (int) $eslesme[1];
            }
        }

        if ($govde === false || $durum >= 400 || $durum === 0) {
            throw new EYazismaException("$adres yanıt vermedi".($durum ? " (HTTP $durum)" : '').'.');
        }

        return $govde;
    }
}
