<?php

use App\Database\Migrations\CreateEkstrakurikuler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-05-000001_CreateEkstrakurikuler.php';

final class EkstrakurikulerTest extends CIUnitTestCase
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
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'eks_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE eks_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE eks_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('PRAGMA foreign_keys = ON');
        (new CreateEkstrakurikuler(Database::forge($db)))->up();
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Guru', 'password' => 'x', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Siswa', 'password' => 'x', 'type' => 'siswa'],
        ]);
        $db->table('guru')->insertBatch([
            ['id_user' => self::GURU, 'nama' => 'Bu Ani', 'type' => 'guru', 'status' => 'ACTIVE'],
            ['id_user' => self::GURU2, 'nama' => 'Pak Budi', 'type' => 'guru', 'status' => 'ACTIVE'],
        ]);
        $db->table('ekstrakurikuler')->insertBatch([
            ['id_ekstrakurikuler' => 1, 'nama_ekstrakurikuler' => 'Pramuka', 'id_pembina' => self::GURU, 'hari' => 'jumat',
                'jam_mulai' => '14:00:00', 'jam_selesai' => '16:00:00', 'lokasi' => 'Lapangan', 'deskripsi' => null, 'status' => 'ACTIVE'],
            ['id_ekstrakurikuler' => 2, 'nama_ekstrakurikuler' => 'Futsal', 'id_pembina' => null, 'hari' => 'rabu',
                'jam_mulai' => '15:00', 'jam_selesai' => '17:00', 'lokasi' => null, 'deskripsi' => null, 'status' => 'INACTIVE'],
        ]);
        $db->table('ekstrakurikuler_foto')->insertBatch([
            ['id_foto' => 1, 'id_ekstrakurikuler' => 1, 'file_name' => str_repeat('a', 32) . '.jpg'],
            ['id_foto' => 2, 'id_ekstrakurikuler' => 2, 'file_name' => str_repeat('b', 32) . '.webp'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    public function testListReturnsPaginatedRecordsInFrontendFormat(): void
    {
        $response = $this->asGuru()->get('/api/ekstrakurikuler');

        $response->assertStatus(200);
        $body = $this->body($response);
        $this->assertSame(['Futsal', 'Pramuka'], array_column($body['data'], 'nama_ekstrakurikuler'));
        $this->assertSame([
            'id_ekstrakurikuler' => 1, 'nama_ekstrakurikuler' => 'Pramuka',
            'id_pembina' => self::GURU, 'nama_pembina' => 'Bu Ani', 'hari' => 'jumat',
            'jam_mulai' => '14:00', 'jam_selesai' => '16:00', 'lokasi' => 'Lapangan', 'deskripsi' => null,
            'status' => 'ACTIVE', 'jumlah_anggota' => null,
            'foto' => [['id_foto' => '1', 'url' => '/uploads/ekstrakurikuler/' . str_repeat('a', 32) . '.jpg', 'caption' => 'Pramuka']],
        ], $body['data'][1]);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 2, 'from' => 1, 'to' => 2], $body['meta']);
    }

    public function testListFiltersBySearchAndDay(): void
    {
        $this->assertSame([1], $this->ids($this->asGuru()->get('/api/ekstrakurikuler?search=pram')));
        $this->assertSame([2], $this->ids($this->asGuru()->get('/api/ekstrakurikuler?hari=rabu')));
        $this->assertSame([], $this->ids($this->asGuru()->get('/api/ekstrakurikuler?search=%25')));
        $this->asGuru()->get('/api/ekstrakurikuler?hari=libur')->assertStatus(422);
    }

    public function testPhotosEndpointOnlyReturnsActiveExtracurriculars(): void
    {
        $response = $this->asSiswa()->get('/api/ekstrakurikuler/foto?per_page=5');

        $response->assertStatus(200);
        $this->assertSame([
            ['id_foto' => '1', 'url' => '/uploads/ekstrakurikuler/' . str_repeat('a', 32) . '.jpg', 'caption' => 'Pramuka'],
        ], $this->body($response)['data']);
    }

    public function testShowReturnsRecordOr404(): void
    {
        $response = $this->asSiswa()->get('/api/ekstrakurikuler/2');
        $response->assertStatus(200);
        $this->assertSame('Futsal', $this->body($response)['data']['nama_ekstrakurikuler']);

        $this->asGuru()->get('/api/ekstrakurikuler/99')->assertStatus(404);
        $this->asGuru()->get('/api/ekstrakurikuler/abc')->assertStatus(422);
    }

    public function testGuruCanCreateWithJson(): void
    {
        $response = $this->jsonCall('post', '/api/ekstrakurikuler', $this->payload(['nama_ekstrakurikuler' => '  Paskibra ']));

        $response->assertStatus(201);
        $data = $this->body($response)['data'];
        $this->assertSame('Paskibra', $data['nama_ekstrakurikuler']);
        $this->assertSame(self::GURU2, $data['id_pembina']);
        $this->assertSame('Pak Budi', $data['nama_pembina']);
        $this->assertSame(['senin', '13:00', '15:00'], [$data['hari'], $data['jam_mulai'], $data['jam_selesai']]);
        $this->assertSame([], $data['foto']);
    }

    public function testGuruCanUpdateWithJson(): void
    {
        $response = $this->jsonCall('put', '/api/ekstrakurikuler/2', $this->payload([
            'nama_ekstrakurikuler' => 'Futsal Putra', 'id_pembina' => '', 'status' => 'ACTIVE',
        ]));

        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame([2, 'Futsal Putra', null, 'ACTIVE'], [$data['id_ekstrakurikuler'], $data['nama_ekstrakurikuler'], $data['id_pembina'], $data['status']]);
        // Foto lama tetap ada bila tidak diminta dihapus.
        $this->assertCount(1, $data['foto']);
    }

    public function testValidationErrorsUseFrontendFieldNames(): void
    {
        $response = $this->jsonCall('post', '/api/ekstrakurikuler', [
            'nama_ekstrakurikuler' => '', 'id_pembina' => 'bukan-guru', 'hari' => 'libur',
            'jam_mulai' => '15:00', 'jam_selesai' => '14:00', 'status' => 'AKTIF',
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ['nama_ekstrakurikuler', 'id_pembina', 'hari', 'jam_selesai', 'status'],
            array_keys($this->body($response)['errors']),
        );
    }

    public function testDuplicateNameIsRejectedCaseInsensitively(): void
    {
        $response = $this->jsonCall('post', '/api/ekstrakurikuler', $this->payload(['nama_ekstrakurikuler' => 'PRAMUKA']));

        $response->assertStatus(422);
        $this->assertArrayHasKey('nama_ekstrakurikuler', $this->body($response)['errors']);
        // Mengubah data sendiri tanpa ganti nama tetap boleh.
        $this->jsonCall('put', '/api/ekstrakurikuler/1', $this->payload(['nama_ekstrakurikuler' => 'Pramuka']))->assertStatus(200);
    }

    public function testCannotRemovePhotoOwnedByAnotherExtracurricular(): void
    {
        $response = $this->jsonCall('put', '/api/ekstrakurikuler/1', $this->payload([
            'nama_ekstrakurikuler' => 'Pramuka', 'hapus_foto' => ['2'],
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('hapus_foto', $this->body($response)['errors']);
        $this->assertSame(2, $this->isolatedDatabase->table('ekstrakurikuler_foto')->countAllResults());
    }

    public function testGuruCanRemoveOwnPhoto(): void
    {
        $response = $this->jsonCall('put', '/api/ekstrakurikuler/1', $this->payload([
            'nama_ekstrakurikuler' => 'Pramuka', 'hapus_foto' => ['1'],
        ]));

        $response->assertStatus(200);
        $this->assertSame([], $this->body($response)['data']['foto']);
    }

    public function testDeleteRemovesRecordAndPhotos(): void
    {
        $this->asGuru()->delete('/api/ekstrakurikuler/1')->assertStatus(200);

        $this->asGuru()->get('/api/ekstrakurikuler/1')->assertStatus(404);
        $this->assertSame(0, $this->isolatedDatabase->table('ekstrakurikuler_foto')->where('id_ekstrakurikuler', 1)->countAllResults());
    }

    public function testSiswaCanReadButNotWrite(): void
    {
        $this->asSiswa()->get('/api/ekstrakurikuler')->assertStatus(200);
        $this->asSiswa()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($this->payload()))->post('/api/ekstrakurikuler')->assertStatus(403);
        $this->asSiswa()->delete('/api/ekstrakurikuler/1')->assertStatus(403);
    }

    public function testGuestIsRejected(): void
    {
        $this->withHeaders([])->withBody('')->get('/api/ekstrakurikuler')->assertStatus(401);
        $this->withHeaders([])->withBody('')->get('/api/ekstrakurikuler/foto')->assertStatus(401);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'nama_ekstrakurikuler' => 'Paskibra', 'id_pembina' => self::GURU2, 'hari' => 'senin',
            'jam_mulai' => '13:00', 'jam_selesai' => '15:00', 'lokasi' => 'Aula', 'deskripsi' => null, 'status' => 'ACTIVE',
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

        return array_column($this->body($response)['data'], 'id_ekstrakurikuler');
    }
}
