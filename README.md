# Ragnus Static Publisher

Farklı WordPress sitelerinde kullanılmak üzere geliştirilen, Cloudflare Pages odaklı statik export eklentisi.

## MVP özellikleri

- Aynı origin HTML, CSS, JS, görsel ve font kaynaklarını tarama
- WordPress origin adresini canlı statik domain ile değiştirme
- Cloudflare `_headers` ve `_redirects` üretimi
- ZIP ve SHA-256 manifest üretimi
- Araçlar > Static Publisher yönetim ekranı
- `wp ragnus-static export` WP-CLI komutu
- Application Password korumalı export/status/artifact REST uçları
- İçerik güncellemelerini 60 saniye birleştiren otomatik export ve isteğe bağlı deployment webhook'u
- Cloudflare Pages için örnek GitHub Actions workflow'u

## Kurulum

`ragnus-static-publisher` klasörünü WordPress'in `wp-content/plugins/` dizinine kopyalayın ve eklentiyi etkinleştirin. Ardından **Araçlar > Static Publisher** altında canlı domaini ve URL sınırını yapılandırın.

Sunucuda PHP DOM ve Zip eklentileri bulunmalıdır. Uzun export işleri için gerçek sistem cron'u ile `wp-cron.php` çalıştırılması önerilir.

## WP-CLI

```bash
wp ragnus-static export
wp ragnus-static export --format=json
```

## CI secrets

- `WP_ORIGIN`: WordPress origin adresi, örneğin `https://cms.example.com`
- `WP_USER`: Sadece otomasyon için açılmış WordPress kullanıcı adı
- `WP_APP_PASSWORD`: Kullanıcının Application Password değeri
- `CLOUDFLARE_API_TOKEN`: Pages projesiyle sınırlı Cloudflare API token
- `CLOUDFLARE_ACCOUNT_ID`: Cloudflare hesap kimliği
- Repository variable `CLOUDFLARE_PAGES_PROJECT`: Pages proje adı

Örnek workflow `.github/workflows/deploy-example.yml` içindedir. Önce dosyayı hedef repository'ye taşıyın ve proje değişkenlerini tanımlayın.

Tam otomatik akış için eklenti ayarlarında deployment webhook alanını `https://api.github.com/repos/SAHIP/REPO/dispatches` olarak girin. Fine-grained bearer token yalnızca ilgili repository için `Contents: write` yetkisiyle oluşturulmalıdır. Workflow dosyası repository'nin varsayılan branch'inde bulunmalıdır. Cloudflare token hiçbir zaman WordPress'e girilmez.

## Geliştirme doğrulaması

```bash
find ragnus-static-publisher -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n scripts/*.sh
./scripts/package-plugin.sh
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-smoke.php
npx --yes @wp-playground/cli@latest php --php=8.1 --wp=latest --auto-mount=ragnus-static-publisher --mount=.:/workspace -- /workspace/tests/playground-export.php
```

Mimari ve sınırlar için `docs/ARCHITECTURE.md` dosyasına bakın.
