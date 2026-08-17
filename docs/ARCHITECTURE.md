# Wext Static Publisher mimarisi

## Sınırlar

- WordPress içerik kaynağı ve export motorudur.
- Gelişmiş GitHub akışında Cloudflare kimlik bilgileri yalnızca CI secrets içinde tutulur.
- Yönetilen akışta Cloudflare OAuth erişimi yalnızca deployment servisinde tutulur; WordPress lisans anahtarını kaydetmez ve yalnızca korumalı installation ID ile siteye özel, şifrelenmiş servis erişim/callback anahtarlarını saklar.
- REST uçları WordPress Application Password ve eklentiye ait `wext_static_export` yetkisi ister.
- Headless + Static Publisher modu tema ön yüzünü ziyaretçilere kapatır; aynı-origin export istekleri WordPress salt değerinden türetilen, beş dakika geçerli timestamp + HMAC başlıklarıyla tema HTML'ine erişir.
- Cloudflare CI deployment'ı tam ve atomik bir statik snapshot'tır; SFTP akışı dosyaları doğrudan hedef dizine yükler.
- Form, arama, yorum, üyelik ve e-ticaret bu MVP'nin kapsamında değildir.

## Akış

1. Manuel akışta CI `POST /wp-json/wext-static/v1/exports` çağrısı yapar. Otomatik akışta WordPress, Settings ekranında seçilen içerik, medya, menü, tema veya site ayarı değişikliklerini 60 saniye biriktirir.
2. WordPress işi WP-Cron kuyruğuna ekler.
3. Exporter yayınlanmış içerik URL'lerini seed olarak alır ve aynı origin kaynaklarını tarar.
   Headless modu açıksa bu istekler imzalanır; CMS originindeki normal ziyaretçiler aynı sayfalarda yapılandırılan 404, 410 veya 307 davranışını alır.
4. HTML/CSS içindeki origin adresleri canlı statik domain ile değiştirilir.
5. Snapshot, `_headers`, `_redirects`, dil yönlendirme yapılandırması, manifest ve ZIP oluşturulur.
6. Export tamamlanınca isteğe bağlı GitHub `repository_dispatch` webhook'u job ID ve build SHA-256 ile CI akışını tetikler.
7. CI yalnızca `/exports/{job_id}/artifact` uç noktasındaki ZIP'i indirir; manifest job ID ve SHA-256 değerlerini webhook verisiyle karşılaştırır.
8. Doğrulanan snapshot Wrangler ile `wp-statik-deneme` Workers Static Assets deployment'ına yüklenir ve deployment URL için HTTP kontrolü yapılır.
9. GitHub Actions `deploying`, `completed` veya `failed` sonucunu kimlik doğrulamalı `/deployments/callback` ucuna gönderir. Export ve Cloudflare deploy durumları WordPress'te ayrı saklanır.
10. SFTP otomatik yükleme açıksa aynı başarılı build dizinindeki statik dosyalar uzak hedefe aktarılır; bu durum ve hatalar ZIP exportundan ayrı kaydedilir.

Yönetilen Cloudflare akışında GitHub adımları yerine lisans aktivasyonu, tek kullanımlık connect ticket ve Cloudflare OAuth hesap seçimi çalışır. Başarılı export sonrasında WordPress 15 dakika geçerli HMAC imzalı artifact URL'siyle `POST /v1/deployments` çağrısı yapar. Yalnız zorunlu `deployment_id`, eşleşen `job_id`, `status` ve `created_at` alanlarını taşıyan `202` yanıtı dispatch olarak kaydedilir; servis imzalı callback ile `deploying`, `completed` veya `failed` sonucunu bildirir.

Bu yönetilen zincirin gerçek OAuth, artifact indirme, Workers Static Assets yayını, callback retry/dead-letter ve queue recovery kabulü 17 Ağustos 2026 tarihinde tamamlanmıştır. Bu servis kabulü, yeni bağlanan her WordPress sitesinin ilk yayın sonucunu otomatik olarak kanıtlamaz; site bazlı durum WordPress'te ayrı izlenir.

## SFTP akışı

- SFTP, paketlenmiş phpseclib 3 istemcisi ve parola kimlik doğrulamasıyla çalışır; phpseclib yüklenemezse SFTP destekli PHP cURL yedek taşıyıcı olarak kullanılabilir.
- Sunucu, port, kullanıcı, uzak dizin, zaman aşımı ve isteğe bağlı MD5 host fingerprint Deploy > SFTP altında tutulur.
- Parola Sodium `secretbox` veya OpenSSL AES-256-GCM ile, WordPress `AUTH_KEY` türevi bir anahtar kullanılarak şifrelenir; parola hiçbir admin yanıtına veya filtre verisine eklenmez.
- Bağlantı testi hedef dizine giriş ve listeleme yetkisini doğrular. Parmak izi girilmişse cURL bağlantı sırasında sunucu anahtarını doğrular.
- Manuel işlem son başarılı build'i yükler. Otomatik seçenek her başarılı export sonrasında aynı işlemi çalıştırır.
- Uzak dizinde aynı yolların üzerine yazılır ve eksik alt dizinler oluşturulur. İlgisiz ya da artık exportta bulunmayan uzak dosyalar güvenlik nedeniyle otomatik silinmez.

## Çoklu dil akışı

- Çoklu dil özelliği yalnızca `/tr/`, `/en/` gibi dizin tabanlı WordPress çeviri URL'leriyle çalışır.
- Etkin dillerin kök adresleri normal içerik seed'lerine ek olarak crawl kuyruğuna alınır; her dil için `/<dil>/index.html` üretilmesi zorunludur.
- Export `wext-language-config.json` ve tercih kaydı için `wext-language-preference.js` üretir.
- Çoklu dil yönlendirme ayarları yönetim arayüzündeki üst seviye `SEO` sekmesinde tutulur; option anahtarı geriye dönük uyumluluk için değişmez.
- `SEO > SEO Plugins`, Rank Math sayfa metadata, JSON-LD, sitemap ve robots çıktılarının statik pakete dahil edilmesini ayrı seçeneklerle yönetir.
- Rank Math sitemap ağacı yalnızca aynı origin içindeki güvenli `*.xml`/`*.xsl` sitemap yollarından takip edilir; en fazla 100 dosya alınır ve bütün origin adresleri canlı hedef domaine dönüştürülür.
- Rank Math JSON-LD URL değerleri hedef domaine taşınır. Statik arama etkinse `SearchAction` hedefi statik arama yoluna çevrilir; dinamik WordPress araması statik çıktıda bırakılmaz.
- Cloudflare Worker yalnızca `/` yolunda çalışır. Açık dil tercihi çerezi `Accept-Language` değerinden, `Accept-Language` ise varsayılan dilden önceliklidir.
- Yönlendirme kullanıcıya bağlı olduğu için `302`, `Cache-Control: private, no-store` ve `Vary: Accept-Language, Cookie` kullanılır.
- Dil içeren yollar doğrudan Static Assets tarafından sunulur; Worker bunları başka dile yönlendirmez.
- Exporter `hreflang` adreslerini hedef domaine dönüştürür ve yönlendirici kökü `x-default` olarak ekler.

## Güvenlik modeli

- CI hesabı ayrı bir WordPress kullanıcısı ve `Static Publisher Deploy` rolünde olmalıdır; yönetici rolü verilmemelidir.
- Bu kullanıcı için yalnızca Application Password üretilmelidir.
- WordPress yönetim parolası CI içine konmamalıdır.
- `WP_APP_PASSWORD` ve Cloudflare token aynı sistem dışında paylaşılmamalıdır.
- Yönetilen akışta artifact indirme URL'si 15 dakika geçerlidir; callback gövdesi siteye özel secret ile HMAC-SHA256 olarak imzalanır ve beş dakikalık zaman penceresinde kabul edilir.
- Callback `delivery_id` taşıyorsa son beş dakika içinde aynı teslimatın tekrar işlenmesi reddedilir.
- Deploy callback'i arşivdeki job ID ve SHA-256 ile eşleşmeyen durum güncellemelerini reddeder.
- Cloudflare token yalnızca yetkili Workers Static Assets hedefi için gereken en düşük kapsamda olmalıdır.
- Otomatik GitHub `repository_dispatch` tetiklemesinde WordPress'te tutulan fine-grained token yalnızca ilgili repository için `Contents: write` yetkisine sahip olmalıdır.
- SFTP hesabı yalnızca hedef statik dizinde yazma yetkisine sahip olmalı; kabuk, WordPress dizini veya daha geniş sunucu erişimi verilmemelidir.
- SFTP host fingerprint hosting sağlayıcısından ayrı bir kanalla doğrulanmalıdır. Parmak izi olmadan bağlantı şifrelidir ancak sunucu kimliği sabitlenmez.

## Bilinen MVP sınırlamaları

- WP-Cron düşük trafikli veya Access arkasındaki originlerde sistem cron ile tetiklenmelidir.
- Çok büyük sitelerde tam crawl yerine artımlı export gerekir.
- JavaScript'in çalışma anında istediği WordPress AJAX/REST uçları statik değildir.
- Karmaşık CSS URL sözdizimleri ve JavaScript içine gömülü asset adresleri ayrıca test edilmelidir.
- Çoklu dilde çeviri içeriklerini ve karşılıklı `hreflang` etiketlerini üretmek WordPress çoklu dil eklentisinin sorumluluğundadır.
- Eklenti Apache/IIS için storage klasörüne erişim engeli yazar. Nginx'te ayrıca `wp-content/uploads/wext-static` yolu engellenmelidir; artifact yalnızca kimlik doğrulamalı REST üzerinden indirilir.
- Origin Cloudflare Access veya HTTP Basic arkasındaysa siteye özel bir MU-plugin ile `wext_static_request_args` filtresinden gerekli servis başlıkları eklenmelidir.
- SFTP deployment parola tabanlıdır; özel anahtar kimlik doğrulaması bu sürümün kapsamında değildir.
- SFTP doğrudan yükleme atomik değildir ve uzak hedeften eski dosyaları temizlemez.

Örnek Access filtresi:

```php
add_filter('wext_static_request_args', function (array $args): array {
    $args['headers']['CF-Access-Client-Id'] = getenv('CF_ACCESS_CLIENT_ID');
    $args['headers']['CF-Access-Client-Secret'] = getenv('CF_ACCESS_CLIENT_SECRET');
    return $args;
});
```
