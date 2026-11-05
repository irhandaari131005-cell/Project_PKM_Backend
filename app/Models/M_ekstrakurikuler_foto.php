<?php

namespace App\Models;

use CodeIgniter\Model;

class M_ekstrakurikuler_foto extends Model
{
    protected $table            = 'ekstrakurikuler_foto';
    protected $primaryKey       = 'id_foto';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = '';
    protected $allowedFields    = [
        'id_ekstrakurikuler',
        'file_name',
    ];
}
