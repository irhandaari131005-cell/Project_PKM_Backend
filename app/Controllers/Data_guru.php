<?php

namespace App\Controllers;

use App\Models\M_kelas;
use App\Models\M_guru;
use App\Models\M_users;


class Data_guru extends BaseController
{
    public function DataGuru()
    {
        $idUser = $this->request->getGet('id_user');
        $id_guru = $this->request->getGet('id_guru');

        if ($idUser !== null && M_users::normalizeId($idUser) === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_user harus berupa UUID atau ID numerik lama yang valid',
            ]);
        }

        if ($id_guru !== null && ! is_int(M_users::normalizeId($id_guru))) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_guru harus berupa bilangan bulat positif',
            ]);
        }

        $guruModel = new M_guru();
        // id_guru tetap diterima untuk ID numerik lama; akun baru memakai UUID id_user.
        $identifier = M_users::normalizeId($idUser ?? $id_guru);
        $data = $identifier === null ? $guruModel->findAll() : $guruModel->find((string) $identifier);

        // Frontend (dropdown wali kelas & halaman guru) membaca ID guru dari `id_guru`.
        // Primary key guru adalah id_user, jadi kirim keduanya dengan nilai yang sama.
        // SN kartu guru dipakai untuk reset password; siswa hanya boleh melihat data umum.
        $hideCard = (session()->get('auth_user')['type'] ?? null) !== 'guru';
        $withAlias = static function (array $row) use ($hideCard): array {
            if ($hideCard) {
                unset($row['sn']);
            }

            return $row + ['id_guru' => $row['id_user'] ?? null];
        };
        if ($data !== null) {
            $data = $identifier === null ? array_map($withAlias, $data) : $withAlias($data);
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

        if ($jurusan !== null && filter_var($jurusan, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
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
}
