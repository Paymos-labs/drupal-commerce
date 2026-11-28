# Drupal Commerce için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Drupal Commerce mağazanızda Paymos üzerinden ödeme kabul edin. `1.0.0` sürümü ilk kararlı sürümdür; [Drupal.org](https://www.drupal.org/project/commerce_paymos) ve [resmi GitHub deposu](https://github.com/Paymos-labs/drupal-commerce) üzerinden edinilebilir.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video anlatım (1:12, 6 dilde altyazı):** [YouTube'da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

Drupal `^10.3 || ^11` ve Drupal Commerce `^3` kullanın. Modül PHP `^8.1` gereksinimi beyan eder; kullandığınız Drupal sürümü daha yeni bir PHP sürümü isteyebilir, bu nedenle daha katı gereksinime uyun. Mağazanızın bağlantı ve ödeme bildirimleri için herkese açık bir HTTPS adresine sahip olması gerekir.

Modül GPL-2.0-or-later lisansını kullanır. PHP SDK bağımlılığı (`paymos/php-sdk`) MIT lisanslıdır ve ödeme adımında `Paymos\Plugin\InvoicePresentation::normalizeProducts` üzerinden `products[]` normalleştirmesi için `^1.12.0` (`1.12.0` veya uyumlu daha yeni bir `1.x` sürümü) gerektirir.

## Modülü kurma

Kararlı sürümü kurmak ve `paymos/php-sdk:^1.12.0` bağımlılığını çözmek için Composer kullanın:

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

İsterseniz ayrı olarak sunulan manuel ZIP paketini de kurabilirsiniz. Paketteki `commerce_paymos` dizinini sitenizin katkılı modüller (`contributed modules`) dizinine çıkarın. Manuel paket, `vendor/` içinde `paymos/php-sdk` `1.11.0` sürümünü barındırır (`Paymos\Client` zaten otomatik yüklenmemişse `SdkBootstrap` bunu yükler); bu yüzden paketle gelen dosyaları bir arada tutun. Kurulumdan sonra Drupal'da Paymos modülünü etkinleştirin.

Bir site manuel ZIP paketini başka bir Composer paketi tarafından global olarak zaten yüklenmiş daha eski bir `paymos/php-sdk` sürümüyle birlikte kullanıyorsa, `CheckoutService` `method_exists(InvoicePresentation::class, 'normalizeProducts')` kontrolünü yapar ve ödeme akışını bozmak yerine güvenli biçimde yalnızca tutar içeren (`amount-only`) fatura yüküne geri döner.

Modülü güncelledikten sonra Drupal önbelleğini yeniden oluşturun: `drush --uri=https://your-store.example cache:rebuild`.

## Mağazanızı bağlama

Drupal'da `/admin/commerce/config/payment/paymos` adresini açın. Paymos panelinde bu mağaza için kullanmak istediğiniz projeyi seçin. Drupal'da **Connect Paymos** seçeneğine tıklayın, bağlantıyı onaylayın ve Drupal'a dönüp **Check approval** düğmesini seçin.

Ardından `/admin/commerce/config/payment-gateways/add` sayfasını açın. Yeni bir ödeme ağ geçidi oluşturun, Paymos'u seçin ve kullanmayı planladığınız ortama göre Sandbox veya Live seçeneğini belirleyin. Hesap bağlantısı ve Commerce ödeme ağ geçidi kurulumu iki ayrı adımdır; ağ geçidini ödeme sayfasında kullanmadan önce ikisini de tamamlayın.

Drupal yönetim ekranında gösterilen dönüş adreslerini mevcut Paymos projenize kopyalayın: Success URL için `/paymos/return`, Fail URL için `/paymos/return?cancel=1`. Bunu bir kez yapmanız yeterlidir. Barındırılan ödeme sayfası **Back to store** bağlantısını kullanır. Drupal, son ödeme oturumunun yerel dönüş hedeflerini alıcının tarayıcı oturumunda bir saat boyunca saklar. Başka bir tarayıcı, süresi dolmuş oturum verisi veya değişmiş sipariş/ağ geçidi eşleşmesi alıcıyı mağaza ana sayfasına yönlendirir. Tarayıcı dönüşü hiçbir zaman ödemeyi onaylamaz; ödeme durumunu doğrulanmış webhook/API kontrolleri ve yerel Commerce ödeme kaydı belirler. Fatura istekleri yalnızca sipariş referansını, `project_id`, `amount`, `currency` alanlarını ve isteğe bağlı normalleştirilmiş `products[]` dizisini gönderir; alıcı kimlik bilgilerini içermez.

Kimlik bilgileri şifrelenmiş Drupal state alanında saklanır. Şifreleme, Drupal özel anahtarını ve `hash_salt` değerini kullanır; gizli anahtarlar yapılandırma dışa aktarımlarında görünmez. Mağazanızın kanonik URL'si değişirse mağazayı Paymos'a yeniden bağlayın.

## Sipariş kalemleri ve katalog sınırı

`Paymos\Plugin\InvoicePresentation::normalizeProducts` kullanılabilir olduğunda `CheckoutService`, kargo, indirim, vergi, ek ücret ve not satırlarını ayıklar ve `1..50` ürün kalemini (`title`, pozitif tam sayı `quantity`, ondalık dize `unit_amount` ve isteğe bağlı düz metin `description`) hep-ya-da-hiç kuralıyla `products[]` içine normalleştirir: herhangi bir ürün satırı geçersizse veya sepette `50` adetten fazla kalem varsa Drupal, kırpılmış bir sepet yerine yerel sipariş toplamını (`amount`) taşıyan `amount-only` faturası gönderir. Boş açıklamalar ürün satırını düşürmeden kalem bazında atlanır. Normalleştirilmiş `products[]` anlık görüntüsü kalıcı `InvoiceStore` kaydında saklanır; böylece kesintiye uğrayan oluşturma denemeleri değişmiş bir siparişi yeniden okumak yerine dondurulmuş değerleri kullanır. Geçmiş anlık görüntülerde yeniden denemeler yalnızca kullanımdan kaldırılmış `image_url` anahtarını giden kopyadan çıkarır, diğer tüm değerleri korur ve saklanan anlık görüntüyü değiştirmez.

12 eklentilik Paymos CMS kataloğunun genelinde (`plugins/cms-store-catalog.json`):

- Tüm **11** ürün ticareti CMS eklentisi `products[]` gönderir: `Paymos\Plugin\InvoicePresentation::normalizeProducts` üzerine kurulu **10** PHP eklentisi (`WooCommerce`, `Easy Digital Downloads`, `paymos/php-sdk:^1.12.0` üzerinde `Drupal Commerce`, `OpenCart`, `PrestaShop`, `WHMCS`, `CS-Cart`, `Magento 2`, `Shopware 6` ve `^1.11.0` üzerinde `HikaShop` `1.1.0`) artı **Odoo** (`20.0.1.1.0`, gömülü Paymos Python SDK `1.x` (`>= 1.6.0`) üzerinden `client.request("POST", "/v1/invoices", payload)` ile `products` gönderir).
- Yalnızca bağış ağ geçidi **`givewp-paymos`**, bağışlarda ürün sepeti olmadığı için `amount-only` olarak kalır.

## Ödeme kontrollerini çalışır durumda tutma

Drupal cron görevini mağazanızın kanonik HTTPS URL'siyle zamanlayın. Örneğin:

```sh
drush --uri=https://your-store.example cron
```

Cron, sonuçlanmamış ödemeleri sınırlı gruplar hâlinde kontrol eder. Ödeme bildirimleri normal şekilde ulaşsa bile cron'u çalışır durumda tutun ki sonuçlanmamış ödemeler düzenli olarak denetlenmeye devam etsin.

## Siparişlerin ödemeyi alma süreci

Ödeme bildirimleri imza taşır ve bir API kontrolünü tetikler. Ödeme kaydedilmeden önce faturanın sipariş, Paymos projesi, ortam, tutar ve para birimi ile birebir eşleşmesi gerekir. Yerel bir SQL işlemi tek bir tamamlanmış Commerce ödemesi kaydeder; mükerrer bildirimler ödemeyi tekrar kaydetmez.

Kısmi veya geç ödemeler ürünleri otomatik olarak teslim etmez. Bilgileri uyuşmayan bir ödeme inceleme gerektirir. Drupal Commerce, teslimat sürecini mağazanızın kendi sipariş iş akışıyla yönetir; bu nedenle ağ geçidini kurarken iş akışınızı gözden geçirin.

## İadeler

Modül, Paymos Merchant API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan iki yerel iade modunu destekler: `full` (tam fatura iadesi) ve `overpayment` (`paid_over` faturasındaki fazla ödemenin iadesi). Paymos fonları yeni bir giden blokzincir transferi olarak yalnızca ödenen faturada kayıtlı doğrulanmış `first_sender` adresine geri gönderir — elle alıcı cüzdan adresi girilmez ve keyfi kısmi iade tutarları desteklenmez.

Yetkili yöneticiler Drupal'daki iki korumalı noktadan iade başlatabilir ve izleyebilir:

- siparişin **Payments** sekmesindeki Commerce ödeme **Refund** işlem formu (`RefundForm`); buradan **Full refund** (`full`) veya **Refund overpayment** (`overpayment`) seçebilirsiniz;
- `/admin/commerce/config/payment/paymos` (`ConnectForm`) sayfasındaki **Paymos refunds** paneli; uygun Paymos ödemeleri için `full` ve `overpayment` iadeleri başlatmayı destekler, işlem durumlarını ve harici iade gözlemlerini listeler.

`/paymos/webhook` adresine gelen imzalı webhook bildirimleri ve Drupal cron mutabakatı bekleyen iade işlemlerini günceller; Paymos tamamlanmayı (`completed`) doğruladığında yerel Commerce ödeme iade durumunu uygular. Sandbox modunda test amacıyla nihai iade sonuçları (`completed`, `failed`, `cancelled`) simüle edilebilir.

## Modülü kaldırma

Mağazaya ait iade geçmişi kayıtları mevcutken `/admin/commerce/config/payment/paymos` üzerinden Paymos bağlantısını kesme işlemi engellenir. Veritabanında iade kayıtları veya sonuçlanmamış bekleyen Paymos ödemeleri bulunduğu sürece modülün kaldırılması engellenir (tamamlanmış faturalar ve webhook olay satırları bağlantıyı kesmeyi veya kaldırmayı engellemez). Kaldırmayı engelleyen bekleyen ödeme veya iade kaydı kalmadığında, modülü kaldırmak modüle ait şifrelenmiş kimlik bilgilerini siler ve yerel Drupal Commerce ödeme kayıtlarını korur.

## Daha fazla bilgi

Ödeme hizmeti hakkında bilgi almak için [Paymos](https://paymos.io/tr) adresini ziyaret edin. Ayrıntılı rehberler için [Paymos dokümantasyonuna](https://paymos.io/tr/docs) göz atın.
