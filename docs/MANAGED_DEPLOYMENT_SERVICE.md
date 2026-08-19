# Wext Managed Deployment Service Contract

Bu sözleşme, WordPress eklentisi ile ayrı çalışan Wext deployment servisi arasındaki etkin istemci sınırını tanımlar. Lisans, Cloudflare bağlantısı ve deployment kuyruğu etkindir. Faz 4 canlı kabulü 17 Ağustos 2026 tarihinde gerçek OAuth, WordPress artifact, Workers Static Assets yayını ve imzalı callback zinciriyle tamamlanmıştır.

## Canlı kabul durumu

- Production ve staging readiness uçları PostgreSQL/Redis bağımlılıklarıyla HTTP 200 döndürdü.
- Gerçek Cloudflare OAuth bağlantı ve revoke akışı tamamlandı.
- Gerçek WordPress export paketi indirildi, doğrulandı ve Workers Static Assets'e yayınlandı.
- `deploying`, `completed` ve kontrollü hata callback'leri; retry, dead-letter ve queue recovery davranışları doğrulandı.
- Backup/restore, tenant izolasyonu ve kabul sonrası credential rotasyonu tamamlandı.

Bu kabul servis altyapısına aittir. Her müşteri sitesi yine kendi lisans aktivasyonu, Cloudflare hesap izni, target seçimi ve ilk deployment sonucu üzerinden ayrı doğrulanır.

## Faz 5 ticari lisans sözleşmesi

- Ticari planlar USD olarak yıllık `$39/year` ve tek seferlik `$199 lifetime` şeklindedir; iki plan da bir site aktivasyonu hakkı verir.
- Satın alma ve müşteri portalı Stripe Checkout/Portal üzerinden deployment servisinin güvenilir sunucu katmanında oluşturulur. `BILLING_API_TOKEN`, Stripe secret/webhook anahtarları ve Price ID değerleri WordPress'e ya da tarayıcıya verilmez.
- WordPress ödeme veya portal oturumu oluşturmaz. Kullanıcının dış satın alma akışından aldığı lisans anahtarını etkinleştirir ve lisans anahtarını kalıcı olarak saklamaz.
- Yıllık ödeme `past_due` olduğunda yedi günlük grace süresi servis tarafından uygulanır; süre sonunda lisans askıya alınır, başarılı ödeme yeniden etkinleştirir. Lifetime tam iadesi entitlement ve bağlı lisans erişimini geri alır.
- Faz 5 Stripe sandbox kabulü tamamlanmıştır. Production live-mode ürün, Price, portal, Tax, webhook ve gerçek ödeme/iade kabulü ayrı ve açık bir canlı operasyon onayı gerektirir.

## Operator configuration

Varsayılan servis adresi `https://deploy.wext.io` değeridir. Staging veya self-hosted kurulumda `wp-config.php` üzerinden değiştirilebilir:

```php
define('WEXTSTAT_DEPLOY_SERVICE_URL', 'https://deploy.example.com');
```

Servis tarafında Cloudflare OAuth istemcisinin Client ID, Client Secret ve sabit redirect URL değeri secret olarak tutulur. Cloudflare Authorization Code akışı kullanılmalı; public kullanım öncesinde istemci domaini doğrulanmalı ve OAuth client görünürlüğü uygun hale getirilmelidir.

## 1. Lisans aktivasyonu ve connect ticket

WordPress kalıcı, korumalı bir `installation_id` üretir ve kullanıcıdan aldığı lisans anahtarını kaydetmeden aktivasyon ister:

```http
POST /v1/wordpress/license-activations
Content-Type: application/json

{"license_key":"...","installation_id":"wext_...","site_url":"https://cms.example.com"}
```

Kısa ömürlü activation credential ile site ve callback adresine bağlı tek kullanımlık `connect_ticket` alınır. Lisans anahtarı, activation credential veya servis tokenı loglanmaz.

## 2. Start Cloudflare authorization

WordPress yönlendirmesi:

```http
GET /connect/cloudflare
  ?site_url=https%3A%2F%2Fcms.example.com
  &callback_url=https%3A%2F%2Fcms.example.com%2Fwp-admin%2Fadmin-post.php%3Faction%3Dwext_static_managed_callback
  &state=...
  &locale=tr_TR
  &connect_ticket=...
```

Servis, `site_url`, `callback_url` ve `state` değerlerini sunucu tarafında kısa ömürlü ve tek kullanımlık saklar; ardından kullanıcıyı Cloudflare OAuth izin ekranına yönlendirir. Cloudflare dönüşünde servis kendi tek kullanımlık bağlantı kodunu üretip WordPress callback URL'sine `code` ve özgün `state` ile döner.

## 3. Exchange one-time code

```http
POST /v1/wordpress/connections/exchange
Content-Type: application/json

{
  "code": "one-time-code",
  "site_url": "https://cms.example.com",
  "callback_url": "https://cms.example.com/wp-admin/admin-post.php?action=wext_static_managed_callback"
}
```

Başarılı yanıt:

```json
{
  "access_token": "site-scoped-service-token",
  "callback_secret": "minimum-32-character-random-secret",
  "account_label": "Example Company",
  "domain": "www.example.com",
  "deployment_url": "https://www.example.com"
}
```

Kod tek kullanımlık olmalı; `site_url` ve `callback_url` başlangıç isteğiyle birebir eşleşmelidir. Servis tokenı yalnızca bu WordPress sitesi ve seçilen Cloudflare hedefi için işlem yapabilmelidir.

## 4. Create deployment

```http
POST /v1/deployments
Authorization: Bearer site-scoped-service-token
Content-Type: application/json

{
  "job_id": "20260815-120000-AbCd1234",
  "build_sha256": "64-character-sha256",
  "artifact_url": "https://cms.example.com/wp-json/wext-static/v1/exports/.../managed-artifact?expires=...&signature=...",
  "callback_url": "https://cms.example.com/wp-json/wext-static/v1/managed-deployments/callback",
  "target_url": "https://www.example.com"
}
```

Servis paketi süresi dolmadan indirir, ZIP içeriğini güvenli bir geçici dizinde açar, manifestteki `job_id` ve `build_sha256` değerlerini istekle karşılaştırır, Cloudflare Workers Static Assets'e atomik deployment yapar ve canlı URL'yi kontrol eder. ZIP path traversal girdileri reddedilmelidir.

`202 Accepted` yanıtında `deployment_id`, aynı `job_id`, `status` ve `created_at` zorunludur. WordPress bu alanları doğrular ve servis deployment kimliğini ayrı saklar.

## 5. Signed status callback

Callback JSON gövdesi değiştirilmeden aşağıdaki imza üretilir:

```text
signature = hex(hmac_sha256(callback_secret, timestamp + "." + raw_request_body))
```

```http
POST /wp-json/wext-static/v1/managed-deployments/callback
X-Wext-Timestamp: 1786785600
X-Wext-Signature: hex-signature
Content-Type: application/json

{
  "delivery_id": "uuid",
  "job_id": "20260815-120000-AbCd1234",
  "status": "completed",
  "build_sha256": "64-character-sha256",
  "deployment_url": "https://www.example.com"
}
```

`status` yalnızca `deploying`, `completed` veya `failed` olabilir. WordPress beş dakikadan eski/yeni timestamp değerlerini, hatalı imzayı, yinelenen `delivery_id` değerini, bilinmeyen job ID'yi ve eşleşmeyen checksum değerini reddeder. Eski `state`/`error` alanları yalnız geriye uyumluluk için okunur; Faz 3 servisinin kanonik alanları `status`/`error_code` değerleridir.

## 6. Disconnect ve lisans deaktivasyonu

```http
DELETE /v1/wordpress/connections/current
Authorization: Bearer site-scoped-service-token
```

Servis bağlantıyı ve Cloudflare refresh/access tokenlarını iptal eder. WordPress, servis yanıt veremese bile yerel şifrelenmiş bağlantıyı siler; kullanıcı daha sonra yeniden bağlanabilir.

Lisans ayırma bağlantıdan ayrı olarak `DELETE /v1/wordpress/license-activations/current` ile yalnızca `license:deactivate` yetkisi taşıyan siteye bağlı `detach_credential` kullanılarak yapılır. Credential servis tarafında tek yönlü hash, eklentide şifreli option olarak tutulur ve lisans hak süresinden daha uzun geçerli olmaz. Başarılı ayırmada yerel bağlantı ve lisans durumu temizlenir, aktif site slotu boşalır; lisansın kalan süresi ve müşteri verisi silinmez. Lisans kalan süre içinde aynı veya başka bir domainde yeniden etkinleştirilebilir; installation ID yeniden kurulum bağını korumak için saklanır.
