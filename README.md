# bahricanli/eyazisma

PHP ile **e-Yazışma Paketi** (`.eyp`) oluşturma, okuma ve doğrulama: güncel 2.x ve hâlâ dolaşımda olan 2.0 öncesi (1.x) paketler. Çekirdek saf PHP'dir; Laravel için servis sağlayıcı ve facade ile gelir.

Paket yapısı Cumhurbaşkanlığı'nın yayımladığı [e-Yazışma Teknik Rehberi](https://www.tccb.gov.tr/resmiyazisma/eyp/dokumanlar/) sürüm 2.0 / 2.1'e göre üretilir.

## Kapsam

| Yapar | Yapmaz |
|---|---|
| Şifresiz paket üretir: üst yazı, üstveri, ekler, `Core`, özetler; 2.x ya da 1.x yapısında | Elektronik imza ya da mühür **üretmez**; dışarıda atılan CAdES imzayı pakete ekler |
| Paketi okur: üstveri, nihai üstveri, üst yazı, ek dosyaları, imza ve mühür | İmzayı kriptografik olarak **doğrulamaz** (sertifika zinciri, iptal, zaman damgası, CAdES profili) |
| Paketi rehberin kurallar listesine göre denetler, özet değerlerini yeniden hesaplar | Şifreli paket (`.eyps`) ve güncelleme paketini (`.eypg`) işlemez |
| Paketi imza adımları arasında kaydedip sürdürür | Paraf özeti ve paraf imzası üretmez (okuduğu pakette varsa özet denetimlerinde hesaba katar) |

Rehbere göre geçerli bir 2.x paket için **nitelikli elektronik imza** (P4, CAdES-X Long) ve kurumun **elektronik mührü** (P4, CAdES-A) gerekir. 1.x pakette mühür isteğe bağlıdır. İmza da mühür de bu kütüphanenin dışında sağlanır.

## Kurulum

```bash
composer require bahricanli/eyazisma
```

PHP 8.2+, `ext-dom`, `ext-libxml`, `ext-zlib` gerekir. `ext-zip` gerekmez.

## Kullanım

Paket üç adımda tamamlanır; her adımın çıktısı bir sonrakinin imzalanacak girdisidir.

### 1. Paketi oluştur

```php
use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Paket;

$paket = Paket::yeni()
    ->konu('Şenlik daveti')
    ->olusturan(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği'))
    ->dagitim(new KurumKurulus('24301050', 'Adalet Bakanlığı'))
    ->dagitim(new KurumKurulus('24322010', 'Sağlık Bakanlığı'), DagitimTuru::Bilgi)
    ->dogrulamaAdresi('https://ornek.org.tr/belge-dogrula')
    ->ustYazi(Dosya::yoldan('yazi.pdf'))
    ->ek(Dosya::yoldan('rapor.pdf'), ad: 'Çalışma raporu')
    ->fizikselEk('İki adet CD')
    ->olustur();

$paket->kaydet($dizin.'/'.$paket->dosyaAdi());   // [BelgeId].eyp
```

- Belge Id verilmezse üretilir; aynı zamanda belge doğrulama kodudur.
- Oluşturan ve dağıtımdaki taraflar `KurumKurulus` (DETSİS kodlu kamu kurumu), `TuzelSahis` (dernek, şirket; MERSİS no) ya da `GercekSahis` olabilir.
- Üst yazı rehbere göre PDF/A olmalıdır; kütüphane dosyayı dönüştürmez.
- `ek(..., imzasiz: true)` eki paket özetine sokmadan ekler; `hariciEk()` paket dışındaki bir adresi gösterir.

### 2. İmzala

```php
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\NihaiUstveri;

$paket = Paket::ac($yol);

$imzalanacak = $paket->paketOzeti();          // bu içerik e-imzayla imzalanır
$cades = /* CAdES tümleşik imza (DER) */;

$paket->imzaEkle($cades, new NihaiUstveri(
    tarih: new DateTimeImmutable,
    belgeNo: '2026/41',
    imzalar: [new Imza(new GercekSahis(new Kisi('Ayşe', 'Yılmaz'), gorev: 'Yönetim Kurulu Başkanı'), amac: 'Onay')],
))->kaydet($yol);
```

İmza **tümleşik** olmalıdır: imza bloğu paket özetini kendi içinde taşır. Belgenin tarihi ve sayısı EYP 2.0'dan beri nihai üstveridedir, bu yüzden imzayla birlikte verilir.

### 3. Mühürle

```php
$paket = Paket::ac($yol);

$muhurlenecek = $paket->nihaiOzet();          // bu içerik e-mühürle imzalanır
$paket->muhurEkle(/* CAdES tümleşik mühür */)->kaydet($yol);

$paket->asama();                              // PaketAsamasi::Tamamlandi
```

İmza ve mührü kodla atabiliyorsanız `Imzalayici` sözleşmesini uygulayın:

```php
use BahriCanli\EYazisma\Contracts\Imzalayici;

$paket->imzala($imzalayici, $nihaiUstveri)->muhurle($muhurleyici);
```

### 2.0 öncesi (1.x) paket

2.0 öncesi paketler hâlâ gönderilip alınıyor. `surum('1.3')` o yapıda paket üretir: tarih ve sayı imzalanan üstverinin parçasıdır ve baştan verilir, bileşen başına tek özet alınır, paket imzayla tamamlanır, mühür gerekmez.

```php
$paket = Paket::yeni()
    ->surum('1.3')
    ->belge(new DateTimeImmutable('2026-10-08'), '06-061-115-2026-22')
    ->konu('Şenlik daveti')
    ->olusturan(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği'))
    ->dagitim(new KurumKurulus('24301050', 'Adalet Bakanlığı'))
    ->ustYazi(Dosya::yoldan('yazi.pdf'))
    ->olustur();

$paket->imzaEkle($cades, new NihaiUstveri($tarih, '06-061-115-2026-22', [$imza]));   // imza bilgileri "Belge İmza" bileşenine yazılır
$paket->asama();                                                                       // PaketAsamasi::Tamamlandi
```

2.0 ile gelen alanlar (doğrulama adresi, dosya planı, KEP adresi, birim kodu) 1.x pakete yazılmaz; "Yok" güvenlik kodu ve "Acele" ivedilik eski karşılıklarıyla (TSD, IVD) yazılır.

### Okuma

```php
$paket = Paket::ac('gelen.eyp');

$paket->ustveri()->konu;
$paket->ustveri()->dagitimlar;
$paket->nihaiUstveri()?->belgeNo;
$paket->ustYazi()->icerik;

foreach ($paket->ustveri()->ekler as $ek) {
    $dosya = $paket->ekDosyasi($ek->id);      // dahili elektronik dosya değilse null
}
```

Okuma iki kuşakta da aynıdır: `$paket->surum()` kuşağı (`Surum::V1` / `Surum::V2`) verir, `nihaiUstveri()` 1.x'te tarih ve sayıyı üstveriden, imza bilgilerini "Belge İmza" bileşeninden derler, `hedefler()` "Belge Hedef" bileşenindeki alıcıları döndürür.

Şifreli ve güncelleme paketlerinde `DesteklenmeyenPaketException`, paket olmayan dosyada `GecersizPaketException` fırlatılır.

### Doğrulama

```php
$rapor = $paket->dogrula();

$rapor->gecerli();                            // hata yoksa true
foreach ($rapor->hatalar() as $bulgu) {
    echo $bulgu;                              // "[K.33] ..."
}
```

1.x paketlerde o kuşağın kuralları uygulanır: tek özet yeter (SHA-1 dahil), nihai özet ve mühür zorunlu değildir.

Denetlenenler: zorunlu bileşenler ve konumları, paket ilişkileri, üstveri ile ek dosyalarının tutarlılığı, `Core` ile üstverinin uyumu, Id biçimleri, paket özeti ve nihai özetteki her özet değerinin bileşen içeriğiyle eşleşmesi, imza ve mührün varlığı. İmza için yalnızca imzalanan bileşenin imza bloğunun içinde geçip geçmediğine bakılır.

## Laravel

Servis sağlayıcı kendiliğinden keşfedilir. Yapılandırmayı yayımlamak için:

```bash
php artisan vendor:publish --tag=eyazisma-config
```

```dotenv
EYAZISMA_OLUSTURAN_TUR=tuzel
EYAZISMA_OLUSTURAN_KIMLIK=0123456789012345
EYAZISMA_OLUSTURAN_AD="Örnek Derneği"
EYAZISMA_DOGRULAMA_ADRESI=https://ornek.org.tr/belge-dogrula
```

`EYazisma::yeni()` oluşturan, doğrulama adresi, dil, sürüm ve özet algoritmaları yapılandırmadan doldurulmuş bir paket oluşturucu döndürür:

```php
use BahriCanli\EYazisma\Laravel\Facades\EYazisma;

$paket = EYazisma::yeni()->konu('...')->dagitim(...)->ustYazi(...)->olustur();
$gelen = EYazisma::ac($yol);
```

## Bilinen sınırlar

- Paket bütünüyle bellekte işlenir; ZIP64 desteklenmez (4 GB sınırı). Okumada açılmış toplam boyut varsayılan olarak 256 MB ile sınırlıdır (`Paket::ac($yol, $boyutSiniri)`).
- `Core` bileşenine sürüm olarak `2.0` yazılır; `->surum('2.1')` ile değiştirilebilir.
- 1.x üretiminde 1.3'e özgü `TCYK` alanı ve 1.x mührü üretimi sınanmadı; 1.x okuma, eski İmzager'ın ürettiği gerçek paketlerle denendi.
- Resmî XSD şemalarıyla çalışma anında doğrulama yapılmaz: şemalar libxml ile yüklenemiyor ve resmî API'nin ürettiği paketler de şemadan birebir geçmiyor. Üretilen XML geliştirme sırasında şemalara karşı denetlenmiştir.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Kaynaklar

- [e-Yazışma Teknik Rehberi ve yardımcı dokümanlar](https://www.tccb.gov.tr/resmiyazisma/eyp/dokumanlar/)
- [Resmî Java ve .NET API'leri](https://www.tccb.gov.tr/resmiyazisma/eyp/ciktilar/)
- [Kamu SM e-Yazışma Paketi sayfası](https://yazilim.kamusm.gov.tr/eit-wiki/doku.php?id=e-yazisma_paketi)

## Lisans

MIT
