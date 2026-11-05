<?php

namespace App\Models;

use CodeIgniter\Model;

class M_ekstrakurikuler extends Model
{
    protected $table            = 'ekstrakurikuler';
    protected $primaryKey       = 'id_ekstrakurikuler';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = [
        'nama_ekstrakurikuler',
        'id_pembina',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'lokasi',
        'deskripsi',
        'status',
    ];
}
