<?php

namespace App\Models;

use CodeIgniter\Model;

class M_siswa extends Model
{
    protected $table            = 'siswa';
    protected $primaryKey       = 'id_siswa';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id_user',
        'id_kelas',
        'nama_siswa',
        'status',
        'sn',
        'type',
        'created_date',
        'update_date',
        'nis',
        // Field profil (bisa diedit sendiri lewat /api/profile).
        'tempat_lahir',
        'tanggal_lahir',
        'alamat',
        'no_telp',
        'foto',
    ];
}
