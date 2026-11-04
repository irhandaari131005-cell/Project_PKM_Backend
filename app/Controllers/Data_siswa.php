<?php

namespace App\Controllers;

use App\Models\M_kelas;
use App\Models\M_siswa;

class Data_siswa extends BaseController
{
    public function DataSiswa()
    {
        $id_siswa = $this->request->getGet('id_siswa');

        if ($id_siswa !== null && ! self::validPositiveId($id_siswa)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_siswa harus berupa bilangan bulat positif',
            ]);
        }

        // Siswa boleh melihat seluruh daftar siswa (read-only), tetapi SN kartu disembunyikan
        // karena SN dipakai untuk absensi RFID dan reset password.
        $siswaModel = new M_siswa();
        $isStudent = session()->get('auth_user')['type'] !== 'guru';
        $data =$id_siswa === null ? $siswaModel->findAll() : $siswaModel->where('id_siswa', (int) $id_siswa)->first();
        if ($isStudent && $data !== null) {
            if ($id_siswa === null) {
                foreach ($data as &$row) {
                    unset($row['sn']);
                }
                unset($row);
            } else {
                unset($data['sn']);
            }
        }

        // Return json response 200
        if($data === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 404,
                'message' => 'Data tidak ditemukan',
            ]);
        } else {
            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data berhasil diambil',
                'data' => $data,
            ]);
        }
    }

    public function DataKelas() 
    {
        $jurusan = $this->request->getGet('id_jurusan');

        if ($jurusan !== null && ! self::validPositiveId($jurusan)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_jurusan harus berupa bilangan bulat positif',
            ]);
        }

        $kelasModel = new M_kelas();

        if ($jurusan !== null) {
            $kelasModel->where('id_jurusan', (int) $jurusan);
        }

        $data = $kelasModel->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }

    /** ID INT positif dari query string: hanya digit, tanpa spasi/tanda +/nol di depan. */
    public static function validPositiveId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }
}
