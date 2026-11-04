<?php

namespace App\Models;

use CodeIgniter\Model;

class M_absensi extends Model
{
    protected $table            = 'absensi';
    protected $primaryKey       = 'id_absensi';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $beforeInsert     = ['assignAttendanceId'];
    protected $allowedFields    = [
        'id_user',
        'sn',
        'tanggal',
        'check_in',
        'check_out',
        'created_date',
        'update_date',
    ];

    protected function assignAttendanceId(array $event): array
    {
        $event['data']['id_absensi'] = M_users::generateUuid();

        return $event;
    }

    public function withSiswa(): self
    {
        return $this->select('absensi.*, siswa.id_siswa, siswa.nama_siswa')
            ->join('siswa', 'siswa.id_user = absensi.id_user');
    }

    public function withStudentClass(): self
    {
        return $this->select('absensi.id_absensi, siswa.id_siswa, absensi.tanggal,
                absensi.check_in, absensi.check_out, siswa.nama_siswa, siswa.id_kelas, kelas.nama_kelas')
            ->join('siswa', 'siswa.id_user = absensi.id_user')
            ->join('kelas', 'kelas.id_kelas = siswa.id_kelas', 'left');
    }
}
