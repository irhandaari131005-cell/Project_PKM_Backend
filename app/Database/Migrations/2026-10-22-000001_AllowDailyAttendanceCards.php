<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AllowDailyAttendanceCards extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('absensi') || ! $this->db->fieldExists('sn', 'absensi')) {
            return;
        }

        $indexes = $this->db->getIndexData('absensi');
        foreach ($indexes as $index) {
            if ($index->type !== 'UNIQUE' || $index->fields !== ['sn']) {
                continue;
            }

            // SQLite inline UNIQUE memakai autoindex yang tidak bisa dihapus
            // tanpa rebuild tabel. Jangan ubah tabel/data lama secara diam-diam.
            if ($this->db->DBDriver === 'SQLite3' && str_starts_with($index->name, 'sqlite_autoindex_')) {
                throw new RuntimeException('UNIQUE sn inline perlu migrasi SQLite tersendiri; tabel absensi tidak diubah.');
            }
        }

        $hasCardIndex = false;
        foreach ($indexes as $index) {
            if ($index->type === 'UNIQUE' && $index->fields === ['sn']) {
                // Nama dari metadata sudah berisi prefix jika driver memakainya.
                if (! $this->forge->dropKey('absensi', $index->name, false)) {
                    throw new RuntimeException('Indeks UNIQUE kartu absensi gagal dihapus.');
                }
            } elseif ($index->type === 'INDEX' && $index->fields === ['sn']) {
                $hasCardIndex = true;
            }
        }

        if (! $hasCardIndex) {
            $this->forge->addKey('sn', false, false, 'idx_absensi_sn');
            if (! $this->forge->processIndexes('absensi')) {
                throw new RuntimeException('Indeks kartu absensi gagal ditambahkan.');
            }
        }
    }

    public function down()
    {
        // Kartu yang sama sudah dapat dipakai pada beberapa tanggal. Memasang
        // UNIQUE sn kembali bisa gagal atau mengharuskan penghapusan riwayat.
    }
}
