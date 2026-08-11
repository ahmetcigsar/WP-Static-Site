# Ragnus Static Publisher

Farklı WordPress sitelerinde kullanılmak üzere geliştirilen, Cloudflare Workers Static Assets odaklı statik export eklentisi.

## MVP özellikleri

- Aynı origin HTML, CSS, JS, görsel ve font kaynaklarını tarama
- WordPress origin adresini canlı statik domain ile değiştirme
- Asset adreslerini hem `pages.dev` hem özel domainde çalışan kök-relative yollara dönüştürme
- Cloudflare `_headers` ve `_redirects` üretimi
- ZIP ve SHA-256 manifest üretimi
- Sol ana menüde doküman ikonlu Static Publisher yönetim ekranı
- Main, Files, Activity Log, Settings, Diagnostics ve About sekmelerine ayrılmış yönetim görünümü
- Main sekmesinde son başarılı static oluşturma zamanı ve yeşil ilerleme/tamamlanma göstergesi
- Diagnostics sekmesinde PHP, Basic Auth, php-xml, cURL, site URL erişimi, kalıcı bağlantılar, indexlenebilirlik, önbellek ve WP-Cron kontrolleri
- About sekmesinde sürüm, destek e-postası ve eklenti web sitesi bilgileri
- Modern yönetim arayüzü, kart tabanlı içerik alanları, yenilenmiş sekmeler ve butonlar
- Activity Log kayıtlarında arama ve ortalanmış modern sayfalama
- Activity Log kaynak HTTP durum kodları ve WordPress ayarlarına bağlı tarih/saat biçimi
- Yalnızca son exporta ait, veritabanı dışında tutulan ve sayfa başına 50 kayıt gösteren Activity Log
- Activity Log içinde kaynak WordPress URL'sini ve üretilen statik yolu ayrı sütunlarda gösterme
- Yönetim ekranından son başarılı ZIP'i indirme
- Varsayılan 5 arşiv için ayarlanabilir ZIP saklama sınırı
- İş kimliği, URL sayısı ve oluşturma zamanını gösteren ZIP arşiv tablosu
- En son ZIP'i koruyarak eski arşivleri elle temizleme
- ZIP arşivlerini tek tek veya toplu olarak indirme ve silme
- `wp ragnus-static export` WP-CLI komutu
- Application Password korumalı export/status/artifact REST uçları
- CI için yönetici yetkisi gerektirmeyen `Static Publisher Deploy` kullanıcı rolü
- İçerik güncellemelerini 60 saniye birleştiren otomatik export ve isteğe bağlı deployment webhook'u
- Cloudflare Workers Static Assets için örnek GitHub Actions workflow'u

## Kurulum

`ragnus-static-publisher` klasörünü WordPress'in `wp-content/plugins/` dizinine kopyalayın ve eklentiyi etkinleştirin. Ardından sol ana menüdeki **Static Publisher** sayfasından canlı domaini ve URL sınırını yapılandırın.

Sunucuda PHP DOM ve Zip eklentileri bulunmalıdır. Uzun export işleri için gerçek sistem cron'u ile `wp-cron.php` çalıştırılması önerilir.

## WP-CLI

```bash
wp ragnus-static export
wp ragnus-static export --format=json
```

## CI secrets

- `WP_ORIGIN`: WordPress origin adresi, örneğin `https://cms.example.com`
- `WP_USER`: `Static Publisher Deploy` rolündeki otomasyon kullanıcısı
- `WP_APP_PASSWORD`: Kullanıcının Application Password değeri
- `CLOUDFLARE_API_TOKEN`: `wp-statik-deneme` Worker deployment'ı için en düşük gerekli yetkilere sahip Cloudflare API token
- `CLOUDFLARE_ACCOUNT_ID`: Cloudflare hesap kimliği

Örnek workflow `.github/workflows/deploy-example.yml` içindedir. Worker adı ve asset ayarları `wrangler.jsonc` içinde tutulur; Custom Domain dashboard tarafından yönetilmeye devam eder. CI kullanıcısına yönetici rolü vermeyin; eklentinin oluşturduğu `Static Publisher Deploy` rolünü seçin ve bu kullanıcıya ayrı bir Application Password üretin.

Tam otomatik akış için eklenti ayarlarında deployment webhook alanını `https://api.github.com/repos/SAHIP/REPO/dispatches` olarak girin. Fine-grained bearer token yalnızca ilgili repository için `Contents: write` yetkisiyle oluşturulmalıdır. Workflow dosyası repository'nin varsayılan branch'inde bulunmalıdır. Cloudflare token hiçbir zaman WordPress'e girilmez.

## Geliştirme doğrulaması

```bash
find ragnus-static-publisher -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n scripts/*.sh
./scripts/package-plugin.sh
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-smoke.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-activity-log.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-archives.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-export.php
```

Mimari ve sınırlar için `docs/ARCHITECTURE.md` dosyasına bakın.
