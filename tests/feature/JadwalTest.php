<?php

use App\Database\Migrations\CreateJadwal;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-05-000003_CreateJadwal.php';

final class JadwalTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GURU = '11111111-1111-4111-8111-111111111111';
    private const SISWA = '22222222-2222-4222-8222-222222222222';
    private const GURU2 = '33333333-3333-4333-8333-333333333333';

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'jdw_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE jdw_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE jdw_kelas (id_kelas INTEGER PRIMARY KEY AUTOINCREMENT, nama_kelas VARCHAR(100) NOT NULL,
            id_jurusan INTEGER, tahun_ajaran DATE, status VARCHAR(100))');
        $db->query('CREATE TABLE jdw_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('PRAGMA foreign_keys = ON');
        (new CreateJadwal(Database::forge($db)))->up();
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Bu Ani', 'password' => 'x', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Andi', 'password' => 'x', 'type' => 'siswa'],
        ]);
        $db->table('guru')->insertBatch([
            ['id_user' => self::GURU, 'nama' => 'Bu Ani', 'type' => 'guru', 'status' => 'ACTIVE'],
            ['id_user' => self::GURU2, 'nama' => 'Pak Budi', 'type' => 'guru', 'status' => 'ACTIVE'],
        ]);
        $db->table('kelas')->insertBatch([
            ['id_kelas' => 1, 'nama_kelas' => 'X RPL 1', 'status' => 'aktif'],
            ['id_kelas' => 2, 'nama_kelas' => 'X RPL 2', 'status' => 'aktif'],
        ]);
        $db->table('jadwal')->insertBatch([
            ['id_jadwal' => 1, 'mata_pelajaran' => 'Matematika', 'id_kelas' => 1, 'id_guru' => self::GURU, 'hari' => 'senin',
                'jam_mulai' => '07:00:00', 'jam_selesai' => '08:30:00', 'ruangan' => 'R-101'],
            ['id_jadwal' => 2, 'mata_pelajaran' => 'Bahasa Indonesia', 'id_kelas' => 2, 'id_guru' => self::GURU2, 'hari' => 'senin',
                'jam_mulai' => '07:00:00', 'jam_selesai' => '08:30:00', 'ruangan' => 'R-102'],
            ['id_jadwal' => 3, 'mata_pelajaran' => 'Pemrograman Web', 'id_kelas' => 1, 'id_guru' => self::GURU2, 'hari' => 'selasa',
                'jam_mulai' => '10:00:00', 'jam_selesai' => '12:00:00', 'ruangan' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    public function testListIsOrderedByDayThenTimeInFrontendFormat(): void
    {
        $response = $this->asGuru()->get('/api/jadwal');

        $response->assertStatus(200);
        $body = $this->body($response);
        $this->assertSame([1, 2, 3], array_column($body['data'], 'id_jadwal'));
        $this->assertSame([
            'id_jadwal' => 1, 'mata_pelajaran' => 'Matematika', 'id_kelas' => 1, 'nama_kelas' => 'X RPL 1',
            'id_guru' => self::GURU, 'nama_guru' => 'Bu Ani', 'hari' => 'senin',
            'jam_mulai' => '07:00', 'jam_selesai' => '08:30', 'ruangan' => 'R-101',
        ], $body['data'][0]);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 3, 'from' => 1, 'to' => 3], $body['meta']);
    }

    public function testListFilters(): void
    {
        $this->assertSame([3], $this->ids($this->asGuru()->get('/api/jadwal?search=web')));
        $this->assertSame([3], $this->ids($this->asGuru()->get('/api/jadwal?hari=selasa')));
        $this->assertSame([1, 3], $this->ids($this->asGuru()->get('/api/jadwal?id_kelas=1')));
        $this->assertSame([1], $this->ids($this->asGuru()->get('/api/jadwal?id_kelas=1&hari=senin')));
        $this->asGuru()->get('/api/jadwal?id_kelas=abc')->assertStatus(422);
    }

    public function testShowAnd404(): void
    {
        $this->assertSame('Pemrograman Web', $this->body($this->asSiswa()->get('/api/jadwal/3'))['data']['mata_pelajaran']);
        $this->asGuru()->get('/api/jadwal/99')->assertStatus(404);
    }

    public function testGuruCanCreateAndUpdate(): void
    {
        $created = $this->jsonCall('post', '/api/jadwal', $this->payload());
        $created->assertStatus(201);
        $data = $this->body($created)['data'];
        $this->assertSame(['Fisika', 2, 'X RPL 2', 'Bu Ani', 'rabu', '09:00', '10:30', 'Lab IPA'], [
            $data['mata_pelajaran'], $data['id_kelas'], $data['nama_kelas'], $data['nama_guru'],
            $data['hari'], $data['jam_mulai'], $data['jam_selesai'], $data['ruangan'],
        ]);

        $updated = $this->jsonCall('put', '/api/jadwal/' . $data['id_jadwal'], $this->payload(['jam_mulai' => '13:00', 'jam_selesai' => '14:00', 'ruangan' => '']));
        $updated->assertStatus(200);
        $this->assertSame(['13:00', '14:00', null], [
            $this->body($updated)['data']['jam_mulai'], $this->body($updated)['data']['jam_selesai'], $this->body($updated)['data']['ruangan'],
        ]);
    }

    public function testValidationErrors(): void
    {
        $response = $this->jsonCall('post', '/api/jadwal', [
            'mata_pelajaran' => '', 'id_kelas' => '99', 'id_guru' => 'bukan-guru', 'hari' => 'libur',
            'jam_mulai' => '10:00', 'jam_selesai' => '09:00',
        ]);

        $response->assertStatus(422);
        $this->assertSame(['mata_pelajaran', 'id_kelas', 'id_guru', 'hari', 'jam_selesai'], array_keys($this->body($response)['errors']));
    }

    public function testClassConflict(): void
    {
        $response = $this->jsonCall('post', '/api/jadwal', $this->payload([
            'id_kelas' => '1', 'id_guru' => self::GURU2, 'hari' => 'senin', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'ruangan' => 'R-200',
        ]));

        $response->assertStatus(409);
        $body = $this->body($response);
        $this->assertSame(['id_kelas'], array_keys($body['errors']));
        $this->assertStringContainsString('Matematika (X RPL 1, 07:00–08:30)', $body['message']);
    }

    public function testTeacherConflict(): void
    {
        $response = $this->jsonCall('post', '/api/jadwal', $this->payload([
            'id_kelas' => '2', 'id_guru' => self::GURU2, 'hari' => 'selasa', 'jam_mulai' => '11:00', 'jam_selesai' => '12:30',
        ]));

        $response->assertStatus(409);
        $this->assertSame(['id_guru'], array_keys($this->body($response)['errors']));
    }

    public function testRoomConflictIsCaseInsensitive(): void
    {
        $response = $this->jsonCall('post', '/api/jadwal', $this->payload([
            'hari' => 'senin', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'ruangan' => 'r-102', 'id_kelas' => '2',
            'id_guru' => self::GURU,
        ]));

        // Kelas 2 juga bentrok; yang dilaporkan bentrok pertama (kelas).
        $response->assertStatus(409);

        $room = $this->jsonCall('post', '/api/jadwal', $this->payload([
            'hari' => 'selasa', 'jam_mulai' => '07:00', 'jam_selesai' => '08:00', 'ruangan' => 'lab ipa',
        ]));
        $room->assertStatus(201);
        $again = $this->jsonCall('post', '/api/jadwal', $this->payload([
            'id_kelas' => '1', 'id_guru' => self::GURU2, 'hari' => 'selasa', 'jam_mulai' => '07:30', 'jam_selesai' => '08:00', 'ruangan' => 'LAB IPA',
        ]));
        $again->assertStatus(409);
        $this->assertSame(['ruangan'], array_keys($this->body($again)['errors']));
    }

    public function testBackToBackIsNotAConflictAndEditingSelfIsAllowed(): void
    {
        $this->jsonCall('post', '/api/jadwal', $this->payload([
            'id_kelas' => '1', 'hari' => 'senin', 'jam_mulai' => '08:30', 'jam_selesai' => '10:00', 'ruangan' => 'R-101',
        ]))->assertStatus(201);
        $this->jsonCall('put', '/api/jadwal/1', $this->payload([
            'mata_pelajaran' => 'Matematika Wajib', 'id_kelas' => '1', 'hari' => 'senin', 'jam_mulai' => '07:00', 'jam_selesai' => '08:30', 'ruangan' => 'R-101',
        ]))->assertStatus(200);
    }

    public function testDelete(): void
    {
        $this->asGuru()->delete('/api/jadwal/1')->assertStatus(200);
        $this->asGuru()->get('/api/jadwal/1')->assertStatus(404);
    }

    public function testSiswaCanReadButNotWrite(): void
    {
        $this->asSiswa()->get('/api/jadwal')->assertStatus(200);
        $this->asSiswa()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($this->payload()))->post('/api/jadwal')->assertStatus(403);
    }

    public function testGuestIsRejected(): void
    {
        $this->withHeaders([])->withBody('')->get('/api/jadwal')->assertStatus(401);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'mata_pelajaran' => 'Fisika', 'id_kelas' => '2', 'id_guru' => self::GURU, 'hari' => 'rabu',
            'jam_mulai' => '09:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab IPA',
        ];
    }

    private function asGuru(): self
    {
        return $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']]);
    }

    private function asSiswa(): self
    {
        return $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => self::SISWA, 'type' => 'siswa']]);
    }

    private function jsonCall(string $method, string $url, array $input)
    {
        return $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->call($method, $url);
    }

    private function body($response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function ids($response): array
    {
        $response->assertStatus(200);

        return array_column($this->body($response)['data'], 'id_jadwal');
    }
}
