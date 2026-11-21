<?php

use App\Database\Migrations\CreateEkstrakurikuler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-05-000001_CreateEkstrakurikuler.php';

final class DashboardSummaryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GURU = '11111111-1111-4111-8111-111111111111';
    private const SISWA = '22222222-2222-4222-8222-222222222222';
    private const SISWA2 = '33333333-3333-4333-8333-333333333333';

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'dsh_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE dsh_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE dsh_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE dsh_siswa (id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36),
            nama_siswa TEXT NOT NULL, status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT, id_kelas INTEGER)');
        $db->query('CREATE TABLE dsh_absensi (id_absensi VARCHAR(36) PRIMARY KEY, id_user VARCHAR(36), sn TEXT, tanggal DATE,
            check_in DATETIME, check_out DATETIME, created_date TEXT, update_date TEXT)');
        (new CreateEkstrakurikuler(Database::forge($db)))->up();

        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Guru', 'password' => 'x', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Andi', 'password' => 'x', 'type' => 'siswa'],
        ]);
        $db->table('siswa')->insertBatch([
            ['id_user' => self::SISWA, 'nama_siswa' => 'Andi', 'type' => 'siswa'],
            ['id_user' => self::SISWA2, 'nama_siswa' => 'Budi', 'type' => 'siswa'],
            ['id_user' => null, 'nama_siswa' => 'Citra', 'type' => 'siswa'],
        ]);
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $db->table('absensi')->insertBatch([
            // Andi absen masuk hari ini (tercatat dua kali tetap dihitung satu orang).
            ['id_absensi' => 'a1', 'id_user' => self::SISWA, 'sn' => 'S1', 'tanggal' => $today, 'check_in' => "{$today} 07:00:00", 'check_out' => null],
            ['id_absensi' => 'a2', 'id_user' => self::SISWA, 'sn' => 'S1', 'tanggal' => $today, 'check_in' => "{$today} 07:01:00", 'check_out' => null],
            // Budi absen kemarin: tidak dihitung.
            ['id_absensi' => 'a3', 'id_user' => self::SISWA2, 'sn' => 'S2', 'tanggal' => '2000-01-01', 'check_in' => '2000-01-01 07:00:00', 'check_out' => null],
        ]);
        foreach ([['Pramuka', 'ACTIVE'], ['Futsal', 'ACTIVE'], ['Catur', 'INACTIVE']] as [$nama, $status]) {
            $db->table('ekstrakurikuler')->insert([
                'nama_ekstrakurikuler' => $nama, 'hari' => 'senin', 'jam_mulai' => '14:00', 'jam_selesai' => '15:00', 'status' => $status,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    public function testSummaryCountsTodaysAttendanceAndActiveExtracurriculars(): void
    {
        foreach ([self::GURU => 'guru', self::SISWA => 'siswa'] as $id => $type) {
            $response = $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => $id, 'type' => $type]])
                ->get('/api/dashboard/ringkasan');

            $response->assertStatus(200);
            $data = json_decode($response->getJSON(), true)['data'];
            $this->assertSame(['hadir_hari_ini' => 1, 'total_siswa' => 3, 'sudah_pulang' => 0, 'belum_hadir' => 2], $data['kehadiran']);
            $this->assertSame(['aktif' => 2, 'total' => 3], $data['ekstrakurikuler']);
            $this->assertSame(0, $data['total_guru']);
            // Tren 7 hari: terlama dulu, hari ini terakhir dengan 1 siswa hadir.
            $this->assertCount(7, $data['tren_kehadiran']);
            $this->assertSame(['tanggal' => $data['tanggal'], 'hari' => $data['hari'], 'hadir' => 1], end($data['tren_kehadiran']));
            $this->assertSame([0, 0, 0, 0, 0, 0], array_column(array_slice($data['tren_kehadiran'], 0, 6), 'hadir'));
        }
    }

    public function testTeacherSeesLatestAttendanceAndStudentSeesOwnRecap(): void
    {
        $guru = json_decode($this->withHeaders([])->withBody('')
            ->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']])
            ->get('/api/dashboard/ringkasan')->getJSON(), true)['data'];
        $this->assertSame(['Andi', 'Andi'], array_column($guru['absensi_terbaru'], 'nama_siswa'));
        $this->assertArrayNotHasKey('kehadiran_saya', $guru);

        $siswa = json_decode($this->withHeaders([])->withBody('')
            ->withSession(['auth_user' => ['id_user' => self::SISWA, 'type' => 'siswa']])
            ->get('/api/dashboard/ringkasan')->getJSON(), true)['data'];
        $this->assertArrayNotHasKey('absensi_terbaru', $siswa, 'Siswa tidak melihat absensi siswa lain.');
        $this->assertSame(1, $siswa['kehadiran_saya']['minggu_ini']);
        $this->assertSame(1, $siswa['kehadiran_saya']['bulan_ini']);
        $this->assertNotNull($siswa['kehadiran_saya']['hari_ini']['check_in']);
        $this->assertSame([], $siswa['jadwal_hari_ini']);
    }

    public function testGuestIsRejected(): void
    {
        $this->withHeaders([])->withBody('')->get('/api/dashboard/ringkasan')->assertStatus(401);
    }
}
