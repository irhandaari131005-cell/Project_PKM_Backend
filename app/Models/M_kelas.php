<?php

namespace App\Models;

use CodeIgniter\Model;

// Model untuk table kelas / pembuatan table kelas di database
class M_kelas extends Model
{
    protected $table            = 'kelas';
    protected $primaryKey       = 'id_kelas';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id_kelas', 
        'nama_kelas', 
        'id_jurusan', 
        'tahun_ajaran', 
        'status',
        'id_wali_kelas',
        ];

    public function getKelasByJurusan($id_jurusan)
    {
        // Use query builder to get data from table kelas where id_jurusan = $id_jurusan with getAllResults() method
        $this->db = \Config\Database::connect();
        $builder = $this->db->table($this->table);
        $builder->where('id_jurusan', $id_jurusan);
        $query = $builder->get();

        return $query->getResult();
    }
}
