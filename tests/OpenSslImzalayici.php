<?php

namespace BahriCanli\EYazisma\Tests;

use BahriCanli\EYazisma\Contracts\Imzalayici;
use RuntimeException;

/**
 * Kendinden imzalı sertifikayla gerçek bir CMS tümleşik imza üretir. Nitelikli imza değildir;
 * yalnız paketin gerçek bir imza bloğuyla nasıl davrandığını sınamak içindir.
 */
final class OpenSslImzalayici implements Imzalayici
{
    private mixed $anahtar;

    private mixed $sertifika;

    public function __construct()
    {
        $this->anahtar = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $istek = openssl_csr_new(['commonName' => 'e-Yazışma Test'], $this->anahtar, ['digest_alg' => 'sha256']);
        $this->sertifika = openssl_csr_sign($istek, null, $this->anahtar, 1, ['digest_alg' => 'sha256']);
    }

    public function imzala(string $icerik): string
    {
        $girdi = tempnam(sys_get_temp_dir(), 'eyp');
        $cikti = tempnam(sys_get_temp_dir(), 'eyp');

        try {
            file_put_contents($girdi, $icerik);

            if (! openssl_cms_sign($girdi, $cikti, $this->sertifika, $this->anahtar, [], OPENSSL_CMS_BINARY | OPENSSL_CMS_NOATTR, OPENSSL_ENCODING_DER)) {
                throw new RuntimeException('CMS imzası üretilemedi: '.openssl_error_string());
            }

            return file_get_contents($cikti);
        } finally {
            @unlink($girdi);
            @unlink($cikti);
        }
    }
}
