<?php

namespace App\Controllers;

use App\Models\M_absensi;
use App\Models\M_ekstrakurikuler;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class Dashboard extends BaseController
{
    /**
     * Angka untuk kartu Dashboard: kehadiran hari ini (WIB) dan jumlah ekstrakurikuler aktif.
     * Hanya hitungan, tanpa data pribadi, jadi aman untuk guru maupun siswa.
     */
    public function Ringkasan(): ResponseInterface
    {
        try {
            $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $hadir = (new M_absensi())->select('id_user')->distinct()
                ->where('tanggal', $today)->where('check_in IS NOT NULL', null, false)
                ->countAllResults();

            return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Ringkasan dashboard berhasil diambil.',
                'data' => [
                    'tanggal' => $today,
                    'kehadiran' => [
                        'hadir_hari_ini' => $hadir,
                        'total_siswa' => (new M_siswa())->countAllResults(),
                    ],
                    'ekstrakurikuler' => [
                        'aktif' => (new M_ekstrakurikuler())->where('status', 'ACTIVE')->countAllResults(),
                        'total' => (new M_ekstrakurikuler())->countAllResults(),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Ringkasan dashboard: {exception}', ['exception' => $e]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Ringkasan dashboard belum dapat dimuat.',
            ]);
        }
    }

    // Dashboard controller for handling dashboard related requests
    public function Dashboard()
    {
        // Jangan kirim hash password atau SN pemulihan akun ke dashboard.
        $data = (new M_users())
            ->select('id_user, username, nama, type, created_date, update_date');
        if (session()->get('auth_user')['type'] === 'siswa') {
            $data->where('id_user', (string) session()->get('auth_user')['id_user']);
        }
        $data = $data->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }

    public function Data_guru()
    {
        // Load model m_guru
        $this->m_user = new \App\Models\M_guru();

        // Get all data from table guru
        $data = $this->m_user->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }
    
    public function Data_siswa()
    {
        // Load model m_siswa
        $this->m_user = new \App\Models\M_siswa();

        // Get all data from table siswa
        $data = $this->m_user->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }
}
