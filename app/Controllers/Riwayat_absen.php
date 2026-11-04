<?php

namespace App\Controllers;

use App\Models\M_absensi;
use App\Models\M_kelas;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class Riwayat_absen extends BaseController
{
    private const PER_PAGE = 25;

    public function index(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $tanggal = $this->request->getGet('tanggal')
            ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $kelas = $this->request->getGet('id_kelas');
        $page = $this->request->getGet('page') ?? '1';
        $errors = [];

        if (! is_string($tanggal) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $tanggal)
            || ! checkdate((int) substr($tanggal, 5, 2), (int) substr($tanggal, 8, 2), (int) substr($tanggal, 0, 4))) {
            $errors['tanggal'] = 'Pilih tanggal yang valid dengan format YYYY-MM-DD.';
        }
        if ($kelas !== null && ! $this->validId($kelas)) {
            $errors['id_kelas'] = 'id_kelas harus berupa bilangan bulat positif.';
        }
        if (! $this->validId($page)) {
            $errors['page'] = 'Halaman harus berupa bilangan bulat positif.';
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        try {
            if ($kelas !== null && (new M_kelas())->find((int) $kelas) === null) {
                return $this->validationError(['id_kelas' => 'Kelas tidak ditemukan.']);
            }

            $model = (new M_absensi())->withStudentClass()->where('absensi.tanggal', $tanggal);
            if (session()->get('auth_user')['type'] === 'siswa') {
                $model->where('absensi.id_user', (string) session()->get('auth_user')['id_user']);
            }
            if ($kelas !== null) {
                $model->where('siswa.id_kelas', (int) $kelas);
            }
            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $data = $model->orderBy('kelas.nama_kelas', 'ASC')
                ->orderBy('siswa.nama_siswa', 'ASC')->orderBy('absensi.id_absensi', 'ASC')
                ->findAll(self::PER_PAGE, $offset);
            foreach ($data as &$row) {
                $row['status_kehadiran'] = 'hadir';
            }
            unset($row);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Riwayat absensi berhasil diambil.',
                'data' => $data,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'from' => $data === [] ? null : $offset + 1,
                    'to' => $data === [] ? null : $offset + count($data),
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Riwayat absensi gagal: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Terjadi kesalahan saat mengambil riwayat absensi.',
            ]);
        }
    }

    private function validId(mixed $value): bool
    {
        // Hanya digit murni: tolak spasi, tanda +, dan angka nol di depan.
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }

    private function validationError(array $errors): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'status' => 422, 'message' => 'Filter riwayat absensi tidak valid.', 'errors' => $errors,
        ]);
    }
}
