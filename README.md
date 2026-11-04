# PKM Backend

## API register siswa dan guru

`POST /api/register` menyimpan akun login ke tabel `users`, termasuk `sn` dan
`type` yang dikirim. Jika `type` adalah `siswa`, data juga masuk ke tabel
`siswa`; jika `guru`, data juga masuk ke tabel `guru`.

Kirim JSON dengan `Content-Type: application/json`, atau form-data dengan
field yang sama:

```json
{
  "nama": "Budi",
  "username": "budi",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "sn": "001234",
  "type": "siswa",
  "id_kelas": "1"
}
```

Untuk registrasi guru, ganti `type` menjadi `guru` dan hapus `id_kelas`.
Registrasi siswa dan guru tetap publik. Sesi siswa yang sudah login tidak boleh
membuat akun lain; request register pada sesi siswa mendapat HTTP 403. Guru yang
sudah login boleh mendaftarkan akun tanpa mengganti identitas sesinya sendiri.
Registrasi siswa wajib memilih kelas yang tersedia melalui `GET /api/data_kelas`.
Kirim `id_kelas` sebagai ID bilangan bulat positif atau string angka; backend
memvalidasi bahwa kelas tersebut terdaftar dan menyimpannya di `siswa.id_kelas`.
Kelas tidak disimpan pada akun guru. Field identitas dan password wajib berupa
string. Username dan SN harus belum terdaftar. SN maksimal 50 karakter sesuai
kolom `users.sn`, minimal satu karakter, tanpa karakter kontrol, dan tidak boleh sudah dipakai pada
tabel `users`, `siswa`, atau `guru`. Angka nol di depan SN tetap disimpan.
Spasi di awal/akhir nama, username, SN, dan type dibuang. Password harus
sepanjang 8 sampai 72 byte dan konfirmasi harus cocok.

Nama disimpan sebagai `siswa.nama_siswa` atau `guru.nama`. Siswa baru mendapat
`status: "ACTIVE"` (guru juga mendapat `status: "ACTIVE"`). SN, type, dan waktu pembuatan/perubahan (WIB) sama pada
akun dan profil. Data langsung tersedia melalui `/api/data_siswa` atau
`/api/data_guru`.

`users.id_user` memakai `VARCHAR(36)`. Server membuat UUID v4 untuk setiap
akun baru dan menyimpan ID yang sama pada `siswa.id_user` atau `guru.id_user`.
Primary key tabel guru adalah `id_user`; siswa tetap memakai `id_siswa`
angka otomatis. Jangan mengirim `id_user` dari frontend. ID akun lama yang
valid tetap dapat dipakai untuk login; UUID dipertahankan sebagai string
pada respons dan sesi, bukan diubah menjadi angka.
Detail guru dapat dibaca melalui `/api/data_guru?id_user=<UUID>`. Parameter
`id_guru` tetap diterima sebagai alias untuk ID numerik lama.

Jika sebelumnya muncul `Duplicate entry '' for key 'users.PRIMARY'`, model
kini sudah menghasilkan UUID sebelum INSERT. Jalankan `php spark migrate`
untuk memberi UUID pada akun lama yang ID-nya kosong. Migrasi mempertahankan
username dan password serta tidak mengubah UUID yang sudah ada. Jika ID
kosong masih direferensikan profil, migrasi berhenti tanpa mengubahnya agar
relasi tidak terputus; data tersebut perlu diperbaiki bersama relasinya.

Migrasi `AddStudentClass` menambahkan relasi kelas pada tabel siswa. Jalankan
`php spark migrate` sebelum menggunakan field kelas pada form register.
Siswa lama tetap tersimpan dengan `id_kelas: null`; siswa baru memilih kelas
saat mendaftar. Jika kelas dihapus, profil siswanya tetap ada dan relasi kelas
menjadi null.

Penyimpanan akun dan profil memakai satu transaksi: jika salah satu gagal,
seluruh penyimpanan dibatalkan. Respons sukses adalah HTTP 201 dengan
`{"status":201,"message":"Registrasi berhasil","redirect":"/login"}`.
Frontend mengarahkan pengguna ke `/login` setelah respons 201; registrasi
tidak otomatis membuat sesi login. Validasi gagal mendapat
HTTP 422 beserta `errors` per field, JSON tidak valid mendapat 400, dan
kegagalan penyimpanan mendapat 500. Alur ini berlaku untuk registrasi baru;
data akun lama tidak otomatis disalin ke tabel siswa/guru.

## API login dan akses dashboard

`POST /api/login` menerima JSON, form-urlencoded, atau form-data:

```json
{
  "username": "budi",
  "password": "Password123!",
  "type": "siswa"
}
```

Username dicocokkan dengan akun di `users` dan password diverifikasi terhadap
hash hasil registrasi. Spasi di awal/akhir username dibuang; spasi pada password
tetap dipertahankan. `type` wajib dikirim sesuai pilihan login, yaitu `siswa` atau
`guru`, dan harus sama dengan role akun di database. Role dari request tidak
dapat mengganti role akun. Respons sukses memakai HTTP 200:

```json
{
  "status": 200,
  "message": "Login berhasil",
  "redirect": "/dashboard",
  "data": {
    "id_user": "47c3f276-a10a-4e3e-a6cd-fb14a305fdeb",
    "username": "budi",
    "nama": "Budi",
    "type": "siswa"
  }
}
```

| Kondisi | HTTP | Pesan |
| --- | --- | --- |
| Username belum terdaftar, termasuk jika kedua kredensial salah | 400 | `User tidak ditemukan` |
| Username terdaftar tetapi password salah | 400 | `Username/password salah` |
| Username/password kosong atau bukan string | 400 | `Username dan password wajib diisi sebagai teks.` |
| Type kosong, bukan string, atau selain siswa/guru | 422 | `Pilih jenis akun siswa atau guru.` |
| Password benar tetapi pilihan jenis akun berbeda dengan akun terdaftar | 403 | `Jenis akun tidak sesuai dengan akun yang terdaftar.` |
| JSON rusak atau bukan objek | 400 | `Body JSON harus berupa objek yang valid.` |
| 5 kali gagal (username/password salah) dari IP yang sama dalam 5 menit | 429 | `Terlalu banyak percobaan login gagal. Coba lagi nanti.` (header `Retry-After`) |
| Gangguan server/database | 500 | `Terjadi kesalahan pada server` |

Batas percobaan gagal dipakai bersama oleh `/api/login` dan `/api/change_password`
untuk username yang sama, dan direset setelah login/ganti password berhasil.
Setelah password diganti atau direset, semua sesi lain milik akun itu otomatis
tidak berlaku (HTTP 401); sesi yang dipakai untuk mengganti password tetap login.

Check-out (`POST /api/absensi/check-out`) hanya bisa dilakukan pada tanggal
absensi yang sama; absensi hari sebelumnya yang belum check-out mendapat 409.
Username/nama tidak boleh berisi karakter kontrol (baris baru, tab, null).
Parameter ID numerik pada query (`id_siswa`, `id_guru`, `id_kelas`, `id_jurusan`,
`page`) hanya menerima digit tanpa spasi, tanda `+`, atau nol di depan.

Login berhasil membuat sesi pada server dan mengganti ID sesi. Percobaan
login yang gagal menghapus status autentikasi sebelumnya. `GET /api/me` dan
`GET /api/dashboard` memerlukan cookie sesi login yang valid. Filter auth
mencocokkan `id_user` dari sesi dengan tabel `users` pada setiap request dan
memperbarui nama dan username dari database. Akun yang sudah dihapus, role yang
tidak dikenal, atau perubahan role setelah login membatalkan sesi lama. Pengguna
harus login lagi dengan role terbaru. Tanpa sesi valid, respons HTTP 401:

```json
{
  "status": 401,
  "message": "Silakan login terlebih dahulu",
  "redirect": "/login"
}
```

`GET /api/me` memvalidasi sesi dan mengembalikan identitas pengguna yang sedang
login, sehingga frontend tidak perlu memakai daftar semua user untuk
menentukan siapa yang login:

```json
{
  "status": 200,
  "message": "User terautentikasi",
  "data": {
    "id_user": "47c3f276-a10a-4e3e-a6cd-fb14a305fdeb",
    "username": "budi",
    "nama": "Budi",
    "type": "siswa"
  }
}
```

`GET /api/logout` menghapus autentikasi dan menghancurkan sesi. Respons 200
menyertakan `redirect: "/login"`, sehingga frontend dapat kembali ke halaman
login. Respons login, validasi sesi, dan dashboard tidak menyertakan hash
password atau SN pemulihan akun. Gangguan database saat memvalidasi sesi
menghasilkan HTTP 500, bukan dianggap sebagai login berhasil.

Untuk masuk dengan akun lain, panggil `/api/logout`, hapus identitas user
yang tersimpan di state frontend, lalu arahkan ke `/login`. Login berikutnya
membuat sesi akun yang dipilih dan `/api/me` mengembalikan identitas akun
tersebut. Login pada sesi aktif juga mengganti identitas sebelumnya; login
yang gagal menghapus autentikasi lama.

Frontend harus mengirim cookie pada request login, validasi sesi, dashboard, dan logout:
gunakan `credentials: "include"` pada `fetch` atau `withCredentials: true`
pada Axios. CORS mengizinkan cookie dari origin yang sudah terdaftar,
`http://localhost:5173` dan `http://localhost:5174`. Gunakan hostname yang sama (`localhost`) untuk backend
dan frontend saat pengembangan supaya cookie sesi ikut terkirim.

Alur routing frontend adalah registrasi berhasil (201) → `/login`, lalu login
berhasil (200) → `/dashboard`. Frontend dapat memanggil
`navigate(response.redirect)` setelah respons sukses. `/login` dan `/dashboard`
merupakan halaman frontend; endpoint backend tetap memakai awalan `/api`.
Field `redirect` sendiri tidak memindahkan halaman browser.

Saat membuka atau memuat ulang halaman yang memerlukan login, frontend perlu
memvalidasi sesi melalui `/api/me` dengan cookie. Contoh route guard (variabel
`API_URL` berisi alamat backend; `navigate` berasal dari router frontend):

```js
async function validateUser() {
  const response = await fetch(`${API_URL}/api/me`, {
    credentials: "include",
  });
  const result = await response.json();

  if (response.status === 401) {
    navigate(result.redirect, { replace: true });
    return null;
  }
  if (!response.ok) {
    throw new Error(result.message);
  }

  return result.data;
}
```

Jalankan pemeriksaan ini sebelum menampilkan halaman dashboard. Jika mendapat
500 atau terjadi gangguan jaringan, tampilkan kegagalan pemeriksaan dan beri
kesempatan mencoba lagi. Validasi tetap dilakukan server; data user yang
disimpan di localStorage bukan bukti bahwa sesi login masih valid.

## Izin API berdasarkan role

Filter `ApiAccessFilter` melindungi seluruh `api/*`. Tanpa sesi, endpoint data
pribadi dan absensi mengembalikan 401. Siswa mendapat 403 untuk operasi tulis
data sekolah (semua method selain GET/HEAD), jadi siswa hanya bisa melihat;
role pada payload/query tidak memberi izin tambahan.

| Endpoint | Siswa | Guru |
| --- | --- | --- |
| `GET /api/me` | Identitas sendiri | Identitas sendiri |
| `GET /api/dashboard` | Akun sendiri | Daftar akun tanpa password/SN |
| `GET /api/data_siswa` | Seluruh siswa (lihat saja), tanpa SN | Seluruh siswa |
| `GET /api/data_guru` | Seluruh guru (lihat saja), tanpa SN | Seluruh guru |
| `GET /api/kelas`, `/api/kelas/{id}` | Seluruh kelas (lihat saja) | Seluruh kelas |
| `POST`/`PUT`/`PATCH`/`DELETE /api/kelas` | 403 | Diizinkan |
| `GET /api/absensi` dan `/api/absensi/riwayat` | Absensi sendiri, tanpa SN | Seluruh absensi |
| `POST /api/absensi`, `/tap`, `/check-in`, `/check-out` | 403 | Diizinkan |
| `POST /api/register` pada sesi aktif | 403 | Diizinkan |

Detail absensi milik siswa lain mendapat 404. Filter daftar dan jumlah
riwayat juga dibatasi ke siswa yang login. Daftar siswa/guru kosong mendapat
200 dengan `data: []`. Pembaca RFID perlu memakai sesi guru pada aplikasi
penghubung; SN saja tidak lagi cukup untuk menulis absensi.

Endpoint autentikasi (`login`, `logout`, `change_password`, `forgot_password`)
dan daftar kelas untuk register tetap tersedia. Ganti/reset password tetap
memerlukan kredensial akun yang diubah. Registrasi publik guru diizinkan
sesuai kebijakan aplikasi, sehingga siapa pun yang mendaftar sebagai guru
mendapat akses guru setelah login.

Frontend aktif `../PKM1` memakai profil hasil `/api/me` untuk membatasi tombol
tambah/edit, navigasi guru dan scan, serta label role. Siswa dapat melihat
profil, kelas, dan riwayatnya. Opsi login yang tersedia adalah siswa dan guru.

## API forgot password berdasarkan SN

`PATCH /api/forgot_password` mereset password dengan mencocokkan pasangan
`username` dan `sn` yang tersimpan di tabel `users` saat registrasi. Password
lama, email, dan nomor HP tidak diperlukan.

Kirim JSON dengan `Content-Type: application/json`:

```json
{
  "username": "user_terdaftar",
  "sn": "001234",
  "new_password": "PasswordBaru123!"
}
```

SN wajib dikirim sebagai string agar angka nol di awal tetap dipertahankan.
SN dicocokkan persis, termasuk huruf besar/kecil, dengan data akun tersebut;
SN milik username lain tidak dapat dipakai. SN maksimal 100 karakter dan
tidak boleh mengandung karakter kontrol. Spasi di awal/akhir input username
dan SN dibuang. Akun tanpa SN terdaftar tidak dapat menggunakan alur ini.

Pasangan username/SN yang tidak sesuai mendapat HTTP 401 dengan pesan yang
sama, termasuk username yang belum terdaftar. Pembatasan percobaan memakai
cache aplikasi: kapasitas 5 percobaan per akun, terisi ulang selama 300 detik,
serta 20 percobaan per IP, terisi ulang selama 60 detik. Saat batas habis,
respons HTTP 429 menyertakan header `Retry-After` dalam detik.

SN berfungsi sebagai kredensial pemulihan pada alur ini. Orang yang mengetahui
username dan SN dapat mereset password akun. Saat SN juga dipakai sebagai UID
RFID dan ditampilkan pada endpoint data siswa/absensi, pengetahuan SN tidak
membuktikan kepemilikan kartu; akses ke data SN perlu dibatasi sebelum alur ini
digunakan untuk akun yang membutuhkan perlindungan kuat. Pembatasan percobaan
tidak melindungi akun jika pasangan username/SN sudah diketahui orang lain.

## API ganti password

`PATCH /api/change_password` tetap mengubah password dengan memverifikasi
password lama:

```json
{
  "username": "user_terdaftar",
  "password": "PasswordLama123!",
  "new_password": "PasswordBaru123!"
}
```

Kedua endpoint juga menerima body `application/x-www-form-urlencoded` dengan
field yang sama. Semua field wajib berupa string; parameter URL tidak digunakan
sebagai kredensial. Password baru harus sepanjang 8 sampai 72 byte, tanpa
karakter null. Password tidak dipangkas spasinya.

Respons berhasil kedua alur adalah HTTP 200 dengan
`{"status":200,"message":"Password berhasil diubah."}`. Input tidak valid
mendapat 400 dan kegagalan penyimpanan 500. Pada `/api/change_password`,
password lama salah mendapat 401 dan akun belum terdaftar 404. Hanya hash
password dan `update_date` (WIB) milik akun tersebut yang diperbarui berdasarkan
`id_user`. Tidak perlu membuat tabel `forgot_password`; model `M_forgot`
mengikuti model `M_users`.

## API riwayat absensi berdasarkan kelas

Pilihan kelas pada halaman riwayat dibaca dari `GET /api/data_kelas`.
`GET /api/absensi/riwayat` menampilkan siswa yang sudah memiliki catatan
absensi pada tanggal yang dipilih, beserta nama siswa dan nama kelasnya.
Contoh: `/api/absensi/riwayat?id_kelas=1&tanggal=2026-10-21&page=1`.

Parameter `id_kelas` boleh dihilangkan untuk semua kelas. `tanggal` memakai
format `YYYY-MM-DD` dan secara default adalah tanggal WIB hari ini. `page`
dimulai dari 1; tiap halaman berisi maksimal 25 catatan. Respons memuat
`data: []` dan `meta` dengan `current_page`, `last_page`, `per_page`, `total`,
`from`, dan `to`. Daftar kosong tetap mendapat HTTP 200, filter tidak valid
422 beserta `errors` per field, dan kegagalan database mendapat JSON 500.

Setiap baris menyertakan `id_absensi`, `id_siswa`, `nama_siswa`, `id_kelas`,
`nama_kelas`, `tanggal`, `check_in`, `check_out`, dan `status_kehadiran`.
Catatan check-in ditampilkan sebagai `hadir`; siswa tanpa catatan pada
tanggal tersebut tidak dimasukkan ke riwayat. Nama kelas dibaca melalui
relasi `siswa.id_kelas` ke tabel `kelas`. Profil lama yang belum memiliki
kelas tetap ditampilkan, dengan `nama_kelas: null`; frontend menampilkan
`Belum ada kelas` sampai kelas profil siswa diisi. Password dan SN tidak
disertakan pada respons riwayat.

## API absensi siswa

Jalankan `php spark migrate` untuk menyiapkan tabel `absensi`. Tabel memakai
`id_absensi CHAR(36)` yang diisi UUID oleh server, `id_user VARCHAR(36)` sebagai
foreign key ke `users.id_user`, dan `sn VARCHAR(50)`. Profil siswa dihubungkan
melalui `siswa.id_user`; `id_siswa` tetap dapat dikirim oleh client sebagai ID profil.
Model yang digunakan adalah `app/Models/M_absensi.php`.

Untuk tabel yang sudah memakai struktur tersebut, migrasi mempertahankan data
dan menghapus unique index pada `sn` saja (`absensi_unique`), lalu memakai index
biasa pada `sn`. `UNIQUE(id_user, tanggal)` tetap dipertahankan. `UNIQUE(sn)`
tidak dapat dipakai karena kartu yang sama harus bisa absen pada hari berikutnya.
Migrasi ini tidak mengonversi tabel lama yang masih memakai `absensi.id_siswa`.

| Method | Endpoint | Fungsi / input |
| --- | --- | --- |
| GET | `/api/absensi` | Daftar absensi; filter opsional `id_user`, `id_siswa`, `tanggal=YYYY-MM-DD`, `id_absensi` |
| GET | `/api/absensi/{id_absensi}` | Detail absensi |
| POST | `/api/absensi/tap` | Tap kartu RFID: `{"sn": "001234"}`, opsional `"jenis_absensi": "masuk"` / `"pulang"` |
| POST | `/api/absensi` | Tap RFID jika mengirim `sn`; check-in manual jika mengirim `id_user` atau `id_siswa` |
| POST | `/api/absensi/check-in` | Check-in manual: `{"id_user": "47c3f276-a10a-4e3e-a6cd-fb14a305fdeb"}` atau `{"id_siswa": 1}` |
| POST | `/api/absensi/check-out` | Check-out: `{"id_absensi": "e3b1387c-f91b-4189-980e-a37b256bfe4a"}` |

POST menerima JSON (`Content-Type: application/json`), form-urlencoded, atau
multipart form-data. Untuk RFID, simpan UID kartu di kolom `siswa.sn` dan
kirim UID hasil pembacaan reader sebagai string `sn` ke `/api/absensi/tap`.
Contoh: `{"sn": "001234"}`. Angka nol di awal dipertahankan; gunakan format
UID yang sama seperti data kartu tersimpan. Spasi/newline di awal/akhir hasil
scan dibuang, sedangkan karakter kontrol di dalam UID ditolak. Panjang maksimal
UID adalah 50 karakter. Tidak perlu mengirim `id_siswa` dari reader.

Tap pertama pada tanggal WIB hari ini mencatat check-in (HTTP 201,
`action: "check_in"`). Tap selanjutnya mencatat check-out (HTTP 200,
`action: "check_out"`). Tap dalam 10 detik sejak check-in ditolak dengan
HTTP 409 agar pembacaan berulang tidak langsung mencatat kepulangan. Setelah
check-out, tap tambahan pada tanggal yang sama ditolak. Kartu tidak terdaftar
mendapat 404; UID yang terpasang pada lebih dari satu siswa mendapat 409 tanpa
memilih siswa secara acak. Pada hari berikutnya alur dimulai lagi dari check-in;
catatan hari sebelumnya tidak diubah.

Jika `jenis_absensi` dikirim (dipakai halaman Absensi frontend), backend mengikuti
mode yang dipilih petugas, bukan urutan tap:

| Mode | Belum absen hari ini | Sudah masuk, belum pulang | Sudah pulang |
| --- | --- | --- | --- |
| `masuk` | Check-in (201) | 409 `<nama> sudah absen masuk hari ini.` | 409 |
| `pulang` | 409 `<nama> belum absen masuk hari ini.` (tidak membuat absensi) | Check-out (200), tanpa jeda 10 detik | 409 `<nama> sudah absen pulang hari ini.` |

Nilai selain `masuk`/`pulang` mendapat 422 dengan `errors.jenis_absensi`.
Setiap kartu diproses sendiri-sendiri, sehingga setelah siswa A tap, siswa B
dan seterusnya bisa langsung tap tanpa menunggu.

Halaman Absensi frontend tidak memakai kolom input: reader RFID yang bekerja
sebagai keyboard USB langsung dibaca dari ketikan cepat + Enter, lalu dikirim ke
`POST /api/absensi`. Reader lain harus mengirim request HTTP tersebut ke backend,
melalui firmware reader atau aplikasi penghubung.
Untuk endpoint manual, gunakan `id_user` akun siswa atau `id_siswa` dari
`/api/data_siswa`, lalu simpan UUID `data.id_absensi` dari respons check-in
untuk check-out. Siswa harus memiliki relasi akun dan SN yang valid.

Waktu check-in/check-out, `tanggal`, `created_date`, dan `update_date` diisi
server dalam zona waktu `Asia/Jakarta` (WIB). Nilai waktu dari client diabaikan.
Satu siswa hanya dapat check-in sekali per tanggal, termasuk setelah check-out;
unique key database juga mencegah duplikasi saat request bersamaan. Check-out
dilakukan untuk catatan check-in yang dipilih dan boleh melewati tengah malam.

Respons mengikuti bentuk `{"status": 200, "message": "...", "data": ...}`.
Check-in berhasil memakai HTTP 201, baca/check-out 200, JSON rusak 400,
data tidak ditemukan 404, absensi ganda 409, dan ID/filter tidak valid 422.
Daftar absensi kosong mengembalikan `data: []`. Data absensi menyertakan
`id_user`, `id_siswa`, `nama_siswa`, serta `sn` yang disimpan saat check-in.

Kolom model siswa/guru telah disesuaikan dengan database: siswa memakai
`nama_siswa` dan `status`, guru memakai `nama`, keduanya memakai `update_date`.
`/api/data_siswa` membaca tabel `siswa`; `/api/data_kelas?id_jurusan=...`
menerapkan filter jurusan. Profil siswa kini menyimpan relasi `id_kelas`;
`GET /api/absensi` belum menyediakan filter kelas; filter tersebut tersedia
melalui `/api/absensi/riwayat`. Endpoint mengikuti konfigurasi
izin pada tabel di atas. Endpoint data siswa/guru dan absensi memerlukan
sesi login; daftar kelas tetap publik untuk keperluan registrasi.

Jalankan pengujian dengan `php vendor/phpunit/phpunit/phpunit --no-coverage`.
Tes endpoint memakai database SQLite di memori yang terpisah dari database aplikasi.
Pengujian tambahan terhadap skema MySQL dapat dijalankan dari PowerShell:

```powershell
$env:TEST_MYSQL_REGISTRATION = "1"
php vendor/phpunit/phpunit/phpunit --no-coverage tests/feature/RegistrationMySQLTest.php
Remove-Item Env:TEST_MYSQL_REGISTRATION
```

Tes tersebut menyalin skema `users`, `siswa`, `guru`, dan `kelas` ke tabel sementara
dengan prefix acak pada koneksi terpisah. Data akun aplikasi tidak dipakai
atau diubah. Tabel sementara hilang setelah koneksi pengujian ditutup.

## CodeIgniter 4 Application Starter

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library

## API data kelas

Resource `/api/kelas` memakai tabel `kelas` (`id_kelas`, `nama_kelas`, `id_jurusan`,
`tahun_ajaran`, `status`). Semua endpoint wajib login; siswa hanya bisa membaca,
guru bisa membaca dan mengubah. `GET /api/data_kelas` tetap publik untuk form register.

| Method & path | Keterangan | Sukses |
| --- | --- | --- |
| `GET /api/kelas?page=&search=&id_jurusan=&status=` | Daftar 25 per halaman, urut nama, beserta `meta` paginasi | 200 |
| `GET /api/kelas/{id}` | Detail satu kelas | 200 |
| `POST /api/kelas` | Tambah kelas (`nama_kelas` wajib) | 201 |
| `PUT`/`PATCH /api/kelas/{id}` | Ubah sebagian; field yang tidak dikirim tidak berubah | 200 |
| `DELETE /api/kelas/{id}` | Hapus kelas kosong | 200 |

Setiap data berisi `id_kelas`, `nama_kelas`, `id_jurusan`, `tahun_ajaran`, `status`,
`id_wali_kelas`, `nama_wali_kelas`, dan `jumlah_siswa`. Body JSON, form-data (POST), atau form-urlencoded:

```json
{ "nama_kelas": "XI RPL 1", "id_jurusan": 1, "tahun_ajaran": "2026-07-01", "status": "aktif",
  "id_wali_kelas": "c9d58043-53a5-478f-ad0c-7e468da174c3" }
```

Aturan: `nama_kelas` wajib saat membuat, maksimal 100 karakter, tanpa karakter kontrol, dan
unik tanpa membedakan huruf besar/kecil (duplikat → 409). `id_jurusan` bilangan bulat positif
atau `null`, `tahun_ajaran` tanggal `YYYY-MM-DD` atau `null`, `status` `aktif`/`nonaktif`
(default `aktif`). `search` mencari `%` dan `_` sebagai teks biasa. Kelas yang masih punya siswa
tidak bisa dihapus (409). Validasi gagal → 422 dengan `errors` per field,
JSON rusak → 400, ID tidak ditemukan → 404, siswa yang mencoba menulis → 403, tanpa login → 401.

### Wali kelas

Pilihan guru untuk dropdown diambil dari `GET /api/data_guru` (pakai `id_guru` atau `id_user`,
nilainya sama). Guru yang dipilih disimpan di kelas melalui `id_wali_kelas` pada `POST`/`PUT /api/kelas`,
lalu respons kelas menampilkan `nama_wali_kelas`. Kirim `null` atau string kosong untuk mengosongkan.
ID harus milik guru yang terdaftar (selain itu 422). Satu guru hanya boleh menjadi wali satu kelas
(409). Jika guru dihapus, kelas tetap ada dan wali kelasnya menjadi `null`. Jalankan `php spark migrate`
untuk menambahkan kolom `kelas.id_wali_kelas`.
