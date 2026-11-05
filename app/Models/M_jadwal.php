<?php

namespace App\Models;

use CodeIgniter\Model;

class M_jadwal extends Model
{
    protected $table            = 'jadwal';
    protected $primaryKey       = 'id_jadwal';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = [
        'mata_pelajaran',
        'id_kelas',
        'id_guru',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'ruangan',
    ];
}
