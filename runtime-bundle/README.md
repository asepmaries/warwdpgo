# WARWDPGO Ubuntu 24 runtime bundle

Direktori ini berisi sumber builder dan installer repository paket lokal untuk
fast path production Ubuntu 24.04. Bundle memasang PHP CLI/cURL, CA certificate,
tar/coreutils, dan chrony tanpa mengakses mirror APT pada target.

Aplikasi (`war.php`, config, dan `install.sh`) tetap didistribusikan terpisah
agar update aplikasi tidak memerlukan download ulang runtime 35 MiB.

## Release production AMD64 v3

- Tag: `runtime-ubuntu24-amd64-v3`
- Asset: `runtime-ubuntu24-amd64-v3.tar.gz`
- Ukuran: 36,132,390 byte (34.46 MiB)
- SHA-256: `1bf80c485efe1b929936bff4c3ae359c2bc45119b18788d5b8a3cbe015d714b4`
- Dependency closure: 92 paket `.deb`

Asset binary berada di `dist/` saat build dan sengaja diabaikan Git. Binary
diunggah sebagai GitHub Release asset, bukan dimasukkan ke riwayat repository.
Versi yang sudah dipublikasikan tidak boleh dibangun ulang atau ditimpa; setiap
perubahan wajib memakai versi dan tag release baru.

Bundle v3 adalah artefak hasil pengujian lintas provider. Installer utama
menulis marker production `/var/lib/warwdpgo/runtime-ready` setelah memverifikasi
hasilnya. Marker kompatibilitas lama yang ada di dalam artefak v3 tidak dipakai
sebagai satu-satunya bukti kesehatan.

## Build versi berikutnya

Jalankan pada builder Ubuntu 24.04 sebagai root. Gunakan nomor versi baru,
misalnya `v4`; jangan membangun ulang `v3`.

```bash
bash build-ubuntu24-runtime.sh v4
```

Hasil berada di `dist/` bersama checksum SHA-256. Builder mendukung `amd64` dan
`arm64`, tetapi masing-masing arsitektur menghasilkan asset terpisah dan wajib
diuji pada VPS fresh sebelum dipublikasikan.

## Instalasi manual/offline

Untuk diagnosis atau instalasi tanpa download release:

```bash
sha="$(awk '{print $1}' runtime-ubuntu24-amd64-v3.tar.gz.sha256)"
bash bootstrap-runtime-bundle.sh \
  --bundle-file runtime-ubuntu24-amd64-v3.tar.gz \
  --sha256 "$sha"
```

Pada production, `install.sh` menangani download HTTPS, batas waktu, ukuran,
SHA-256, validasi path archive, instalasi repository lokal, marker, dan fast
path idempotent.

## Hasil pengujian v3

- Builder dan dua target lintas provider: Ubuntu 24.04 AMD64.
- Fresh install pada target 2 GiB: 43.194 detik.
- Fresh install pada target 961 MiB: 54.332 detik.
- Total transfer dan install terukur sekitar 48-67 detik.
- PHP 8.3.6, cURL, JSON, OpenSSL, chrony, dan clock gate terverifikasi.
- Log instalasi tidak memuat URL HTTP/HTTPS; APT target hanya membaca `file:`.
- `acquisitionport 123` memungkinkan NTP pada firewall stateless.
- OCI memakai layanan NTP lokal `169.254.169.254` secara otomatis agar strict
  error bound tidak bergantung pada delay/dispersion sumber NTP publik.
- Pada uji OCI, error bound turun dari 59.238 ms (NTP publik) menjadi 0.705 ms;
  mode `--clock-only` lulus strict gate dalam 9.049 detik.
- Clock gate adaptive membutuhkan maksimal tiga ronde burst pendek.
- Smoke test entrypoint production dengan bundle lokal lulus dalam 11.881 detik
  pada VPS yang runtime-nya sudah terpasang; fast path berikutnya 1.455 detik.
- Download dan install melalui release HTTPS publik tanpa token lulus dalam
  15.317 detik pada VPS yang runtime-nya sudah terpasang.
- Full install aplikasi dari `main` setelah runtime sehat lulus dalam 2.265
  detik, termasuk dua salinan aplikasi dan strict clock gate.
