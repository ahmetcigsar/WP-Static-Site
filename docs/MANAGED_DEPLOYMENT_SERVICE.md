# Wext Managed Deployment Service Contract

Bu ertelenmiş sözleşme, WordPress eklentisi ile ayrı çalıştırılacak Wext deployment servisi arasındaki sınırı tanımlar. Yönetilen bağlantı arayüzü mevcut sürümde etkin değildir.

## Operator configuration

WordPress paketinde servis adresi sabit veya `wp-config.php` üzerinden tanımlanır:

```php
define('WEXTSTAT_DEPLOY_SERVICE_URL', 'https://deploy.example.com');
```

Servis tarafında Cloudflare OAuth istemcisinin Client ID, Client Secret ve sabit redirect URL değeri secret olarak tutulur. Cloudflare Authorization Code akışı kullanılmalı; public kullanım öncesinde istemci domaini doğrulanmalı ve OAuth client görünürlüğü uygun hale getirilmelidir.

## 1. Start Cloudflare authorization

WordPress yönlendirmesi:

```http
GET /connect/cloudflare
  ?site_url=https%3A%2F%2Fcms.example.com
  &callback_url=https%3A%2F%2Fcms.example.com%2Fwp-admin%2Fadmin-post.php%3Faction%3Dwext_static_managed_callback
  &state=...
  &locale=tr_TR
```

Servis, `site_url`, `callback_url` ve `state` değerlerini sunucu tarafında kısa ömürlü ve tek kullanımlık saklar; ardından kullanıcıyı Cloudflare OAuth izin ekranına yönlendirir. Cloudflare dönüşünde servis kendi tek kullanımlık bağlantı kodunu üretip WordPress callback URL'sine `code` ve özgün `state` ile döner.

## 2. Exchange one-time code

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

## 3. Create deployment

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

## 4. Signed status callback

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
  "job_id": "20260815-120000-AbCd1234",
  "state": "completed",
  "build_sha256": "64-character-sha256",
  "deployment_url": "https://www.example.com"
}
```

`state` yalnızca `deploying`, `completed` veya `failed` olabilir. WordPress beş dakikadan eski/yeni timestamp değerlerini, hatalı imzayı, bilinmeyen job ID'yi ve eşleşmeyen checksum değerini reddeder.

## 5. Disconnect

```http
DELETE /v1/wordpress/connections/current
Authorization: Bearer site-scoped-service-token
```

Servis bağlantıyı ve Cloudflare refresh/access tokenlarını iptal eder. WordPress, servis yanıt veremese bile yerel şifrelenmiş bağlantıyı siler; kullanıcı daha sonra yeniden bağlanabilir.
