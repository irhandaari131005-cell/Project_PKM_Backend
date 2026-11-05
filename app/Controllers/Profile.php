<?php

namespace App\Controllers;

use App\Libraries\PhotoUpload;
use App\Models\M_guru;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * "Profil Saya": lihat dan ubah profil akun yang sedang login (guru maupun siswa).
 * Identitas selalu dari sesi (AuthFilter), tidak pernah dari ID di request.
 *
 * Yang bisa diubah sendiri: nama, tempat_lahir, tanggal_lahir, alamat, no_telp, foto.
 * SN (kartu RFID untuk absen & reset password), tipe, dan status TIDAK bisa diubah di sini.
 *
 * Body PUT bisa JSON (tanpa foto) atau multipart (POST + _method=PUT) dengan file `foto`.
 * Kirim `hapus_foto=1` untuk menghapus foto profil.
 */
class Profile extends BaseController
{
    private const PHOTO_URL_PATH = 'uploads/profil/';
    private const TEXT_FIELDS = [
        'tempat_lahir' => [100, 'Tempat lahir'],
        'alamat' => [255, 'Alamat'],
    ];

    public function show(): ResponseInterface
    {
        try {
            $profile = $this->load();

            return $profile === null
                ? $this->respond(404, 'Profil tidak ditemukan.')
                : $this->respond(200, 'Profil berhasil diambil.', $profile);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function update(): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body harus berupa objek JSON atau form yang valid.');
        }

        $stored = null;
        try {
            [$identity, $detail, $model] = $this->current();
            if ($identity === null) {
                return $this->respond(404, 'Profil tidak ditemukan.');
            }
            if ($detail === null) {
                return $this->respond(409, 'Data ' . $identity['type'] . ' untuk akun ini belum terdaftar. Hubungi admin sekolah.');
            }

            [$data, $errors] = $this->validated($input);
            $file = PhotoUpload::single($this->request->getFile('foto'));
            if ($file !== null && ($error = PhotoUpload::validate($file)) !== null) {
                $errors['foto'] = $error;
            }
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }

            $oldPhoto = $detail['foto'] ?? null;
            $removePhoto = $file !== null || $this->truthy($input['hapus_foto'] ?? null);
            $nama = $data['nama'] ?? null;
            unset($data['nama']);
            if ($nama !== null) {
                // Nama disimpan di dua tempat: akun (users) dan data siswa/guru.
                $data[$identity['type'] === 'siswa' ? 'nama_siswa' : 'nama'] = $nama;
            }
            if ($removePhoto) {
                $data['foto'] = null;
            }

            $db = db_connect();
            $db->transException(true)->transStart();
            if ($file !== null) {
                $stored = PhotoUpload::store($file, $this->photoDir());
                $data['foto'] = $stored;
            }
            if ($nama !== null) {
                (new M_users())->update($identity['id_user'], ['nama' => $nama, 'update_date' => $this->now()]);
            }
            if ($data !== []) {
                $model->update($identity['type'] === 'siswa' ? (int) $detail['id_siswa'] : (string) $detail['id_user'], $data);
            }
            $db->transComplete();

            // Foto lama baru dihapus setelah database berhasil diperbarui.
            if ($removePhoto && $oldPhoto !== null && $oldPhoto !== $stored) {
                PhotoUpload::remove($this->photoDir(), [$oldPhoto]);
            }
            if ($nama !== null) {
                // Nama di sesi ikut diperbarui; AuthFilter juga membacanya ulang dari users.
                session()->set('auth_user', ['nama' => $nama] + session()->get('auth_user'));
            }

            return $this->respond(200, 'Profil berhasil diperbarui.', $this->load());
        } catch (Throwable $e) {
            // Database sudah di-rollback; buang file baru yang sempat dipindahkan.
            if ($stored !== null) {
                PhotoUpload::remove($this->photoDir(), [$stored]);
            }

            return $this->serverError($e);
        }
    }

    /** @return array{0: ?array, 1: ?array, 2: M_siswa|M_guru} */
    private function current(): array
    {
        $auth = session()->get('auth_user');
        $identity = (new M_users())->select('id_user, username, nama, sn, type')->find((string) $auth['id_user']);
        if ($identity === null || ! in_array($identity['type'], ['siswa', 'guru'], true)) {
            return [null, null, new M_guru()];
        }
        if ($identity['type'] === 'siswa') {
            $model = new M_siswa();
            $detail = (new M_siswa())->select('siswa.*, kelas.nama_kelas')
                ->join('kelas', 'kelas.id_kelas = siswa.id_kelas', 'left')
                ->where('siswa.id_user', $identity['id_user'])
                ->orderBy('siswa.id_siswa', 'ASC')->first();
        } else {
            $model = new M_guru();
            $detail = $model->find((string) $identity['id_user']);
        }

        return [$identity, $detail, $model];
    }

    private function load(): ?array
    {
        [$identity, $detail] = $this->current();
        if ($identity === null) {
            return null;
        }
        $detail ??= [];
        $foto = $detail['foto'] ?? null;

        $profile = [
            'id_user' => M_users::normalizeId($identity['id_user']),
            'username' => $identity['username'],
            'type' => $identity['type'],
            'nama' => $detail['nama_siswa'] ?? $detail['nama'] ?? $identity['nama'],
            'sn' => $detail['sn'] ?? $identity['sn'] ?? null,
            'status' => $detail['status'] ?? null,
            'tempat_lahir' => $detail['tempat_lahir'] ?? null,
            'tanggal_lahir' => $detail['tanggal_lahir'] ?? null,
            'alamat' => $detail['alamat'] ?? null,
            'no_telp' => $detail['no_telp'] ?? null,
            // Path relatif; frontend menggabungkannya dengan VITE_API_BASE_URL.
            'foto_url' => PhotoUpload::isStoredName($foto) ? '/' . self::PHOTO_URL_PATH . $foto : null,
        ];
        if ($identity['type'] === 'siswa') {
            $profile['nama_kelas'] = $detail['nama_kelas'] ?? null;
        }

        return $profile;
    }

    /** Field yang tidak dikirim tidak diubah. @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validated(array $input): array
    {
        $data = [];
        $errors = [];

        if (array_key_exists('nama', $input)) {
            $nama = $this->text($input['nama']);
            if ($nama === null || $nama === '') {
                $errors['nama'] = 'Nama wajib diisi.';
            } elseif (mb_strlen($nama) > 100) {
                $errors['nama'] = 'Nama maksimal 100 karakter.';
            } elseif ($this->hasControlChars($nama)) {
                $errors['nama'] = 'Nama tidak boleh berisi karakter kontrol.';
            } else {
                $data['nama'] = $nama;
            }
        }

        foreach (self::TEXT_FIELDS as $field => [$max, $label]) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $this->text($input[$field]);
            if ($value === null) {
                $errors[$field] = "{$label} harus berupa teks.";
            } elseif ($value === '') {
                $data[$field] = null;
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = "{$label} maksimal {$max} karakter.";
            } elseif ($field !== 'alamat' && $this->hasControlChars($value)) {
                $errors[$field] = "{$label} tidak boleh berisi karakter kontrol.";
            } else {
                $data[$field] = $value;
            }
        }

        if (array_key_exists('tanggal_lahir', $input)) {
            $tanggal = $this->text($input['tanggal_lahir']);
            if ($tanggal === '') {
                $data['tanggal_lahir'] = null;
            } elseif (! is_string($tanggal) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $tanggal, $m)
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $errors['tanggal_lahir'] = 'Tanggal lahir harus berupa tanggal valid (YYYY-MM-DD).';
            } elseif ($tanggal < '1900-01-01' || $tanggal > $this->today()) {
                $errors['tanggal_lahir'] = 'Tanggal lahir tidak boleh di masa depan atau sebelum tahun 1900.';
            } else {
                $data['tanggal_lahir'] = $tanggal;
            }
        }

        if (array_key_exists('no_telp', $input)) {
            $telp = $this->text($input['no_telp']);
            // Spasi, tanda hubung, titik, dan kurung boleh dipakai saat mengetik; yang disimpan hanya angka (+ di depan).
            $normalized = is_string($telp) ? preg_replace('/[\s\-.()]/', '', $telp) : null;
            if ($normalized === '') {
                $data['no_telp'] = null;
            } elseif (! is_string($normalized) || ! preg_match('/\A\+?[0-9]{8,15}\z/', $normalized)) {
                $errors['no_telp'] = 'No telp harus berisi 8–15 angka, boleh diawali +.';
            } else {
                $data['no_telp'] = $normalized;
            }
        }

        return [$data, $errors];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? trim($value) : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8');
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }

    protected function photoDir(): string
    {
        return FCPATH . self::PHOTO_URL_PATH;
    }

    private function input(): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $decoded = json_decode($this->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                throw new InvalidArgumentException('Body bukan objek JSON.');
            }

            return $decoded;
        }
        // Multipart selalu datang lewat POST (PUT dipalsukan via _method), jadi isinya ada di $_POST.
        $post = $this->request->getPost();
        if (is_array($post) && $post !== []) {
            unset($post['_method']);

            return $post;
        }
        $raw = $this->request->getRawInput();

        return is_array($raw) ? $raw : [];
    }

    private function respond(int $status, string $message, ?array $data = null, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => $status, 'message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode($status)->setJSON($body);
    }

    private function serverError(Throwable $e): ResponseInterface
    {
        log_message('error', 'Profil: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses profil.');
    }
}
