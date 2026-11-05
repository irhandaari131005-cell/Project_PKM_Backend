<?php

use App\Database\Migrations\AddProfileFields;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-05-000002_AddProfileFields.php';

final class ProfileTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GURU = '11111111-1111-4111-8111-111111111111';
    private const SISWA = '22222222-2222-4222-8222-222222222222';
    private const SISWA_TANPA_DATA = '33333333-3333-4333-8333-333333333333';

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'prf_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE prf_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE prf_kelas (id_kelas INTEGER PRIMARY KEY AUTOINCREMENT, nama_kelas VARCHAR(100) NOT NULL,
            id_jurusan INTEGER, tahun_ajaran DATE, status VARCHAR(100))');
        $db->query('CREATE TABLE prf_siswa (id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36),
            nama_siswa TEXT NOT NULL, status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT,
            id_kelas INTEGER REFERENCES prf_kelas(id_kelas) ON DELETE SET NULL)');
        $db->query('CREATE TABLE prf_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        (new AddProfileFields(Database::forge($db)))->up();
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Bu Ani', 'password' => 'x', 'sn' => 'G-01', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Andi', 'password' => 'x', 'sn' => 'S-01', 'type' => 'siswa'],
            ['id_user' => self::SISWA_TANPA_DATA, 'username' => 'siswa2', 'nama' => 'Budi', 'password' => 'x', 'sn' => null, 'type' => 'siswa'],
        ]);
        $db->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1', 'status' => 'aktif']);
        $db->table('guru')->insert([
            'id_user' => self::GURU, 'nama' => 'Bu Ani', 'sn' => 'G-01', 'type' => 'guru', 'status' => 'ACTIVE',
            'alamat' => 'Jl. Lama', 'foto' => str_repeat('c', 32) . '.jpg',
        ]);
        $db->table('siswa')->insert([
            'id_user' => self::SISWA, 'nama_siswa' => 'Andi', 'status' => 'ACTIVE', 'sn' => 'S-01', 'type' => 'siswa', 'id_kelas' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    public function testGuruSeesOwnProfile(): void
    {
        $response = $this->as(self::GURU, 'guru')->get('/api/profile');

        $response->assertStatus(200);
        $this->assertSame([
            'id_user' => self::GURU, 'username' => 'guru', 'type' => 'guru', 'nama' => 'Bu Ani', 'sn' => 'G-01',
            'status' => 'ACTIVE', 'tempat_lahir' => null, 'tanggal_lahir' => null, 'alamat' => 'Jl. Lama', 'no_telp' => null,
            'foto_url' => '/uploads/profil/' . str_repeat('c', 32) . '.jpg',
        ], $this->body($response)['data']);
    }

    public function testSiswaSeesOwnProfileWithClass(): void
    {
        $data = $this->body($this->as(self::SISWA, 'siswa')->get('/api/profile'))['data'];

        $this->assertSame(['Andi', 'S-01', 'X RPL 1', null], [$data['nama'], $data['sn'], $data['nama_kelas'], $data['foto_url']]);
    }

    public function testGuruCanUpdateProfileAndNameSyncsToAccount(): void
    {
        $response = $this->jsonPut(self::GURU, 'guru', [
            'nama' => '  Ani Lestari, S.Pd. ', 'tempat_lahir' => 'Bandung', 'tanggal_lahir' => '1990-02-28',
            'alamat' => "Jl. Merdeka 1\nBandung", 'no_telp' => '0812-3456 7890',
        ]);

        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(
            ['Ani Lestari, S.Pd.', 'Bandung', '1990-02-28', "Jl. Merdeka 1\nBandung", '081234567890'],
            [$data['nama'], $data['tempat_lahir'], $data['tanggal_lahir'], $data['alamat'], $data['no_telp']],
        );
        $this->assertSame('Ani Lestari, S.Pd.', $this->isolatedDatabase->table('users')->where('id_user', self::GURU)->get()->getRow()->nama);
        $this->assertSame('Ani Lestari, S.Pd.', $this->isolatedDatabase->table('guru')->where('id_user', self::GURU)->get()->getRow()->nama);
    }

    public function testSiswaCanUpdateOwnProfileButStillCannotWriteOtherData(): void
    {
        $response = $this->jsonPut(self::SISWA, 'siswa', ['nama' => 'Andi Pratama', 'no_telp' => '+6281234567890']);

        $response->assertStatus(200);
        $this->assertSame('Andi Pratama', $this->isolatedDatabase->table('siswa')->where('id_user', self::SISWA)->get()->getRow()->nama_siswa);
        $this->assertSame('+6281234567890', $this->body($response)['data']['no_telp']);

        $this->as(self::SISWA, 'siswa')->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['nama_kelas' => 'X'], JSON_THROW_ON_ERROR))->post('/api/kelas')->assertStatus(403);
    }

    public function testSnTypeStatusAndIdInBodyAreIgnored(): void
    {
        $this->jsonPut(self::SISWA, 'siswa', [
            'alamat' => 'Jl. Baru', 'sn' => 'KARTU-ORANG-LAIN', 'type' => 'guru', 'status' => 'INACTIVE', 'id_user' => self::GURU,
        ])->assertStatus(200);

        $siswa = $this->isolatedDatabase->table('siswa')->where('id_user', self::SISWA)->get()->getRowArray();
        $this->assertSame(['S-01', 'siswa', 'ACTIVE', 'Jl. Baru'], [$siswa['sn'], $siswa['type'], $siswa['status'], $siswa['alamat']]);
        // Profil guru tidak tersentuh.
        $this->assertSame('Jl. Lama', $this->isolatedDatabase->table('guru')->where('id_user', self::GURU)->get()->getRow()->alamat);
    }

    public function testPartialUpdateKeepsOtherFieldsAndEmptyClearsThem(): void
    {
        $this->jsonPut(self::GURU, 'guru', ['tempat_lahir' => 'Bogor'])->assertStatus(200);
        $data = $this->body($this->jsonPut(self::GURU, 'guru', ['alamat' => '']))['data'];

        $this->assertSame(['Bogor', null, 'Bu Ani'], [$data['tempat_lahir'], $data['alamat'], $data['nama']]);
    }

    public function testRemovePhoto(): void
    {
        $data = $this->body($this->jsonPut(self::GURU, 'guru', ['hapus_foto' => '1']))['data'];

        $this->assertNull($data['foto_url']);
    }

    public function testValidationErrors(): void
    {
        $response = $this->jsonPut(self::GURU, 'guru', [
            'nama' => '', 'tanggal_lahir' => '2999-01-01', 'no_telp' => '12ab', 'tempat_lahir' => 12,
        ]);

        $response->assertStatus(422);
        $this->assertSame(['nama', 'tempat_lahir', 'tanggal_lahir', 'no_telp'], array_keys($this->body($response)['errors']));
        $this->jsonPut(self::GURU, 'guru', ['tanggal_lahir' => '2001-02-30'])->assertStatus(422);
        $this->assertSame('Bu Ani', $this->isolatedDatabase->table('users')->where('id_user', self::GURU)->get()->getRow()->nama);
    }

    public function testSiswaWithoutStudentRecordGets409(): void
    {
        $this->as(self::SISWA_TANPA_DATA, 'siswa')->get('/api/profile')->assertStatus(200);
        $this->jsonPut(self::SISWA_TANPA_DATA, 'siswa', ['alamat' => 'X'])->assertStatus(409);
    }

    public function testGuestIsRejected(): void
    {
        $this->withHeaders([])->withBody('')->get('/api/profile')->assertStatus(401);
        $this->withHeaders(['Content-Type' => 'application/json'])->withBody('{"nama":"x"}')->put('/api/profile')->assertStatus(401);
    }

    private function as(string $id, string $type): self
    {
        return $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => $id, 'type' => $type]]);
    }

    private function jsonPut(string $id, string $type, array $input)
    {
        return $this->as($id, $type)->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->put('/api/profile');
    }

    private function body($response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }
}
