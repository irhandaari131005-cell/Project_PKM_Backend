<?php

namespace App\Controllers;

use App\Libraries\SchoolMembers;
use App\Models\M_guru;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class Auth extends BaseController
{
    private const MAX_PASSWORD_FAILURES = 5;
    private const PASSWORD_FAILURE_WINDOW = 300;

    protected M_users $m_user;

    public function __construct()
    {
        // Call model m_user
        $this->m_user = new M_users();
    }

    public function Register(): ResponseInterface
    {
        try {
            $input = $this->authInput();
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'Body JSON harus berupa objek yang valid.',
            ]);
        }

        $db = null;
        $transactionStarted = false;

        try {
            foreach (['nama', 'username', 'sn', 'type', 'id_kelas'] as $field) {
                if (isset($input[$field]) && is_string($input[$field])) {
                    $input[$field] = trim($input[$field]);
                }
            }

            $rules = [
                // Nama/username tidak boleh berisi baris baru, tab, null, atau karakter kontrol lain.
                'nama' => ['required', 'string', 'max_length[100]', 'regex_match[/\A[^\x00-\x1F\x7F]+\z/u]'],
                'username' => [
                    'required', 'string', 'max_length[100]', 'regex_match[/\A[^\x00-\x1F\x7F]+\z/u]',
                    'is_unique[users.username]',
                ],
                'password' => 'required|string|min_length[8]|max_length[72]',
                'password_confirmation' => 'required|string|matches[password]',
                // Kolom users.sn berukuran 50 karakter; jangan sampai UID terpotong.
                'sn' => [
                    'required', 'string', 'max_length[50]',
                    'regex_match[/\A[^\x00-\x1F\x7F]+\z/u]',
                    // SN di tabel siswa/guru dicek terpisah di bawah: data tanpa akun boleh diklaim.
                    'is_unique[users.sn]',
                ],
                'type' => 'required|string|in_list[siswa,guru]',
            ];
            if (($input['type'] ?? null) === 'siswa') {
                $rules['id_kelas'] = 'required|integer|greater_than[0]|less_than_equal_to[2147483647]|is_not_unique[kelas.id_kelas]';
            }

            if (! $this->validateData($input, $rules)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'status' => 422,
                    'message' => 'Validasi gagal',
                    'errors' => $this->validator->getErrors(),
                ]);
            }

            if (! $this->validNewPassword($input['password'])) {
                return $this->response->setStatusCode(422)->setJSON([
                    'status' => 422,
                    'message' => 'Validasi gagal',
                    'errors' => ['password' => 'Password harus terdiri dari 8 sampai 72 byte dan tidak boleh mengandung karakter null.'],
                ]);
            }

            // Data siswa/guru yang ditambahkan guru (tanpa akun) dengan SN dan tipe yang sama
            // akan disambungkan ke akun baru. SN milik data lain (sudah berakun / tipe beda) ditolak.
            $members = new SchoolMembers(db_connect());
            $claim = $members->claimable($input['sn'], $input['type']);
            if ($claim === null && $members->cardInUse($input['sn'])) {
                return $this->response->setStatusCode(422)->setJSON([
                    'status' => 422,
                    'message' => 'Validasi gagal',
                    'errors' => ['sn' => 'SN sudah terdaftar.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
            $profile = [
                'sn' => $input['sn'],
                'type' => $input['type'],
                'created_date' => $now,
                'update_date' => $now,
            ];
            $data = $profile + [
                'username' => $input['username'],
                'nama' => $input['nama'],
                'password' => password_hash($input['password'], PASSWORD_DEFAULT),
            ];

            $db = db_connect();
            if (! $db->transBegin()) {
                throw new RuntimeException('Transaksi registrasi gagal dimulai.');
            }
            $transactionStarted = true;

            $userId = $this->m_user->insert($data);
            if ($userId === false) {
                throw new RuntimeException('Model gagal menyimpan user.');
            }
            $profile['id_user'] = $userId;

            if ($claim !== null) {
                // Data dari guru dipertahankan (nama, NIS/NIP, kelas); hanya akunnya yang disambungkan.
                $members->link($claim, (string) $userId, $now);
                if (! $db->transStatus() || ! $db->transCommit()) {
                    throw new RuntimeException('Data siswa/guru gagal disambungkan.');
                }
                $transactionStarted = false;

                return $this->response->setStatusCode(201)->setJSON([
                    'status' => 201,
                    'message' => 'Registrasi berhasil. Akun disambungkan ke data ' . $input['type'] . ' yang sudah terdaftar.',
                    'redirect' => '/login',
                ]);
            }

            if ($input['type'] === 'siswa') {
                $profileModel = new M_siswa($db);
                $profile['nama_siswa'] = $input['nama'];
                $profile['status'] = 'ACTIVE';
                $profile['id_kelas'] = (int) $input['id_kelas'];
            } else {
                $profileModel = new M_guru($db);
                $profile['nama'] = $input['nama'];
                $profile['status'] = 'ACTIVE';
            }

            if ($profileModel->insert($profile) === false || ! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('Data siswa/guru gagal disimpan.');
            }
            $transactionStarted = false;

            return $this->response->setStatusCode(201)->setJSON([
                'status' => 201,
                'message' => 'Registrasi berhasil',
                'redirect' => '/login',
            ]);
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->transRollback();
            }

            // Request ganda (mis. tombol daftar diklik dua kali) bisa lolos validasi bersamaan;
            // unique key database menolak yang kedua. Laporkan sebagai duplikat, bukan 500.
            $duplicates = $this->registrationDuplicates($input);
            if ($duplicates !== []) {
                return $this->response->setStatusCode(422)->setJSON([
                    'status' => 422,
                    'message' => 'Validasi gagal',
                    'errors' => $duplicates,
                ]);
            }

            log_message('error', 'Register gagal: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Terjadi kesalahan pada server',
            ]);
        }
    }


    public function Login(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        try {
            $session = session();
            // Percobaan login yang gagal tidak boleh memakai sesi login sebelumnya.
            $session->remove(['auth_user', 'auth_password']);
            try {
                $input = $this->authInput();
            } catch (JsonException | InvalidArgumentException $e) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 400,
                    'message' => 'Body JSON harus berupa objek yang valid.',
                ]);
            }
            if (! isset($input['username'], $input['password'])
                || ! is_string($input['username']) || ! is_string($input['password'])
                || trim($input['username']) === '' || $input['password'] === '') {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 400,
                    'message' => 'Username dan password wajib diisi sebagai teks.',
                ]);
            }

            if (! is_string($input['type'] ?? null)
                || ! in_array(trim($input['type']), ['siswa', 'guru'], true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'status' => 422,
                    'message' => 'Pilih jenis akun siswa atau guru.',
                    'errors' => ['type' => 'Jenis akun wajib diisi dengan siswa atau guru.'],
                ]);
            }

            $username = trim($input['username']);
            $password = $input['password'];
            if (mb_strlen($username) > 100 || strlen($password) > 72 || str_contains($password, "\0")) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 400,
                    'message' => 'Username/password salah',
                ]);
            }

            $failureKey = $this->passwordFailureKey($username);
            if ($this->passwordAttemptsBlocked($failureKey)) {
                return $this->tooManyPasswordAttempts('Terlalu banyak percobaan login gagal. Coba lagi nanti.');
            }

            $user = $this->m_user->where('username', $username)->first();
            if ($user === null) {
                $this->recordPasswordFailure($failureKey);

                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 400,
                    'message' => 'User tidak ditemukan',
                ]);
            }
            if (! password_verify($password, $user['password'])) {
                $this->recordPasswordFailure($failureKey);

                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 400,
                    'message' => 'Username/password salah',
                ]);
            }

            // Role form hanya untuk mencocokkan portal; izin selalu berasal dari akun tersimpan.
            if (! in_array($user['type'] ?? null, ['siswa', 'guru'], true)
                || $user['type'] !== trim($input['type'])) {
                return $this->response->setStatusCode(403)->setJSON([
                    'status' => 403,
                    'message' => 'Jenis akun tidak sesuai dengan akun yang terdaftar.',
                ]);
            }

            $userId = M_users::normalizeId($user['id_user']);
            if ($userId === null) {
                throw new RuntimeException('ID akun tidak valid.');
            }
            $identity = [
                'id_user' => $userId,
                'username' => $user['username'],
                'nama' => $user['nama'],
                'type' => $user['type'],
            ];
            cache()->delete($failureKey);
            $session->regenerate(true);
            $session->set('auth_user', $identity);
            $session->set('auth_password', M_users::passwordFingerprint($user['password']));

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Login berhasil',
                'redirect' => '/dashboard',
                'data' => $identity,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Login gagal: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Terjadi kesalahan pada server',
            ]);
        }
    }

    public function me(): ResponseInterface
    {
        // Filter auth sudah mencocokkan sesi dengan akun yang masih terdaftar.
        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'User terautentikasi',
            'data' => session()->get('auth_user'),
        ]);
    }

    public function Logout(): ResponseInterface
    {
        $session = session();
        $session->remove(['auth_user', 'auth_password']);
        $session->destroy();

        $this->response->setHeader('Cache-Control', 'no-store');

        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Logout berhasil',
            'redirect' => '/login',
        ]);
    }

    public function forgotPassword(): ResponseInterface
    {
        try {
            $input = $this->passwordInput();
            foreach (['username', 'sn', 'new_password'] as $field) {
                if (! isset($input[$field]) || ! is_string($input[$field]) || $input[$field] === '') {
                    return $this->passwordResponse(400, 'Username, SN, dan password baru wajib berupa teks dan harus diisi.');
                }
            }

            $username = trim($input['username']);
            // SN tetap string agar angka nol di depan tidak hilang.
            $sn = trim($input['sn']);
            $newPassword = $input['new_password'];
            if ($username === '' || mb_strlen($username) > 255) {
                return $this->passwordResponse(400, 'Username harus diisi dan maksimal 255 karakter.');
            }
            if ($sn === '' || mb_strlen($sn) > 100 || preg_match('/[\x00-\x1F\x7F]/', $sn)) {
                return $this->passwordResponse(400, 'SN harus diisi, maksimal 100 karakter, dan tanpa karakter kontrol.');
            }
            if (! $this->validNewPassword($newPassword)) {
                return $this->passwordResponse(400, 'Password baru harus terdiri dari 8 sampai 72 byte dan tidak boleh mengandung karakter null.');
            }

            $throttler = service('throttler');
            $ipKey = 'forgot-password-ip-' . hash('sha256', $this->request->getIPAddress());
            $accountKey = 'forgot-password-user-' . hash('sha256', mb_strtolower($username));
            if (! $throttler->check($ipKey, 20, 60) || ! $throttler->check($accountKey, 5, 300)) {
                $this->response->setHeader('Retry-After', (string) $throttler->getTokenTime());

                return $this->passwordResponse(429, 'Terlalu banyak percobaan reset password. Coba lagi nanti.');
            }

            $user = $this->m_user->where('username', $username)->first();
            // Cocokkan pasangan username dan SN pada tabel users hasil registrasi.
            if ($user === null || ! hash_equals((string) ($user['sn'] ?? ''), $sn)) {
                return $this->passwordResponse(401, 'Username atau SN tidak sesuai.');
            }

            return $this->updatePassword($user, $newPassword);
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->passwordResponse(400, 'Body JSON harus berupa objek yang valid.');
        } catch (Throwable $e) {
            log_message('error', 'Gagal mereset password: {exception}', ['exception' => $e]);

            return $this->passwordResponse(500, 'Terjadi kesalahan saat mengubah password.');
        }
    }

    public function changePassword(): ResponseInterface
    {
        try {
            $input = $this->passwordInput();
            foreach (['username', 'password', 'new_password'] as $field) {
                if (! isset($input[$field]) || ! is_string($input[$field]) || $input[$field] === '') {
                    return $this->passwordResponse(400, 'Username, password lama, dan password baru wajib berupa teks dan harus diisi.');
                }
            }

            $username = trim($input['username']);
            $oldPassword = $input['password'];
            $newPassword = $input['new_password'];
            if ($username === '') {
                return $this->passwordResponse(400, 'Username harus diisi.');
            }
            // bcrypt hanya memeriksa 72 byte pertama. Jangan terima tambahan byte atau null.
            if (strlen($oldPassword) > 72 || str_contains($oldPassword, "\0")) {
                return $this->passwordResponse(400, 'Password lama tidak valid.');
            }
            if (! $this->validNewPassword($newPassword)) {
                return $this->passwordResponse(400, 'Password baru harus terdiri dari 8 sampai 72 byte dan tidak boleh mengandung karakter null.');
            }

            // Endpoint ini juga bisa dipakai menebak password; batasi seperti login.
            $failureKey = $this->passwordFailureKey($username);
            if ($this->passwordAttemptsBlocked($failureKey)) {
                return $this->tooManyPasswordAttempts('Terlalu banyak percobaan password gagal. Coba lagi nanti.');
            }

            $user = $this->m_user->where('username', $username)->first();
            if ($user === null) {
                $this->recordPasswordFailure($failureKey);

                return $this->passwordResponse(404, 'User belum terdaftar.');
            }
            if (! password_verify($oldPassword, $user['password'])) {
                $this->recordPasswordFailure($failureKey);

                return $this->passwordResponse(401, 'Password lama salah.');
            }
            cache()->delete($failureKey);

            return $this->updatePassword($user, $newPassword);
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->passwordResponse(400, 'Body JSON harus berupa objek yang valid.');
        } catch (Throwable $e) {
            log_message('error', 'Gagal mengganti password: {exception}', ['exception' => $e]);

            return $this->passwordResponse(500, 'Terjadi kesalahan saat mengubah password.');
        }
    }

    private function passwordInput(): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $input = json_decode($this->request->getBody(), false, 512, JSON_THROW_ON_ERROR);
            if (! $input instanceof \stdClass) {
                throw new InvalidArgumentException('Body JSON harus berupa objek.');
            }

            return (array) $input;
        }

        return $this->request->getRawInput();
    }

    private function validNewPassword(string $password): bool
    {
        // Batas byte mencegah pemotongan password saat memakai bcrypt.
        return strlen($password) >= 8 && strlen($password) <= 72 && ! str_contains($password, "\0");
    }

    private function updatePassword(array $user, string $newPassword): ResponseInterface
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updated = $this->m_user->update($user['id_user'], [
            'password' => $hash,
            'update_date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s'),
        ]);
        if (! $updated) {
            throw new RuntimeException('Model gagal memperbarui password.');
        }

        // Sesi lain milik akun ini otomatis tidak berlaku (lihat AuthFilter); sesi yang
        // sedang dipakai pemilik akun sendiri tetap login dengan sidik jari yang baru.
        $session = session();
        $current = $session->get('auth_user');
        if (is_array($current) && (string) ($current['id_user'] ?? '') === (string) $user['id_user']) {
            $session->set('auth_password', M_users::passwordFingerprint($hash));
        }

        return $this->passwordResponse(200, 'Password berhasil diubah.');
    }

    private function passwordFailureKey(string $username): string
    {
        return 'password-failures-' . hash('sha256', $this->request->getIPAddress() . '|' . mb_strtolower($username));
    }

    private function passwordAttemptsBlocked(string $key): bool
    {
        return (int) cache()->get($key) >= self::MAX_PASSWORD_FAILURES;
    }

    private function recordPasswordFailure(string $key): void
    {
        cache()->save($key, (int) cache()->get($key) + 1, self::PASSWORD_FAILURE_WINDOW);
    }

    private function tooManyPasswordAttempts(string $message): ResponseInterface
    {
        $this->response->setHeader('Retry-After', (string) self::PASSWORD_FAILURE_WINDOW);

        return $this->passwordResponse(429, $message);
    }

    /** @return array<string, string> */
    private function registrationDuplicates(array $input): array
    {
        $errors = [];
        try {
            foreach (['username' => 'Username sudah terdaftar.', 'sn' => 'SN sudah terdaftar.'] as $field => $message) {
                if (is_string($input[$field] ?? null) && $input[$field] !== ''
                    && (new M_users())->where($field, $input[$field])->countAllResults() > 0) {
                    $errors[$field] = $message;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $errors;
    }

    private function passwordResponse(int $status, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'status' => $status,
            'message' => $message,
        ]);
    }

    private function authInput(): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $input = json_decode($this->request->getBody(), false, 512, JSON_THROW_ON_ERROR);
            if (! $input instanceof \stdClass) {
                throw new InvalidArgumentException('Body JSON harus berupa objek.');
            }

            return (array) $input;
        }

        return $this->request->getPost();
    }
}
