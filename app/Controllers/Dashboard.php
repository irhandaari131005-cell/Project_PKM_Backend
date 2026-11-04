<?php

namespace App\Controllers;

use App\Models\M_users;

class Dashboard extends BaseController
{
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
