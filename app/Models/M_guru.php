<?php

namespace App\Models;

use CodeIgniter\Model;

class M_guru extends Model
{
    protected $table            = 'guru';
    protected $primaryKey       = 'id_user';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id_user',
        'nama',
        'sn',
        'type',
        'status',
        'created_date',
        'update_date',
    ];
}
