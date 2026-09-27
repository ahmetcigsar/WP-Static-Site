# Wext Static Publisher

Farklı WordPress sitelerinde kullanılmak üzere geliştirilen, Cloudflare Workers Static Assets odaklı statik export eklentisi.

## MVP özellikleri

- Aynı origin HTML, CSS, JS, görsel ve font kaynaklarını tarama
- WordPress origin adresini canlı statik domain ile değiştirme
- Asset adreslerini hem `pages.dev` hem özel domainde çalışan kök-relative yollara dönüştürme
- Cloudflare `_headers` ve `_redirects` üretimi
- ZIP ve SHA-256 manifest üretimi
- Sol ana menüde doküman ikonlu Static Publisher yönetim ekranı
- Main, Deploy, Static Site, SEO, Arama, Hide, Diagnostics, Activity Logs ve About sekmelerine ayrılmış yönetim görünümü
- Static Site altında General, Headless CMS ve Multilingual ayarları
- SEO ana sekmesinde SEO Plugins dikey sekmesi
- SEO Plugins ekranında etkin eklentiyi öne çıkaran, çıktı sayılarını özetleyen ve Metadata/Teknik SEO seçeneklerini açılır kartlarda gruplayan düzen
- Rank Math metadata çıktılarının sayfa, yazı, özel içerik türü ve arşiv/taksonomi bazında; Schema, XML sitemap ve robots.txt çıktılarının ayrıca yönetilmesi
- All in One SEO metadata çıktılarının sayfa, yazı, özel içerik türü ve arşiv/taksonomi bazında; Schema, XML sitemap ve robots.txt çıktılarının ayrıca yönetilmesi
- SEOPress metadata çıktılarının sayfa, yazı, özel içerik türü ve arşiv/taksonomi bazında; Schema, XML sitemap ve robots.txt çıktılarının ayrıca yönetilmesi
- SureRank SEO, The SEO Framework ve Yoast SEO için içerik türü bazlı metadata, Schema, XML sitemap ve robots.txt entegrasyonları
- WordPress yönetici/site dilini otomatik izleyen Türkçe, İngilizce, İspanyolca, Fransızca, Basitleştirilmiş Çince, Japonca, Arapça ve Portekizce arayüz paketleri
- Deploy sekmesinin sol dikey menüsünde ZIP File, GitHub, Cloudflare, SFTP ve Auto Deploy seçenekleri
- Kullanıcının kendi Cloudflare Account ID ve API tokenı ile doğrudan Workers Static Assets yayını
- Tokenın WordPress güvenlik anahtarlarıyla şifreli saklanması; bağlantıyı yönetme ve kaldırma
- Cloudflare yapılandırıldığında statik üretimi ve Cloudflare yayınını tek butonda başlatan, export ile deploy durumlarını ayrı gösteren akış
- GitHub Actions sonucunu WordPress'e geri bildiren; belirli job ID ve SHA-256 doğrulamalı Cloudflare deploy durumu
- Deploy > ZIP File kartında indirme/silme işlemleri ve 10 kayıtlık sayfalama ile ZIP dosya yönetimi; kartın altında arşiv saklama ayarı
- Şifreli parola saklama, bağlantı testi, manuel yükleme ve başarılı export sonrası seçilebilir otomatik yükleme sunan SFTP desteği
- Yerel Fuse.js 7.3.0 paketiyle çalışan, alanları ve ağırlıkları yapılandırılabilen statik site araması
- Search ekranında Static Search, Indexing Selectors ve Fuse.js dikey alt sekmeleri
- Hide ekranında Directory, Traces ve Static Outputs dikey alt sekmeleri
- Export sonrası JSON/HTML SEO audit raporu, canonical ve hreflang doğrulaması, image/Schema denetimi
- Canonical, lastmod, image ve dil alternatiflerini içeren gelişmiş sitemap; isteğe bağlı video ve Google News sitemap'leri
- WordPress eski slug, Redirection, Rank Math ve özel yönlendirme kurallarını statik `_redirects` çıktısına aktarma
- Yol bazlı noindex, dosyalar için X-Robots-Tag, deploy sonrası değişen URL'ler için IndexNow ve performans raporu
- Hide sekmesinden WordPress yollarını yeniden adlandırma; sürüm, generator, XML-RPC, embed ve emoji izlerini statik çıktıdan temizleme
- Headless + Static Publisher modu ile WordPress tema ön yüzünü ziyaretçilere kapatma; imzalı dahili export istekleriyle ana sayfa ve tasarımı statik çıktıda koruma
- Main sekmesinde son başarılı static oluşturma zamanı ve yeşil ilerleme/tamamlanma göstergesi
- Main sekmesinde iki saniyede bir yenilenen canlı export durumu, aşamalı ilerleme ve takılan WP-Cron işi uyarısı
- Diagnostics sekmesinde PHP, Basic Auth, php-xml, cURL, site URL erişimi, kalıcı bağlantılar, indexlenebilirlik, önbellek ve WP-Cron kontrolleri
- İlk kurulumda ve isteğe bağlı yeniden kontrolde çalışan uyumsuz eklenti, geçici dizin ve MySQL yetki kontrolleri
- About sekmesinde sürüm ve destek bilgileri
- Modern yönetim arayüzü, kart tabanlı içerik alanları, yenilenmiş sekmeler ve butonlar
- Activity Logs kayıtlarında arama ve ortalanmış modern sayfalama
- Activity Logs kayıtlarında WordPress ayarlarına bağlı tarih/saat biçimi
- Yalnızca son exporta ait, veritabanı dışında tutulan ve sayfa başına 50 kayıt gösteren Activity Logs
- Activity Logs içinde kaynak WordPress URL'sini ve üretilen statik yolu ayrı sütunlarda gösterme
- Yönetim ekranından son başarılı ZIP'i indirme
- Varsayılan 5 arşiv için ayarlanabilir ZIP saklama sınırı
- İş kimliği, URL sayısı ve oluşturma zamanını gösteren ZIP arşiv tablosu
- ZIP arşiv tablosunda gösterim sırasını belirten bilgi amaçlı Sıra sütunu
- En son ZIP'i koruyarak eski arşivleri elle temizleme
- ZIP arşivlerini tek tek veya toplu olarak indirme ve silme
- `wp wext-static export` WP-CLI komutu
- Application Password korumalı export/status/artifact REST uçları
- CI için yönetici yetkisi gerektirmeyen `Static Publisher Deploy` kullanıcı rolü
- Yazı, sayfa, özel içerik, kategori/etiket, medya, menü, bileşen, tema ve site ayarı değişiklikleri için ayrı ayrı seçilebilen otomatik export tetikleyicileri
- Seçilen değişiklikleri 60 saniye birleştiren otomatik export ve isteğe bağlı deployment webhook'u
- `/tr/`, `/en/` gibi dil dizinlerini birlikte export eden ve kök adreste tarayıcı diline göre yönlendiren çoklu dil desteği
- Cloudflare Workers Static Assets için örnek GitHub Actions workflow'u

## Kurulum

`wext-static-publisher` klasörünü WordPress'in `wp-content/plugins/` dizinine kopyalayın ve eklentiyi etkinleştirin. Ardından sol ana menüdeki **Static Publisher** sayfasından canlı domaini ve URL sınırını yapılandırın.

Sunucuda PHP DOM ve Zip eklentileri bulunmalıdır. SFTP istemcisi eklenti paketine dahildir; PHP cURL/libcurl derlemesinde ayrıca `sftp` protokolü bulunması gerekmez. Uzun export ve SFTP işleri için gerçek sistem cron'u ile `wp-cron.php` çalıştırılması önerilir.

## WP-CLI

```bash
wp wext-static export
wp wext-static export --format=json
```

## CI secrets

- `WP_ORIGIN`: WordPress origin adresi, örneğin `https://cms.example.com`
- `WP_USER`: `Static Publisher Deploy` rolündeki otomasyon kullanıcısı
- `WP_APP_PASSWORD`: Kullanıcının Application Password değeri
- `CLOUDFLARE_API_TOKEN`: kendi Worker deployment hedefiniz için en düşük gerekli yetkilere sahip Cloudflare API token
- `CLOUDFLARE_ACCOUNT_ID`: Cloudflare hesap kimliği

Örnek workflow `.github/workflows/deploy-example.yml` içindedir. GitHub yolunda Worker adı ve asset ayarları `wrangler.jsonc` içinde tutulur; Custom Domain dashboard tarafından yönetilmeye devam eder. CI kullanıcısına yönetici rolü vermeyin; eklentinin oluşturduğu `Static Publisher Deploy` rolünü seçin ve bu kullanıcıya ayrı bir Application Password üretin.

Tam otomatik akış için eklenti ayarlarında deployment webhook alanını `https://api.github.com/repos/SAHIP/REPO/dispatches` olarak girin. Fine-grained bearer token yalnızca ilgili repository için `Contents: write` yetkisiyle oluşturulmalıdır. Workflow dosyası repository'nin varsayılan branch'inde bulunmalıdır. Bu GitHub seçeneğinde Cloudflare tokenı yalnızca GitHub secrets içinde tutulur; doğrudan yayın seçeneğinde token WordPress içinde şifrelenerek saklanır.

Webhook yapılandırıldığında Main ekranındaki **Create Static Site** işlemi **Deploy to Cloudflare** olarak görünür. Buton önce statik snapshot'ı oluşturur; export başarılı olursa GitHub Actions yalnızca o işe ait `/exports/{job_id}/artifact` dosyasını indirir, manifestteki job ID ve SHA-256 değerini doğrular, Wrangler ile Cloudflare'a yükler ve canlı adresi kontrol eder. Workflow sonucu `/deployments/callback` üzerinden WordPress'e bildirilir; Main ve **Deploy > Cloudflare** ekranlarında export ve Cloudflare deploy durumları ayrı gösterilir.

Callback ve job-specific artifact uçları da `Static Publisher Deploy` rolü ile Application Password kimlik doğrulaması ister. GitHub Actions için `WP_USER` olarak yönetici hesabı yerine bu sınırlı rol kullanılmalıdır.

## Kendi Cloudflare hesabınızla doğrudan yayın

Eklenti ücretsiz ve GPL-2.0-or-later lisanslıdır; SEO, otomatik export, GitHub ve Cloudflare yayını için Wext lisansı gerekmez. **Static Site > General** bölümünde herkese açık HTTPS adresini ayarlayın. **Deploy > Cloudflare** bölümünde [Cloudflare Account ID](https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/), benzersiz Worker adı ve ilgili hesaba `Workers Scripts Write` yetkisi veren [bir API tokenı](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/) girin. Kullanıcı veya hesap API tokenı kullanılabilir. Token WordPress güvenlik anahtarlarından türetilen anahtarla şifrelenir ve arayüzde tekrar gösterilmez. Bağlantıyı kaldırmak tokenı WordPress'ten siler; Cloudflare hesabındaki tokenı iptal etmek için Cloudflare panelini kullanın.

**Deploy to Cloudflare** önce yeni statik ZIP ve build klasörü oluşturur, ardından Cloudflare'ın [Direct Upload API](https://developers.cloudflare.com/workers/static-assets/direct-upload/) akışıyla asset manifestini, eksik dosyaları ve Worker modülünü doğrudan kullanıcının hesabına gönderir. `_headers` ve `_redirects` kuralları Worker asset yapılandırmasına aktarılır. Wext dağıtım servisi, OAuth uygulaması veya lisans sunucusu kullanılmaz.

Worker'ın herkese açık olması için kendi Cloudflare panelinizde `workers.dev` alt alanını ya da özel domaini yapılandırın. **Static Site > General** içindeki hedef adres bu domainle eşleşmelidir; eklenti custom domain oluşturmuyor veya DNS kaydını değiştirmiyor. Yeni bağlantı, eski Wext servis bağlantısından otomatik aktarılmaz; bir kez kendi tokenınızla bağlanmanız gerekir.

GitHub Actions ile yayın, ZIP indirme ve SFTP alternatifleri korunur. GitHub akışında Cloudflare tokenını GitHub secrets içinde tutun; doğrudan yayın için GitHub gerekmez.

## SFTP deployment

**Deploy > SFTP** ekranında sunucu, port, kullanıcı adı, parola ve uzak hedef dizini kaydedilir. Parola WordPress güvenlik anahtarlarından türetilen anahtarla Sodium veya OpenSSL kullanılarak şifrelenir ve arayüzde tekrar gösterilmez. Önce **Bağlantıyı Test Et**, ardından **Son Statik Siteyi Yükle** kullanılmalıdır. İstenirse her başarılı export sonrasında otomatik SFTP yüklemesi etkinleştirilebilir.

Sunucu kimliğini doğrulamak için hosting sağlayıcısından alınan 32 karakterlik MD5 SSH host fingerprint değeri girilmesi önerilir. Yükleme aynı uzak yoldaki dosyaların üzerine yazar; hedefteki ilgisiz veya eski dosyaları otomatik silmez. SFTP hatası başarılı ZIP exportunu silmez ve son SFTP durumu ayrı kaydedilir.

## Çoklu dil yönlendirmesi

WordPress çoklu dil eklentisi çevirileri dizin tabanlı adreslerde yayınlamalıdır: `/tr/`, `/en/`, `/de/`. Static Publisher içindeki **Static Site > Multilingual** ekranında aynı dil kodlarını satır başına bir tane olacak şekilde girin, varsayılan dili seçin ve yönlendirmeyi etkinleştirin.

İlk kurulumda WordPress site dili varsayılan dil olarak seçilir ve **Supported Language Codes** listesinin ilk satırına yerleştirilir. Daha önce kaydedilmiş dil ayarları eklenti güncellemelerinde korunur.

Exporter her dil kökünü ayrıca tarar ve eksik bir dil kökü varsa export işlemini başarısız sayar. Statik paketteki `wext-language-config.json` Cloudflare Worker tarafından okunur. Worker sadece `/` isteğinde önce `wext_language` çerezini, sonra `Accept-Language` başlığını, son olarak varsayılan dili kullanır. `/en/about/` gibi açık dil adresleri yeniden yönlendirilmez. Dil dizinindeki bir sayfa ziyaret edildiğinde tercih çerezi güncellenir.

Her çeviri sayfası WordPress tarafında doğru `lang`, canonical ve karşılıklı `hreflang` etiketlerini üretmelidir. Exporter `hreflang` adreslerini canlı statik domaine taşır ve kök yönlendirici için eksikse `x-default` ekler. Query-string tabanlı `?lang=en` yapısı desteklenmez.

## Rank Math SEO entegrasyonu

**SEO > SEO Plugins** ekranından Rank Math entegrasyonu etkinleştirilebilir. Metadata; sayfa, yazı, özel içerik türü ve arşiv/taksonomi için ayrı ayrı yönetilir. JSON-LD Schema, XML sitemap ailesi ve `robots.txt` çıktısı da bağımsız seçilir. Exporter iç URL'leri canlı statik domaine dönüştürür; statik arama açıksa Rank Math `SearchAction` hedefini statik arama sayfasına uyarlar.

## All in One SEO entegrasyonu

**SEO > SEO Plugins** ekranından All in One SEO entegrasyonu etkinleştirilebilir. Metadata; sayfa, yazı, özel içerik türü ve arşiv/taksonomi için ayrı ayrı yönetilir. JSON-LD Schema, XML sitemap ailesi ve `robots.txt` çıktısı da bağımsız seçilir. Exporter AIOSEO tarafından üretilen iç URL'leri canlı statik domaine dönüştürür; statik arama açıksa `SearchAction` hedefini statik arama sayfasına uyarlar.

## SEOPress entegrasyonu

**SEO > SEO Plugins** ekranından SEOPress entegrasyonu etkinleştirilebilir. Metadata; sayfa, yazı, özel içerik türü ve arşiv/taksonomi için ayrı ayrı yönetilir. JSON-LD Schema, `/sitemaps.xml` ile başlayan XML sitemap ailesi ve `robots.txt` çıktısı da bağımsız seçilir. Exporter iç URL'leri canlı statik domaine dönüştürür; statik arama açıksa `SearchAction` hedefini statik arama sayfasına uyarlar.

## SureRank SEO, The SEO Framework ve Yoast SEO entegrasyonları

**SEO > SEO Plugins** ekranında her eklenti için ayrı bir kart bulunur. Metadata; sayfa, yazı, özel içerik türü ve arşiv/taksonomi için ayrı ayrı yönetilir. JSON-LD Schema, eklentiye ait XML sitemap ailesi ve `robots.txt` bağımsız seçilir. SureRank ve Yoast sitemap taraması `/sitemap_index.xml`, The SEO Framework taraması `/sitemap.xml` adresinden başlar; yalnızca aynı kaynak alan adındaki XML/XSL dosyaları pakete alınır.

## Geliştirme doğrulaması

```bash
find wext-static-publisher -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n scripts/*.sh
node --test tests/worker-language-routing.mjs
npx --yes wrangler@latest deploy --dry-run
./scripts/package-plugin.sh
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-smoke.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-brand-migration.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-activity-log.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-archives.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-export.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-aioseo.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-seopress.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-seo-metadata-groups.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=wext-static-publisher --mount=.:/workspace -- /workspace/tests/playground-additional-seo.php
```

Mimari ve sınırlar için `docs/ARCHITECTURE.md` dosyasına bakın.
