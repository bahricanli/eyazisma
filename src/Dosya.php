<?php

namespace BahriCanli\EYazisma;

use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * Pakete konan ya da paketten okunan dosya: üst yazı veya dahili ek.
 */
final class Dosya
{
    private const MIME_TURLERI = [
        'pdf' => 'application/pdf',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'zip' => 'application/zip',
        'mp4' => 'video/mp4',
        'eyp' => 'application/eyazisma',
    ];

    public function __construct(
        public readonly string $icerik,
        public readonly string $ad,
        public readonly string $mimeTuru,
    ) {
    }

    public static function icerikten(string $icerik, string $ad, ?string $mimeTuru = null): self
    {
        return new self($icerik, $ad, $mimeTuru ?? self::mimeTuruTahmini($ad));
    }

    public static function yoldan(string $yol, ?string $ad = null, ?string $mimeTuru = null): self
    {
        $icerik = @file_get_contents($yol);

        if ($icerik === false) {
            throw new EYazismaException("Dosya okunamadı: {$yol}");
        }

        return self::icerikten($icerik, $ad ?? basename($yol), $mimeTuru);
    }

    public static function mimeTuruTahmini(string $ad): string
    {
        return self::MIME_TURLERI[strtolower(pathinfo($ad, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Paket içinde kullanılabilecek dosya adı: boşluksuz ve OPC "Part URI" sözdizimine uygun.
     */
    public function paketAdi(): string
    {
        $ad = strtr($this->ad, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
        ]);
        $ad = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $ad), '._');

        return $ad === '' ? 'dosya' : $ad;
    }
}
