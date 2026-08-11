# Ragnus Static Publisher mimarisi

## Sınırlar

- WordPress içerik kaynağı ve export motorudur.
- Cloudflare kimlik bilgileri yalnızca CI secrets içinde tutulur.
- REST uçları WordPress Application Password ve eklentiye ait `ragnus_static_export` yetkisi ister.
- Her deployment tam ve atomik bir statik snapshot'tır.
- Form, arama, yorum, üyelik ve e-ticaret bu MVP'nin kapsamında değildir.

## Akış

1. Manuel akışta CI `POST /wp-json/ragnus-static/v1/exports` çağrısı yapar. Otomatik akışta WordPress içerik değişikliğini 60 saniye biriktirir.
2. WordPress işi WP-Cron kuyruğuna ekler.
3. Exporter yayınlanmış içerik URL'lerini seed olarak alır ve aynı origin kaynaklarını tarar.
4. HTML/CSS içindeki origin adresleri canlı statik domain ile değiştirilir.
5. Snapshot, `_headers`, `_redirects`, manifest ve ZIP oluşturulur.
6. Export tamamlanınca isteğe bağlı GitHub `repository_dispatch` webhook'u CI akışını tetikler.
7. CI ZIP'i indirir, doğrular ve Wrangler ile `wp-statik-deneme` Workers Static Assets deployment'ına yükler.

## Güvenlik modeli

- CI hesabı ayrı bir WordPress kullanıcısı ve `Static Publisher Deploy` rolünde olmalıdır; yönetici rolü verilmemelidir.
- Bu kullanıcı için yalnızca Application Password üretilmelidir.
- WordPress yönetim parolası CI içine konmamalıdır.
- `WP_APP_PASSWORD` ve Cloudflare token aynı sistem dışında paylaşılmamalıdır.
- Cloudflare token sadece hedef Pages projesini düzenleyebilecek kapsamda olmalıdır.
- Otomatik GitHub `repository_dispatch` tetiklemesinde WordPress'te tutulan fine-grained token yalnızca ilgili repository için `Contents: write` yetkisine sahip olmalıdır.

## Bilinen MVP sınırlamaları

- WP-Cron düşük trafikli veya Access arkasındaki originlerde sistem cron ile tetiklenmelidir.
- Çok büyük sitelerde tam crawl yerine artımlı export gerekir.
- JavaScript'in çalışma anında istediği WordPress AJAX/REST uçları statik değildir.
- Karmaşık CSS URL sözdizimleri ve JavaScript içine gömülü asset adresleri ayrıca test edilmelidir.
- Eklenti Apache/IIS için storage klasörüne erişim engeli yazar. Nginx'te ayrıca `wp-content/uploads/ragnus-static` yolu engellenmelidir; artifact yalnızca kimlik doğrulamalı REST üzerinden indirilir.
- Origin Cloudflare Access veya HTTP Basic arkasındaysa siteye özel bir MU-plugin ile `ragnus_static_request_args` filtresinden gerekli servis başlıkları eklenmelidir.

Örnek Access filtresi:

```php
add_filter('ragnus_static_request_args', function (array $args): array {
    $args['headers']['CF-Access-Client-Id'] = getenv('CF_ACCESS_CLIENT_ID');
    $args['headers']['CF-Access-Client-Secret'] = getenv('CF_ACCESS_CLIENT_SECRET');
    return $args;
});
```
