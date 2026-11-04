<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class DataSiswaTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;

    /** @var array<string, BaseConnection> */
    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Use a new in-memory connection regardless of local database settings.
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);

        $this->isolatedDatabase->query('CREATE TABLE siswa (
            id_siswa INTEGER PRIMARY KEY, nama_siswa TEXT, status TEXT,
            created_date TEXT, update_date TEXT, sn TEXT, type TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE users (
            id_user INTEGER PRIMARY KEY, username TEXT, nama TEXT, password TEXT, type TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE kelas (
            id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT, id_jurusan INTEGER,
            tahun_ajaran TEXT, status TEXT
        )');
        $this->isolatedDatabase->table('siswa')->insertBatch([
            ['id_siswa' => 1, 'nama_siswa' => 'Siswa Pertama', 'status' => 'aktif', 'sn' => 'S001', 'type' => 'siswa'],
            ['id_siswa' => 2, 'nama_siswa' => 'Siswa Kedua', 'status' => 'aktif', 'sn' => 'S002', 'type' => 'siswa'],
        ]);
        $this->isolatedDatabase->table('users')->insert([
            'id_user' => 1, 'username' => 'teacher', 'nama' => 'Guru', 'password' => 'must-not-be-returned', 'type' => 'guru',
        ]);
        $this->isolatedDatabase->table('kelas')->insertBatch([
            ['id_kelas' => 1, 'nama_kelas' => 'X RPL', 'id_jurusan' => 1],
            ['id_kelas' => 2, 'nama_kelas' => 'X TKJ', 'id_jurusan' => 2],
        ]);
        $this->withSession(['auth_user' => ['id_user' => 1, 'type' => 'guru']]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);

        parent::tearDown();
    }

    public function testStudentListReadsStudentsAndDoesNotExposeAccounts(): void
    {
        $response = $this->get('/api/data_siswa');

        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $body['data']);
        $this->assertSame(['Siswa Pertama', 'Siswa Kedua'], array_column($body['data'], 'nama_siswa'));
        $this->assertArrayNotHasKey('password', $body['data'][0]);
        $this->assertArrayNotHasKey('username', $body['data'][0]);
    }

    public function testStudentDetailUsesStudentId(): void
    {
        $response = $this->get('/api/data_siswa?id_siswa=2');

        $response->assertStatus(200);
        $response->assertJSONFragment(['data' => ['id_siswa' => 2, 'nama_siswa' => 'Siswa Kedua']]);
    }

    public function testMissingStudentReturnsNotFound(): void
    {
        $this->get('/api/data_siswa?id_siswa=999')->assertStatus(404);
    }

    /** @dataProvider invalidIdentifiers */
    public function testStudentIdRejectsInvalidInput(string $query): void
    {
        $response = $this->get('/api/data_siswa?' . $query);

        $response->assertStatus(400);
        $response->assertJSONFragment(['status' => 400]);
    }

    public static function invalidIdentifiers(): array
    {
        return [
            'zero' => ['id_siswa=0'],
            'negative' => ['id_siswa=-1'],
            'non-numeric' => ['id_siswa=abc'],
            'fraction' => ['id_siswa=1.5'],
            'empty' => ['id_siswa='],
            'array' => ['id_siswa[]=1'],
        ];
    }

    public function testClassListFiltersByDepartment(): void
    {
        $response = $this->get('/api/data_kelas?id_jurusan=2');

        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $body['data']);
        $this->assertSame('X TKJ', $body['data'][0]['nama_kelas']);
    }

    public function testClassListWithoutFilterReturnsAllClasses(): void
    {
        $response = $this->get('/api/data_kelas');

        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $body['data']);
    }

    public function testClassFilterWithNoMatchesReturnsEmptyList(): void
    {
        $response = $this->get('/api/data_kelas?id_jurusan=999');

        $response->assertStatus(200);
        $response->assertJSONFragment(['data' => []]);
    }

    public function testClassFilterRejectsInvalidDepartmentId(): void
    {
        $this->get('/api/data_kelas?id_jurusan[]=1')->assertStatus(400);
    }
}
