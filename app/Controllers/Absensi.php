<?php

namespace App\Controllers;

use App\Models\M_absensi;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class Absensi extends BaseController
{
    private const TAP_DELAY_SECONDS = 10;

    protected M_absensi $m_absensi;

    public function __construct()
    {
        $this->m_absensi = new M_absensi();
    }

    public function index(): ResponseInterface
    {
        try {
            $filters = [];
            foreach (['id_absensi', 'id_user', 'id_siswa'] as $field) {
                $value = $this->request->getGet($field);
                if ($value !== null) {
                    if ($field === 'id_siswa') {
                        if (! $this->validId($value)) {
                            return $this->respond(422, 'id_siswa harus berupa bilangan bulat positif.');
                        }
                        $filters['siswa.id_siswa'] = (int) $value;
                    } elseif ($field === 'id_absensi') {
                        if (! $this->validAttendanceId($value)) {
                            return $this->respond(422, 'id_absensi harus berupa UUID yang valid.');
                        }
                        $filters['absensi.id_absensi'] = $value;
                    } else {
                        $userId = M_users::normalizeId($value);
                        if ($userId === null) {
                            return $this->respond(422, 'id_user harus berupa ID akun yang valid.');
                        }
                        $filters['absensi.id_user'] = (string) $userId;
                    }
                }
            }

            $tanggal = $this->request->getGet('tanggal');
            if ($tanggal !== null) {
                if (! is_string($tanggal)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $tanggal)
                    || ! checkdate((int) substr($tanggal, 5, 2), (int) substr($tanggal, 8, 2), (int) substr($tanggal, 0, 4))) {
                    return $this->respond(422, 'tanggal harus berupa tanggal valid dengan format YYYY-MM-DD.');
                }
                $filters['absensi.tanggal'] = $tanggal;
            }

            $query = $this->m_absensi->withSiswa()->where($filters);
            $this->scopeToCurrentStudent($query);
            if (isset($filters['absensi.id_absensi'])) {
                $data = $query->first();

                return $data === null
                    ? $this->respond(404, 'Data absensi tidak ditemukan.')
                    : $this->respond(200, 'Data absensi berhasil diambil.', $data);
            }

            $data = $query->orderBy('absensi.tanggal', 'DESC')
                ->orderBy('absensi.id_absensi', 'DESC')->findAll();

            return $this->respond(200, 'Data absensi berhasil diambil.', $data);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function show(string $id): ResponseInterface
    {
        if (! $this->validAttendanceId($id)) {
            return $this->respond(422, 'id_absensi harus berupa UUID yang valid.');
        }

        try {
            $query = $this->m_absensi->withSiswa();
            $this->scopeToCurrentStudent($query);
            $data = $query->where('absensi.id_absensi', $id)->first();

            return $data === null
                ? $this->respond(404, 'Data absensi tidak ditemukan.')
                : $this->respond(200, 'Data absensi berhasil diambil.', $data);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function Absensi(): ResponseInterface
    {
        try {
            if (array_key_exists('sn', $this->input())) {
                return $this->tap();
            }
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        return $this->checkIn();
    }

    public function tap(): ResponseInterface
    {
        try {
            $input = $this->input();
            $sn = $input['sn'] ?? null;
            if (! $this->validSn($sn)) {
                return $this->respond(422, 'sn wajib berupa UID kartu RFID, maksimal 50 karakter tanpa karakter kontrol.',
                    null, null, ['sn' => 'Kartu tidak terbaca. Tempelkan ulang kartu.']);
            }

            // jenis_absensi dari layar absensi: "masuk" hanya mencatat check-in, "pulang" hanya
            // check-out. Tanpa jenis_absensi (mis. alat RFID mandiri) tap pertama = masuk, berikutnya = pulang.
            $mode = $input['jenis_absensi'] ?? null;
            if ($mode !== null) {
                $mode = is_string($mode) ? strtolower(trim($mode)) : '';
                if (! in_array($mode, ['masuk', 'pulang'], true)) {
                    return $this->respond(422, 'jenis_absensi harus masuk atau pulang.', null, null,
                        ['jenis_absensi' => 'Pilih absen masuk atau absen pulang.']);
                }
            }

            // UID tetap string agar angka nol di awal tidak hilang.
            $students = (new M_siswa())->where('sn', trim($sn))->findAll(2);
            if ($students === []) {
                return $this->respond(404, 'Kartu RFID belum terdaftar pada data siswa.');
            }
            if (count($students) !== 1) {
                return $this->respond(409, 'Kartu RFID terdaftar pada lebih dari satu siswa. Perbaiki data kartu terlebih dahulu.');
            }

            $student = $students[0];
            $error = $this->studentError($student);
            if ($error !== null) {
                return $error;
            }
            $now = $this->now();
            $data = $this->m_absensi->where([
                'id_user' => (string) $student['id_user'],
                'tanggal' => substr($now, 0, 10),
            ])->first();
            $nama = (string) ($student['nama_siswa'] ?? 'Siswa');
            if ($mode === 'masuk') {
                return $data === null
                    ? $this->createCheckIn($student, $now)
                    : $this->respond(409, "{$nama} sudah absen masuk hari ini.");
            }
            if ($mode === 'pulang') {
                if ($data === null) {
                    return $this->respond(409, "{$nama} belum absen masuk hari ini.");
                }
                if ($data['check_out'] !== null) {
                    return $this->respond(409, "{$nama} sudah absen pulang hari ini.");
                }

                return $this->completeCheckOut($data, $now);
            }

            if ($data === null) {
                return $this->createCheckIn($student, $now);
            }
            if ($data['check_out'] !== null) {
                return $this->respond(409, 'Absensi siswa hari ini sudah lengkap.');
            }

            $timezone = new DateTimeZone('Asia/Jakarta');
            $elapsed = (new DateTimeImmutable($now, $timezone))->getTimestamp()
                - (new DateTimeImmutable($data['check_in'], $timezone))->getTimestamp();
            if ($elapsed < self::TAP_DELAY_SECONDS) {
                return $this->respond(409, 'Kartu baru saja dipindai. Tunggu minimal 10 detik sebelum tap berikutnya.');
            }

            return $this->completeCheckOut($data, $now);
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function checkIn(): ResponseInterface
    {
        try {
            $input = $this->input();
            if (array_key_exists('id_user', $input)) {
                $userId = M_users::normalizeId($input['id_user']);
                if ($userId === null) {
                    return $this->respond(422, 'id_user wajib berupa ID akun yang valid.');
                }
                $students = (new M_siswa())->where('id_user', (string) $userId)->findAll(2);
                if (count($students) > 1) {
                    return $this->respond(409, 'Akun terhubung ke lebih dari satu siswa. Perbaiki relasi siswa terlebih dahulu.');
                }
                $student = $students[0] ?? null;
            } else {
                if (! $this->validId($input['id_siswa'] ?? null)) {
                    return $this->respond(422, 'id_siswa wajib berupa bilangan bulat positif, atau gunakan id_user yang valid.');
                }
                $student = (new M_siswa())->find((int) $input['id_siswa']);
            }
            if ($student === null) {
                return $this->respond(404, 'Data siswa tidak ditemukan.');
            }
            $error = $this->studentError($student);
            if ($error !== null) {
                return $error;
            }

            return $this->createCheckIn($student, $this->now());
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function createCheckIn(array $student, string $now): ResponseInterface
    {
        $identity = ['id_user' => (string) $student['id_user'], 'tanggal' => substr($now, 0, 10)];
        $sn = trim($student['sn']);
        if ($this->m_absensi->where($identity)->first() !== null) {
            return $this->respond(409, 'Siswa sudah check-in pada tanggal ini.');
        }

        try {
            $id = $this->m_absensi->insert($identity + [
                'sn' => $sn,
                'check_in' => $now,
                'check_out' => null,
                'created_date' => $now,
                'update_date' => $now,
            ]);
        } catch (DatabaseException $e) {
            // Unique key juga mencegah check-in ganda dari request bersamaan.
            $conflict = $this->checkInConflict($identity, $sn);
            if ($conflict !== null) {
                return $conflict;
            }
            throw $e;
        }

        if ($id === false) {
            $conflict = $this->checkInConflict($identity, $sn);
            if ($conflict !== null) {
                return $conflict;
            }
            throw new RuntimeException('Gagal menyimpan check-in.');
        }

        return $this->respond(201, 'Check-in berhasil.', $this->m_absensi->withSiswa()->find($id), 'check_in');
    }

    public function checkOut(): ResponseInterface
    {
        try {
            $input = $this->input();
            if (! $this->validAttendanceId($input['id_absensi'] ?? null)) {
                return $this->respond(422, 'id_absensi wajib berupa UUID yang valid.');
            }

            $id = $input['id_absensi'];
            $data = $this->m_absensi->find($id);
            if ($data === null) {
                return $this->respond(404, 'Data absensi tidak ditemukan. Lakukan check-in terlebih dahulu.');
            }

            return $this->completeCheckOut($data, $this->now());
        } catch (JsonException | InvalidArgumentException $e) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function completeCheckOut(array $data, string $now): ResponseInterface
    {
        $id = $data['id_absensi'];
        if ($data['check_out'] !== null) {
            return $this->respond(409, 'Siswa sudah check-out untuk absensi ini.');
        }

        if ($now < $data['check_in']) {
            return $this->respond(409, 'Waktu check-out tidak boleh sebelum check-in.');
        }

        // Absensi hari sebelumnya yang tidak di-check-out tidak boleh tertutup dengan jam hari ini.
        if (substr((string) $data['tanggal'], 0, 10) !== substr($now, 0, 10)) {
            return $this->respond(409, 'Check-out hanya dapat dilakukan pada tanggal absensi yang sama.');
        }

        // Update bersyarat memastikan hanya satu request yang berhasil check-out.
        $db = db_connect();
        $updated = $db->table('absensi')->where('id_absensi', $id)
            ->where('check_out', null)->update(['check_out' => $now, 'update_date' => $now]);
        if (! $updated) {
            throw new RuntimeException('Gagal menyimpan check-out.');
        }
        if ($db->affectedRows() === 0) {
            return $this->respond(409, 'Data absensi sudah berubah. Muat ulang data absensi.');
        }

        return $this->respond(200, 'Check-out berhasil.', $this->m_absensi->withSiswa()->find($id), 'check_out');
    }

    private function studentError(array $student): ?ResponseInterface
    {
        $userId = M_users::normalizeId($student['id_user'] ?? null);
        if ($userId === null || (new M_users())->find($userId) === null) {
            return $this->respond(409, 'Data siswa belum terhubung ke akun yang valid. Perbaiki id_user siswa terlebih dahulu.');
        }
        if (! $this->validSn($student['sn'] ?? null)) {
            return $this->respond(409, 'Data kartu siswa tidak valid. sn harus berisi maksimal 50 karakter tanpa karakter kontrol.');
        }

        return null;
    }

    private function checkInConflict(array $identity, string $sn): ?ResponseInterface
    {
        if ($this->m_absensi->where($identity)->first() !== null) {
            return $this->respond(409, 'Siswa sudah check-in pada tanggal ini.');
        }
        if ($this->m_absensi->where('sn', $sn)->first() !== null) {
            foreach (db_connect()->getIndexData('absensi') as $index) {
                if (strtoupper($index->type) === 'UNIQUE' && $index->fields === ['sn']) {
                    return $this->respond(409, 'Kartu sudah dipakai pada tanggal lain. Hapus UNIQUE pada absensi.sn agar kartu dapat dipakai setiap hari.');
                }
            }
        }

        return null;
    }

    private function validSn(mixed $sn): bool
    {
        return is_string($sn) && trim($sn) !== '' && mb_strlen(trim($sn)) <= 50
            && ! preg_match('/[\x00-\x1F\x7F]/', trim($sn));
    }

    private function validAttendanceId(mixed $id): bool
    {
        return is_string($id)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) === 1;
    }

    private function input(): array
    {
        $contentType = strtolower(explode(';', $this->request->getHeaderLine('Content-Type'))[0]);
        if (trim($contentType) === 'application/json' || str_ends_with(trim($contentType), '+json')) {
            $decoded = json_decode($this->request->getBody(), false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new InvalidArgumentException('Body bukan objek JSON.');
            }

            return (array) $decoded;
        }

        return $this->request->getPost();
    }

    private function validId(mixed $id): bool
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id)))
            && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) !== false;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }

    private function respond(int $status, string $message, ?array $data = null, ?string $action = null, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => $status, 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        if ($data !== null) {
            if ((session()->get('auth_user')['type'] ?? null) === 'siswa') {
                if (array_is_list($data)) {
                    foreach ($data as &$row) {
                        unset($row['sn']);
                    }
                    unset($row);
                } else {
                    unset($data['sn']);
                }
            }
            $body['data'] = $data;
        }
        if ($action !== null) {
            $body['action'] = $action;
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    private function scopeToCurrentStudent(M_absensi $query): void
    {
        if (session()->get('auth_user')['type'] === 'siswa') {
            $query->where('absensi.id_user', (string) session()->get('auth_user')['id_user']);
        }
    }

    private function serverError(Throwable $e): ResponseInterface
    {
        log_message('error', 'Absensi: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses absensi.');
    }
}
