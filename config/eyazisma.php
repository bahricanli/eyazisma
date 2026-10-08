<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Belgeyi oluşturan
    |--------------------------------------------------------------------------
    |
    | EYazisma::yeni() ile başlatılan paketlerin "Olusturan" bilgisi.
    | tur: "tuzel" (dernek, vakıf, şirket; kimlik MERSİS numarasıdır) ya da
    | "kurum" (kamu kurumu; kimlik DETSİS'teki kurum kimlik kodudur).
    |
    */

    'olusturan' => [
        'tur' => env('EYAZISMA_OLUSTURAN_TUR', 'tuzel'),
        'kimlik' => env('EYAZISMA_OLUSTURAN_KIMLIK'),
        'kimlik_semasi' => env('EYAZISMA_OLUSTURAN_KIMLIK_SEMASI', 'MERSIS'),
        'ad' => env('EYAZISMA_OLUSTURAN_AD'),
        'iletisim' => [
            'telefon' => env('EYAZISMA_OLUSTURAN_TELEFON'),
            'e_posta' => env('EYAZISMA_OLUSTURAN_EPOSTA'),
            'kep_adresi' => env('EYAZISMA_OLUSTURAN_KEP'),
            'web_adresi' => env('EYAZISMA_OLUSTURAN_WEB'),
            'adres' => env('EYAZISMA_OLUSTURAN_ADRES'),
            'il' => env('EYAZISMA_OLUSTURAN_IL'),
            'ilce' => env('EYAZISMA_OLUSTURAN_ILCE'),
            'ulke' => env('EYAZISMA_OLUSTURAN_ULKE', 'Türkiye'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Belge doğrulama adresi
    |--------------------------------------------------------------------------
    |
    | Üstverinin "DogrulamaBilgisi" alanına yazılır; belge, belge Id değeriyle
    | (belge doğrulama kodu) bu adresten doğrulanabilmelidir.
    |
    */

    'dogrulama_adresi' => env('EYAZISMA_DOGRULAMA_ADRESI'),

    /*
    |--------------------------------------------------------------------------
    | Belge dili
    |--------------------------------------------------------------------------
    |
    | ISO 639-3 dil kodu.
    |
    */

    'dil' => env('EYAZISMA_DIL', 'tur'),

    /*
    |--------------------------------------------------------------------------
    | Rehber sürümü
    |--------------------------------------------------------------------------
    |
    | Paketin "Core" bileşenine yazılan e-Yazışma Teknik Rehberi sürümü.
    |
    */

    'surum' => env('EYAZISMA_SURUM', '2.0'),

    /*
    |--------------------------------------------------------------------------
    | Özet algoritmaları
    |--------------------------------------------------------------------------
    |
    | Her bileşen için iki özet alınır; biri sha512 olmalıdır.
    | Geçerli değerler: sha256, sha384, sha512.
    |
    */

    'ozet_algoritmalari' => ['sha256', 'sha512'],

];
