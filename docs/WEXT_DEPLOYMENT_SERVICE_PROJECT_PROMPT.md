# Wext Deployment Service — Yeni Proje Ana Promptu

Bu belge, `Wext Deployment Service` projesini ayrı bir Git deposunda tasarlamak ve geliştirmek için Codex'e verilecek ana görev tanımıdır. Aynı zamanda ürün, mimari, güvenlik ve kabul sözleşmesidir.

## Codex'e verilecek görev

Sen kıdemli bir SaaS mimarı, güvenlik odaklı backend geliştiricisi ve DevOps mühendisisin. Aşağıdaki sözleşmeye göre **Wext Deployment Service** isimli, çok kiracılı ve üretime hazırlanabilir bir servis geliştir.

Çalışmaya başlamadan önce bu belgenin tamamını oku. Ardından kaynak WordPress eklentisinin aşağıdaki dosyalarını salt okunur biçimde incele ve mevcut istemci sözleşmesini doğrula:

- `../Wordpress Static Site/docs/MANAGED_DEPLOYMENT_SERVICE.md`
- `../Wordpress Static Site/docs/ARCHITECTURE.md`
- `../Wordpress Static Site/wext-static-publisher/includes/class-managed-deployer.php`
- `../Wordpress Static Site/wext-static-publisher/includes/class-rest-controller.php`

Kaynak WordPress eklentisi deposunda değişiklik yapma. Gerekli eklenti değişikliklerini yeni servis deposundaki bir uyumluluk belgesinde listele.

## 1. Proje ve depo sınırı

Yeni proje şu sibling dizinde kurulmalıdır:

```text
../Wext Deployment Service/
```

Kurallar:

- Bu dizin bağımsız bir Git deposu olmalıdır.
- WordPress eklentisi kaynak kodu bu projeye kopyalanmamalıdır.
- Servis kaynak kodu WordPress eklentisinin içine konmamalıdır.
- İki ürün bağımsız sürümlenmeli ve dağıtılmalıdır.
- Aralarındaki tek çalışma zamanı bağı HTTPS API sözleşmesi olmalıdır.
- Servis dışarıya yalnızca API/web arayüzünü açmalıdır. Worker, PostgreSQL ve Redis internete açılmamalıdır.
- Üretim hedefi Docker Compose üzerinden Dokploy'dur.
- Plan dışındaki canlı Cloudflare, DNS, Dokploy veya ödeme sistemi değişiklikleri açık onay olmadan yapılmamalıdır.

## 2. Ürün hedefi

Teknik bilgisi sınırlı bir WordPress müşterisi şu akışı kullanabilmelidir:

1. Lisansını etkinleştirir.
2. Kendi Cloudflare hesabını OAuth ile bağlar.
3. Yetkili Cloudflare hesabı ve hedef domaini seçer.
4. WordPress'te **Publish to Cloudflare** düğmesine basar.
5. Eklenti statik ZIP ve SHA-256 manifest üretir.
6. Servis paketi güvenli biçimde indirip doğrular.
7. Servis statik siteyi Cloudflare Workers Static Assets'e atomik biçimde yayınlar.
8. Canlı URL'yi kontrol eder.
9. Export ve Cloudflare deployment durumları WordPress'te ayrı gösterilir.

Müşteri GitHub, webhook, Wrangler, Cloudflare API token veya Account ID görmek zorunda kalmamalıdır. Gelişmiş GitHub ve SFTP yöntemleri WordPress eklentisinde ayrı kalacaktır.

## 3. Önerilen teknik temel

Başlangıç için aşağıdaki temel tercih edilir:

- Güncel desteklenen Node.js LTS; tam sürüm araştırılıp sabitlenmeli
- TypeScript ve strict mode
- Fastify tabanlı HTTP API
- PostgreSQL
- Redis ve dayanıklı iş kuyruğu
- Cloudflare'ın resmi API/SDK yaklaşımı
- OpenAPI 3.1
- Docker ve production Docker Compose
- Birim, entegrasyon ve uçtan uca sözleşme testleri

Paketlerin güncel ve desteklenen sürümlerini resmi belgelerden doğrula. Sürüm seçimini `docs/adr/` altında kısa bir ADR ile kaydet. Alternatif bir framework seçilecekse gerekçesini, güvenlik/operasyon etkisini ve geçiş maliyetini önce belgeleyip onay almadan temel stack'i değiştirme.

Cloudflare deployment için resmi API ile izole Wrangler çalıştırma yaklaşımını karşılaştır. Varsayılan olarak resmi API/SDK'yı tercih et. Wrangler subprocess gerekiyorsa her iş izole edilmeli; kullanıcı girdisi shell komutuna eklenmemeli; tokenlar environment/log/process listesine gereksiz biçimde sızmamalıdır.

## 4. Sistem bileşenleri

En az aşağıdaki bileşenleri tasarla:

```text
Internet
  |
  v
Wext API / OAuth UI  (deploy.wext.com)
  |-- PostgreSQL       (private)
  |-- Redis            (private)
  `-- Deployment Worker (private, queue consumer)
          |
          `-- Cloudflare API

WordPress Plugin
  |-- License activation
  |-- OAuth connection start/callback
  |-- Signed, short-lived artifact URL
  `-- Signed deployment status callback
```

API ve deployment worker ayrı process olarak çalışmalı, fakat MVP'de aynı kod tabanını paylaşabilir. Web/API process'i uzun süren ZIP indirme, açma veya Cloudflare yükleme işlemlerini request içinde tamamlamaya çalışmamalıdır.

## 5. Tenant ve veri modeli

Asgari veri modeli aşağıdaki kavramları kapsamalıdır:

- `tenants`
- `users` veya operator identities
- `licenses`
- `license_activations`
- `wordpress_sites`
- `cloudflare_connections`
- `cloudflare_accounts`
- `deployment_targets`
- `deployments`
- `deployment_attempts`
- `oauth_sessions`
- `one_time_codes`
- `service_tokens`
- `webhook_events`
- `audit_logs`

Her tenant'a bağlı tabloda tenant sınırı açık olmalıdır. Sadece uygulama katmanındaki filtrelere güvenme; mümkün olduğunda database constraint, composite foreign key veya PostgreSQL Row-Level Security ile çapraz tenant erişimini önle. Her sorgunun tenant bağlamı test edilmelidir.

Domain, WordPress site URL'si, Cloudflare account ve Worker hedefi arasındaki sahiplik açık biçimde tutulmalıdır. Bir servis tokenı yalnızca tek tenant, tek WordPress site aktivasyonu ve izin verilen deployment hedefleri için geçerli olmalıdır.

## 6. Lisanslama sözleşmesi

Mevcut WordPress istemci sözleşmesinde üretim seviyesinde bir lisans aktivasyon adımı yoktur. Bunu sessizce görmezden gelme.

Yeni servis aşağıdaki güvenli akışı tasarlamalıdır:

1. WordPress sitesi lisans anahtarı ve kalıcı, rastgele `installation_id` ile aktivasyon ister.
2. Servis lisansın durumunu, planını, site limitini ve domain politikasını kontrol eder.
3. Başarılı aktivasyon siteye özgü, geri alınabilir bir service credential veya kısa ömürlü aktivasyon sonucu üretir.
4. Cloudflare bağlantısı yalnızca etkin bir site aktivasyonundan başlatılabilir.
5. OAuth başlangıcı için kısa ömürlü, tek kullanımlık ve sunucu tarafında tutulan `connect_ticket` gerekir.
6. Lisans askıya alınır veya iptal edilirse yeni deployment başlatılamaz; mevcut müşteri verileri silinmez ve operator politikası uygulanır.

Lisans anahtarını veritabanında düz metin saklama. Karşılaştırma için güvenli hash/fingerprint kullan. Loglara lisans anahtarı, service token, OAuth code veya provider token yazma.

Önerilecek yeni endpoint'ler OpenAPI belgesine eklenmeli. Bunların WordPress eklentisinde gerektireceği değişiklikler `docs/PLUGIN_COMPATIBILITY.md` içinde açıkça listelenmelidir.

## 7. Cloudflare OAuth

Cloudflare Authorization Code akışı sunucu tarafında uygulanmalıdır.

Zorunlu kurallar:

- Client ID, Client Secret ve encryption anahtarları yalnızca servis secret store/environment içinde bulunur.
- Redirect URI sabit ve allowlist içindedir.
- OAuth `state` yüksek entropili, kısa ömürlü ve tek kullanımlıktır.
- Uygunsa PKCE kullan; Cloudflare'ın güncel resmi desteğini doğrula.
- OAuth session; tenant, WordPress site aktivasyonu, callback allowlist kaydı ve `connect_ticket` ile bağlanır.
- Callback URL kullanıcı girdisi olarak serbestçe kabul edilmez. Aktivasyon sırasında doğrulanmış WordPress origininden türetilir ve birebir eşleşir.
- Tokenlar envelope encryption ile şifrelenmiş halde tutulur.
- Anahtar sürümü kaydedilir ve key rotation desteklenir.
- Refresh, revoke, expiry ve yeniden bağlanma davranışları uygulanır.
- En az ayrıcalık ilkesiyle yalnızca gerçekten gereken Cloudflare scope'ları istenir.
- Cloudflare hesap/domain seçimi tenant sınırından kaçamaz.

Public kullanımdan önce Cloudflare OAuth uygulaması, redirect domaini ve gerekli doğrulama/inceleme süreci resmi belgelerle teyit edilmelidir. Yerel mock testi canlı OAuth kanıtı olarak sunulmamalıdır.

## 8. WordPress uyumluluk API'si

Mevcut eklenti aşağıdaki çekirdek sözleşmeyi bekler. Güvenli lisans/aktivasyon katmanı eklense bile bu veri şekilleri gereksiz yere değiştirilmemelidir.

### Bağlantıyı başlat

```http
GET /connect/cloudflare
  ?site_url=https%3A%2F%2Fcms.example.com
  &callback_url=https%3A%2F%2Fcms.example.com%2Fwp-admin%2Fadmin-post.php%3Faction%3Dwext_static_managed_callback
  &state=...
  &locale=tr_TR
  &connect_ticket=...
```

`connect_ticket` production'da zorunlu olmalıdır. Eski, biletsiz davranış varsa sadece açıkça işaretlenmiş local/test modunda kullanılmalı ve production'da fail-closed olmalıdır.

### Tek kullanımlık kodu değiştir

```http
POST /v1/wordpress/connections/exchange
Content-Type: application/json

{
  "code": "one-time-code",
  "site_url": "https://cms.example.com",
  "callback_url": "https://cms.example.com/wp-admin/admin-post.php?action=wext_static_managed_callback"
}
```

Başarılı yanıt şekli:

```json
{
  "access_token": "site-scoped-service-token",
  "callback_secret": "minimum-32-character-random-secret",
  "account_label": "Example Company",
  "domain": "www.example.com",
  "deployment_url": "https://www.example.com"
}
```

Kod tek kullanımlıktır; kısa sürede sona erer; site ve callback değerleri OAuth başlangıcıyla birebir eşleşir.

### Deployment oluştur

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

Kurallar:

- Aynı tenant/site için `job_id` idempotency anahtarıdır.
- Aynı `job_id` farklı checksum veya hedefle tekrar kullanılamaz.
- Servis tokenının site ve hedef yetkisi kontrol edilir.
- Artifact ve callback hostları aktivasyonda doğrulanan WordPress origin ile birebir eşleşir.
- Redirect takibi SSRF korumalarını aşamaz.
- İstek yalnızca işi kuyruğa alır; uzun deployment işlemini HTTP request içinde çalıştırmaz.

### Bağlantıyı kes

```http
DELETE /v1/wordpress/connections/current
Authorization: Bearer site-scoped-service-token
```

İşlem yerel bağlantıyı revoke eder, Cloudflare token revoke girişimini kaydeder ve idempotent davranır.

## 9. Artifact indirme ve ZIP güvenliği

WordPress artifact URL'si HMAC imzalıdır ve mevcut sözleşmeye göre 15 dakika geçerlidir. Deployment worker şu kontrolleri uygulamalıdır:

- Yalnızca HTTPS.
- Host, etkinleştirilmiş WordPress site originine eşit olmalı.
- DNS rebinding ve private/link-local/loopback IP hedefleri engellenmeli.
- Her redirect yeniden doğrulanmalı; tercihen redirect kapalı veya çok sınırlı olmalı.
- Bağlantı, toplam süre, response body ve sıkıştırılmış dosya boyutu sınırları olmalı.
- ZIP path traversal, absolute path, symlink/hardlink, special device ve nested archive saldırıları reddedilmeli.
- Açılmış toplam boyut, dosya sayısı, tek dosya boyutu ve compression ratio sınırları olmalı.
- Dosyalar izole ve deployment'a özel geçici dizinde açılmalı.
- Manifest zorunlu olmalı.
- Manifest `job_id` ve `build_sha256` değerleri istekle birebir eşleşmeli.
- Paket checksum'u güvenilir biçimde yeniden hesaplanmalı.
- Geçici dosyalar başarı ve hata sonrasında temizlenmeli.
- Aynı artifact başka tenant/site deployment'ında yeniden kullanılamamalı.

Güvenlik limitleri configuration üzerinden yönetilmeli, güvenli varsayılanlara sahip olmalı ve test edilmelidir.

## 10. Cloudflare deployment

Hedef Cloudflare Workers Static Assets'tir; Pages varsayma.

Her deployment:

1. Yetkili Cloudflare connection ve deployment target kaydını yükler.
2. Lisans/site/plan ve eşzamanlılık limitlerini tekrar kontrol eder.
3. Artifact'i indirir ve doğrular.
4. Tenant/site için deterministik, çakışmayan Worker adı kullanır.
5. Tam snapshot'ı Workers Static Assets olarak yükler.
6. Gerekli Worker kodu ve asset binding'ini birlikte yayınlar.
7. Cloudflare'ın döndürdüğü deployment/version kimliğini kaydeder.
8. `workers.dev` veya seçilmiş custom domain üzerinden HTTP doğrulaması yapar.
9. Başarılı deployment'ı atomik biçimde aktif duruma getirir.
10. WordPress'e imzalı durum callback'i gönderir.

Statik pakette bulunan `_headers`, `_redirects`, `404.html` ve dil yapılandırması korunmalıdır. Mevcut WordPress exporter'ın kök dil yönlendirmesi için Worker-first `/` davranışıyla uyumluluk test edilmelidir.

Custom domain ekleme/silme açık kullanıcı seçimi ve sahiplik kontrolü gerektirir. Var olan DNS kayıtlarını sessizce ezme. Domain çakışmasında güvenli hata üret. İlk MVP'de otomatik custom domain yönetimi riskli bulunursa önce `workers.dev` deployment yapıp domain bağlamayı ayrı, onaylı bir faz olarak tasarla.

## 11. Durum makinesi ve callback

Deployment durumları en az şunları kapsamalıdır:

```text
queued -> downloading -> validating -> deploying -> verifying -> completed
   |           |             |             |            |
   `-----------`-------------`-------------`----------> failed
```

WordPress istemcisine mevcut sözleşmeyle yalnızca desteklediği durumlar gönderilir:

- `deploying`
- `completed`
- `failed`

Callback imzası:

```text
signature = hex(hmac_sha256(callback_secret, timestamp + "." + raw_request_body))
```

Header'lar:

```http
X-Wext-Timestamp: 1786785600
X-Wext-Signature: hex-signature
```

Kurallar:

- Callback secret siteye özeldir ve en az 32 rastgele byte gücündedir.
- Raw JSON body imzalanır; imza üretildikten sonra body değiştirilmez.
- Callback teslimatı timeout, exponential backoff ve jitter ile tekrar denenir.
- Başarılı callback idempotency bilgisi tutulur.
- Beş dakikalık WordPress doğrulama penceresi nedeniyle her retry için yeni timestamp ve imza üretilir.
- Job ID ve build SHA-256 her callback'te bulunur.
- Callback hatası başarılı Cloudflare deployment'ı geri almaz; `deployed_but_callback_pending` benzeri iç durumla izlenir.

## 12. Kuyruk, retry ve eşzamanlılık

- Aynı site için production deployment'ları sıraya alınmalıdır.
- Aynı tenantın plan limitleri uygulanmalıdır.
- Bir iş en fazla bir worker tarafından lease edilmelidir.
- Worker çökmesi halinde lease süresi dolunca iş güvenli biçimde devam edebilmelidir.
- Artifact indirme, Cloudflare deploy ve callback retry politikaları birbirinden ayrılmalıdır.
- Deterministik olmayan hatalar sınırlı sayıda tekrar denenmelidir.
- Kimlik doğrulama, checksum, tenant veya ZIP güvenlik hataları otomatik tekrar denenmemelidir.
- Dead-letter durumu ve operator için güvenli retry işlemi bulunmalıdır.
- Aynı job'ın tekrar teslimi çift deployment veya çapraz durum bozulması üretmemelidir.

## 13. Güvenlik zorunlulukları

OWASP ASVS yaklaşımını uygula. Özellikle:

- Tenant izolasyonu
- SSRF
- OAuth account linking / login CSRF
- Broken object-level authorization
- Mass assignment
- Replay saldırıları
- Queue poisoning
- ZIP bomb/path traversal
- Secret leakage
- Log injection
- Rate limiting ve abuse control
- Webhook replay ve sahte callback
- DNS rebinding
- Unsafe redirects
- Supply-chain ve dependency pinning

Zorunlu uygulamalar:

- Production'da fail-closed configuration.
- Güvenli HTTP başlıkları.
- Origin ve callback allowlist.
- API rate limitleri tenant, site, token ve IP bağlamında.
- Service tokenlar düz metin tutulmamalı; hash ile doğrulanmalı.
- Provider tokenlar authenticated encryption ile saklanmalı.
- Encryption key versioning ve rotation runbook'u.
- Secret redaction testleri.
- Audit log append-only davranışına yakın tasarlanmalı.
- Audit kayıtları secret veya tam token içermemeli.
- Admin/operator işlemleri ayrıca yetkilendirilmeli ve denetlenmeli.

## 14. Gözlemlenebilirlik

- Yapılandırılmış JSON logları
- Request/correlation ID
- Tenant/site/deployment için güvenli, kişisel olmayan tanımlayıcılar
- Queue depth, deployment duration, failure class, callback retry ve Cloudflare latency metrikleri
- `/health/live` ve `/health/ready`
- PostgreSQL, Redis ve worker readiness kontrolleri
- Secret içermeyen hata raporları
- Operator audit ekranı veya asgari salt-okunur API

Health endpoint'i database veya secret ayrıntılarını dışarı sızdırmamalıdır.

## 15. Dokploy ve Docker Compose

Production Compose en az şu servisleri içermelidir:

- `api`
- `worker`
- `postgres`
- `redis`

Kurallar:

- Yalnızca `api` Dokploy/Traefik üzerinden dışarı açılır.
- PostgreSQL ve Redis için host port publish edilmez.
- Containerlar mümkün olduğunca non-root çalışır.
- Read-only filesystem ve kısıtlı Linux capabilities uygulanır; geçici artifact alanı açıkça tanımlanır.
- Kalıcı veriler named volume üzerinde tutulur.
- Healthcheck ve graceful shutdown bulunur.
- Database migration ayrı ve kontrollü bir release adımıdır.
- Backup/restore runbook'u hazırlanır ve restore testi acceptance kapsamına alınır.
- OAuth, database, encryption ve operator secretları repoya yazılmaz.
- `.env.example` yalnızca sahte değerler ve açıklamalar içerir.
- Dokploy domain hedefi `deploy.wext.com` olarak hazırlanır; gerçek DNS/TLS değişikliği ayrı onay gerektirir.

## 16. API hata sözleşmesi

Tüm API hataları tutarlı JSON yapısı kullanmalıdır:

```json
{
  "error": {
    "code": "artifact_checksum_mismatch",
    "message": "Deployment package verification failed.",
    "request_id": "req_..."
  }
}
```

Müşteriye gösterilen mesajlar güvenli ve anlaşılır olmalıdır. İç exception, stack trace, SQL, dosya yolu veya provider token ayrıntısı response'a konmamalıdır. Makine tarafından işlenebilir hata kodları belgelenmelidir.

## 17. Test stratejisi

Asgari test kapsamı:

- Birim testleri
- PostgreSQL repository/constraint testleri
- Redis queue ve lease testleri
- OpenAPI contract testleri
- WordPress istemci fixture'larıyla sözleşme testleri
- OAuth mock server testleri
- Cloudflare API mock/fake testleri
- Callback HMAC testleri
- Timestamp/replay testleri
- Cross-tenant negatif testleri
- SSRF ve redirect negatif testleri
- ZIP traversal, symlink, bomb ve limit testleri
- Job idempotency ve duplicate-delivery testleri
- Worker crash/recovery testleri
- Token encryption/rotation testleri
- Log redaction testleri
- Docker Compose smoke testi
- Migration rollback/forward testi
- Backup restore testi

Mock testleri canlı Cloudflare veya canlı Dokploy kanıtı sayılmamalıdır. Production-ready beyanından önce ayrı staging acceptance yapılmalıdır.

## 18. Fazlar

### Faz 0 — Keşif ve sözleşme

- Kaynak eklenti sözleşmesini doğrula.
- Tehdit modelini yaz.
- Veri modelini ve tenant sınırlarını yaz.
- Lisans aktivasyonu ve `connect_ticket` uyumluluk farkını belgele.
- Cloudflare OAuth ve Workers Static Assets güncel resmi API yaklaşımını doğrula.
- ADR'leri hazırla.
- Kodlamadan önce planı kullanıcıya sun.

### Faz 1 — Güvenli temel

- Repo/scaffold
- Configuration validation
- PostgreSQL migrations
- Redis queue
- Tenant/site/license temeli
- Health endpointleri
- OpenAPI
- Docker Compose
- CI lint/typecheck/test/security kontrolleri

### Faz 2 — Lisans ve Cloudflare bağlantısı

- License activation/deactivation
- `connect_ticket`
- OAuth start/callback
- Token encryption/rotation
- Account/domain/target seçimi
- Disconnect/revoke
- WordPress compatibility fixtures

### Faz 3 — Deployment pipeline

- Deployment create/idempotency
- Güvenli artifact fetch
- ZIP/manifest validation
- Cloudflare Workers Static Assets deployment
- Live URL verification
- Signed WordPress callback
- Retry/dead-letter/operator görünürlüğü

### Faz 4 — Üretim hazırlığı

- Rate limits ve abuse controls
- Metrics/alerts/audit
- Backup/restore proof
- Dokploy staging
- Canlı Cloudflare OAuth testi
- Gerçek test domaininde Workers deployment
- Callback acceptance
- Tenant isolation security review
- Runbook ve rollback

### Faz 5 — Ticari entegrasyon

- Plan/entitlement yönetimi
- Seçilecek ödeme sağlayıcısı için adapter
- İmzalı ve idempotent billing webhook'ları
- Grace period, suspension ve reactivation politikası
- Operator/customer portal kapsamı

Ödeme sağlayıcısını kullanıcı seçmeden Stripe veya başka bir sağlayıcıyı zorunlu bağımlılık olarak ekleme.

## 19. MVP dışında kalanlar

Açık onay olmadan şunları MVP'ye ekleme:

- WordPress eklentisini bu repo içinde değiştirme
- Cloudflare dışı hosting sağlayıcıları
- E-posta pazarlama sistemi
- Gelişmiş müşteri portalı
- Otomatik faturalama sağlayıcısı seçimi
- Çok bölgeli active-active altyapı
- Artımlı/delta deployment
- WordPress içerik editörü veya CMS özellikleri
- Canlı production DNS değişikliği

## 20. Teslimatlar

Projede en az aşağıdaki çıktılar bulunmalıdır:

```text
README.md
AGENTS.md
.env.example
compose.yaml
Dockerfile
package.json
src/
tests/
migrations/
openapi/
  openapi.yaml
docs/
  ARCHITECTURE.md
  THREAT_MODEL.md
  PLUGIN_COMPATIBILITY.md
  DEPLOYMENT.md
  OPERATIONS.md
  BACKUP_RESTORE.md
  STAGING_ACCEPTANCE.md
  adr/
```

README; local kurulum, migration, test, worker çalıştırma ve Compose kullanımını içermelidir. Secret üretme örnekleri gerçek secret içermemelidir.

## 21. Definition of Done

Bir faz yalnızca kod yazıldığı için tamamlanmış sayılmaz. İlgili faz için:

- Kod, migration ve sözleşme birlikte tamamlanmış olmalı.
- Lint, typecheck ve testler geçmeli.
- Negatif güvenlik testleri bulunmalı.
- Tenant izolasyonu kanıtlanmalı.
- Dokümanlar mevcut davranışla eşleşmeli.
- Secretlar repoda veya test çıktısında bulunmamalı.
- Docker image ve Compose smoke testi geçmeli.
- Çalıştırılan komutlar ve sonuçlar raporlanmalı.
- Yerel/mock doğrulama ile canlı staging doğrulaması açıkça ayrılmalı.

Servis aşağıdaki canlı kanıtlar olmadan “production-ready” veya “uçtan uca tamamlandı” olarak sunulmamalıdır:

- Yapılandırılmış ve doğrulanmış Cloudflare OAuth uygulaması
- Gerçek OAuth bağlantı ve revoke testi
- Gerçek Workers Static Assets deployment
- Gerçek WordPress artifact indirme ve imzalı callback
- Dokploy staging health/TLS testi
- PostgreSQL ve Redis private-network kontrolü
- Backup restore testi
- Cross-tenant security acceptance
- Gözlemlenebilirlik ve hata alarmı kanıtı

## 22. Çalışma biçimi

1. Önce mevcut checkout, Git durumu ve kaynak sözleşmeyi doğrula.
2. Faz 0 çıktılarını hazırla.
3. Belirsiz ve ürünü değiştiren kararları açık seçeneklerle kullanıcıya sun.
4. Onaylanan fazı küçük ve doğrulanabilir dilimlerle uygula.
5. Her dilimde test et ve belgeyi güncel tut.
6. İlgisiz dosyaları veya kullanıcı değişikliklerini bozma.
7. Secret isteme, gösterme veya kaydetme; yalnızca nerede ve nasıl tanımlanacağını belirt.
8. Canlı deployment veya dış sistem değişikliği için ayrıca açık onay al.
9. Faz sonunda tamamlananlar, testler, kalan riskler ve canlı doğrulama sınırını kısa biçimde raporla.

İlk görev olarak yalnızca **Faz 0 — Keşif ve sözleşme** çalışmasını yap; mimari ve güvenlik kararlarını kullanıcıya sunmadan Faz 1 implementasyonuna geçme.
