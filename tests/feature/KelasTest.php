<?php

use App\Database\Migrations\AddClassHomeroomTeacher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-04-000002_AddClassHomeroomTeacher.php';

final class KelasTest extends CIUnitTestCase
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
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'kls_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE kls_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE kls_kelas (id_kelas INTEGER PRIMARY KEY AUTOINCREMENT, nama_kelas VARCHAR(100) NOT NULL,
            id_jurusan INTEGER, tahun_ajaran DATE, status VARCHAR(100))');
        $db->query('CREATE TABLE kls_siswa (id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36),
            nama_siswa TEXT NOT NULL, status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT,
            id_kelas INTEGER REFERENCES kls_kelas(id_kelas) ON DELETE SET NULL)');
        $db->query('CREATE TABLE kls_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('PRAGMA foreign_keys = ON');
        (new AddClassHomeroomTeacher(Database::forge($db)))->up();
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Guru', 'password' => 'x', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Siswa', 'password' => 'x', 'type' => 'siswa'],
        ]);
        $db->table('guru')->insertBatch([
            ['id_user' => self::GURU, 'nama' => 'Bu Ani', 'type' => 'guru', 'status' => 'ACTIVE'],
            ['id_user' => self::GURU2, 'nama' => 'Pak Budi', 'type' => 'guru', 'status' => 'ACTIVE'],
        ]);
        $db->table('kelas')->insertBatch([
            ['id_kelas' => 1, 'nama_kelas' => 'X RPL 1', 'id_jurusan' => 1, 'tahun_ajaran' => '2026-07-01', 'status' => 'aktif', 'id_wali_kelas' => self::GURU],
            ['id_kelas' => 2, 'nama_kelas' => 'X RPL 2', 'id_jurusan' => 1, 'tahun_ajaran' => '2026-07-01', 'status' => 'aktif', 'id_wali_kelas' => null],
            ['id_kelas' => 3, 'nama_kelas' => 'X TKJ 1', 'id_jurusan' => 2, 'tahun_ajaran' => '2026-07-01', 'status' => 'nonaktif', 'id_wali_kelas' => null],
        ]);
        $db->table('siswa')->insertBatch([
            ['id_user' => self::SISWA, 'nama_siswa' => 'Siswa', 'type' => 'siswa', 'id_kelas' => 1],
            ['id_user' => null, 'nama_siswa' => 'Siswa 2', 'type' => 'siswa', 'id_kelas' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    // ---- Positive -------------------------------------------------------------------

    public function testListReturnsPaginatedClassesWithStudentCounts(): void
    {
        $response = $this->asGuru()->get('/api/kelas');

        $response->assertStatus(200);
        $body = $this->body($response);
        $this->assertSame(['X RPL 1', 'X RPL 2', 'X TKJ 1'], array_column($body['data'], 'nama_kelas'));
        $this->assertSame([
            'id_kelas' => 1, 'nama_kelas' => 'X RPL 1', 'id_jurusan' => 1,
            'tahun_ajaran' => '2026-07-01', 'status' => 'aktif',
            'id_wali_kelas' => self::GURU, 'nama_wali_kelas' => 'Bu Ani', 'jumlah_siswa' => 2,
        ], $body['data'][0]);
        $this->assertSame(0, $body['data'][1]['jumlah_siswa']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 3, 'from' => 1, 'to' => 3], $body['meta']);
    }

    public function testListFiltersBySearchJurusanAndStatus(): void
    {
        $this->assertSame([1, 2], $this->ids($this->asGuru()->get('/api/kelas?search=rpl')));
        $this->assertSame([3], $this->ids($this->asGuru()->get('/api/kelas?id_jurusan=2')));
        $this->assertSame([3], $this->ids($this->asGuru()->get('/api/kelas?status=nonaktif')));
        $this->assertSame([], $this->ids($this->asGuru()->get('/api/kelas?search=IPA')));
        $this->assertSame([1, 2, 3], $this->ids($this->asGuru()->get('/api/kelas?search=%20%20')));
    }

    public function testSearchTreatsLikeWildcardsLiterally(): void
    {
        $this->assertSame([], $this->ids($this->asGuru()->get('/api/kelas?search=%25')));
        $this->assertSame([], $this->ids($this->asGuru()->get('/api/kelas?search=_')));
    }

    public function testPaginationBeyondLastPageReturnsEmptyData(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->isolatedDatabase->table('kelas')->insert(['nama_kelas' => sprintf('Z %02d', $i)]);
        }

        $page2 = $this->body($this->asGuru()->get('/api/kelas?page=2'));
        $this->assertCount(3, $page2['data']);
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 28, 'from' => 26, 'to' => 28], $page2['meta']);
        $page3 = $this->body($this->asGuru()->get('/api/kelas?page=3'));
        $this->assertSame([], $page3['data']);
        $this->assertNull($page3['meta']['from']);
    }

    public function testDetailReturnsOneClass(): void
    {
        $response = $this->asGuru()->get('/api/kelas/1');
        $response->assertStatus(200);
        $this->assertSame(2, $this->body($response)['data']['jumlah_siswa']);
    }

    public function testStudentsCanReadButNotWrite(): void
    {
        $this->asSiswa()->get('/api/kelas')->assertStatus(200);
        $this->asSiswa()->get('/api/kelas/1')->assertStatus(200);
        $this->asSiswa()->post('/api/kelas', ['nama_kelas' => 'Baru'])->assertStatus(403);
        $this->asSiswa()->call('put', '/api/kelas/1', ['nama_kelas' => 'Ubah'])->assertStatus(403);
        $this->asSiswa()->delete('/api/kelas/2')->assertStatus(403);
        $this->assertSame(3, $this->isolatedDatabase->table('kelas')->countAllResults());
    }

    /** @dataProvider formats */
    public function testGuruCanCreateAClass(bool $json): void
    {
        $input = ['nama_kelas' => '  XI RPL 1  ', 'id_jurusan' => $json ? 1 : '1', 'tahun_ajaran' => '2026-07-01', 'id_kelas' => 99];
        $response = $json ? $this->jsonCall('post', '/api/kelas', $input) : $this->asGuru()->post('/api/kelas', $input);

        $response->assertStatus(201);
        $data = $this->body($response)['data'];
        $this->assertSame(4, $data['id_kelas']);
        $this->assertSame([
            'id_kelas' => 4, 'nama_kelas' => 'XI RPL 1', 'id_jurusan' => 1,
            'tahun_ajaran' => '2026-07-01', 'status' => 'aktif',
            'id_wali_kelas' => null, 'nama_wali_kelas' => null, 'jumlah_siswa' => 0,
        ], $data);
    }

    public static function formats(): array
    {
        return ['json' => [true], 'form' => [false]];
    }

    public function testCreateWithOnlyNameLeavesOptionalFieldsNull(): void
    {
        $data = $this->body($this->jsonCall('post', '/api/kelas', ['nama_kelas' => 'XII AKL 1', 'id_wali_kelas' => null]))['data'];
        $this->assertNull($data['id_jurusan']);
        $this->assertNull($data['tahun_ajaran']);
        $this->assertSame('aktif', $data['status']);
    }

    /** @dataProvider updateMethods */
    public function testUpdateChangesOnlySentFields(string $method): void
    {
        $response = $this->jsonCall($method, '/api/kelas/2', ['nama_kelas' => 'X RPL 2B', 'status' => 'NONAKTIF']);

        $response->assertStatus(200);
        $this->assertSame([
            'id_kelas' => 2, 'nama_kelas' => 'X RPL 2B', 'id_jurusan' => 1,
            'tahun_ajaran' => '2026-07-01', 'status' => 'nonaktif',
            'id_wali_kelas' => null, 'nama_wali_kelas' => null, 'jumlah_siswa' => 0,
        ], $this->body($response)['data']);
    }

    public static function updateMethods(): array
    {
        return ['put' => ['put'], 'patch' => ['patch']];
    }

    public function testUpdateViaFormUrlEncodedAndClearingOptionalFields(): void
    {
        $response = $this->asGuru()->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withBody('id_jurusan=&tahun_ajaran=')->call('put', '/api/kelas/1');

        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertNull($data['id_jurusan']);
        $this->assertNull($data['tahun_ajaran']);
        $this->assertSame('X RPL 1', $data['nama_kelas']);
    }

    public function testRenamingAClassToItsOwnNameDifferentCaseIsAllowed(): void
    {
        $this->jsonCall('put', '/api/kelas/1', ['nama_kelas' => 'x rpl 1'])->assertStatus(200);
    }

    public function testEmptyClassCanBeDeleted(): void
    {
        $this->asGuru()->delete('/api/kelas/2')->assertStatus(200);
        $this->assertNull($this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->get()->getRowArray());
        $this->asGuru()->get('/api/kelas/2')->assertStatus(404);
    }

    // ---- Wali kelas -----------------------------------------------------------------

    public function testCreateWithHomeroomTeacherReturnsTeacherName(): void
    {
        $response = $this->jsonCall('post', '/api/kelas', ['nama_kelas' => 'XI RPL 1', 'id_wali_kelas' => ' ' . self::GURU2 . ' ']);

        $response->assertStatus(201);
        $data = $this->body($response)['data'];
        $this->assertSame(self::GURU2, $data['id_wali_kelas']);
        $this->assertSame('Pak Budi', $data['nama_wali_kelas']);
    }

    public function testUppercaseTeacherIdIsStoredAsTheCanonicalId(): void
    {
        $data = $this->body($this->jsonCall('put', '/api/kelas/2', ['id_wali_kelas' => strtoupper(self::GURU2)]))['data'];
        $this->assertSame(self::GURU2, $data['id_wali_kelas']);
    }

    public function testHomeroomTeacherCanBeChangedAndCleared(): void
    {
        $this->jsonCall('put', '/api/kelas/1', ['nama_kelas' => 'X RPL 1', 'id_wali_kelas' => self::GURU2])->assertStatus(200);
        $this->assertSame('Pak Budi', $this->body($this->asGuru()->get('/api/kelas/1'))['data']['nama_wali_kelas']);

        // Form frontend mengirim null atau string kosong saat wali kelas dikosongkan.
        foreach ([null, ''] as $empty) {
            $this->jsonCall('put', '/api/kelas/1', ['id_wali_kelas' => self::GURU])->assertStatus(200);
            $data = $this->body($this->jsonCall('put', '/api/kelas/1', ['nama_kelas' => 'X RPL 1', 'id_wali_kelas' => $empty]))['data'];
            $this->assertNull($data['id_wali_kelas']);
            $this->assertNull($data['nama_wali_kelas']);
        }
    }

    public function testResavingTheSameHomeroomTeacherIsNotAConflict(): void
    {
        $this->jsonCall('put', '/api/kelas/1', ['nama_kelas' => 'X RPL 1', 'id_wali_kelas' => self::GURU])->assertStatus(200);
    }

    public function testTeacherCannotBeHomeroomOfTwoClasses(): void
    {
        $create = $this->jsonCall('post', '/api/kelas', ['nama_kelas' => 'XI RPL 1', 'id_wali_kelas' => self::GURU]);
        $create->assertStatus(409);
        $this->assertArrayHasKey('id_wali_kelas', $this->body($create)['errors']);
        $this->jsonCall('put', '/api/kelas/2', ['id_wali_kelas' => self::GURU])->assertStatus(409);
        $this->assertNull($this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->get()->getRow()->id_wali_kelas);
    }

    public function testDatabaseUniqueKeyBlocksASecondHomeroomAssignment(): void
    {
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->update(['id_wali_kelas' => self::GURU]);
    }

    public function testDeletingTheTeacherKeepsTheClassWithoutHomeroom(): void
    {
        $this->isolatedDatabase->table('guru')->where('id_user', self::GURU)->delete();

        $data = $this->body($this->asGuru()->get('/api/kelas/1'))['data'];
        $this->assertNull($data['id_wali_kelas']);
        $this->assertNull($data['nama_wali_kelas']);
    }

    public function testFilterAndSearchStillWorkWithTheTeacherJoin(): void
    {
        $this->assertSame([1], $this->ids($this->asGuru()->get('/api/kelas?search=rpl%201')));
    }

    public function testTeacherListExposesIdGuruForTheHomeroomDropdown(): void
    {
        $rows = $this->body($this->asGuru()->get('/api/data_guru'))['data'];
        foreach ($rows as $row) {
            $this->assertSame($row['id_user'], $row['id_guru']);
        }
        $this->assertSame(self::GURU2, $this->body($this->asGuru()->get('/api/data_guru?id_user=' . self::GURU2))['data']['id_guru']);
    }

    // ---- Negative -------------------------------------------------------------------

    public function testAnonymousRequestsAreRejected(): void
    {
        $this->withSession([])->get('/api/kelas')->assertStatus(401);
        $this->withSession([])->post('/api/kelas', ['nama_kelas' => 'Baru'])->assertStatus(401);
    }

    /** @dataProvider invalidPayloads */
    public function testCreateRejectsInvalidPayloads(array $input, string $field): void
    {
        $response = $this->jsonCall('post', '/api/kelas', $input);

        $response->assertStatus(422);
        $this->assertArrayHasKey($field, $this->body($response)['errors']);
        $this->assertSame(3, $this->isolatedDatabase->table('kelas')->countAllResults());
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['id_jurusan' => 1], 'nama_kelas'],
            'blank name' => [['nama_kelas' => '   '], 'nama_kelas'],
            'numeric name' => [['nama_kelas' => 10], 'nama_kelas'],
            'array name' => [['nama_kelas' => ['X']], 'nama_kelas'],
            'name too long' => [['nama_kelas' => str_repeat('A', 101)], 'nama_kelas'],
            'name newline' => [['nama_kelas' => "X\nRPL"], 'nama_kelas'],
            'jurusan zero' => [['nama_kelas' => 'A', 'id_jurusan' => 0], 'id_jurusan'],
            'jurusan negative' => [['nama_kelas' => 'A', 'id_jurusan' => -1], 'id_jurusan'],
            'jurusan float' => [['nama_kelas' => 'A', 'id_jurusan' => 1.5], 'id_jurusan'],
            'jurusan text' => [['nama_kelas' => 'A', 'id_jurusan' => 'satu'], 'id_jurusan'],
            'jurusan overflow' => [['nama_kelas' => 'A', 'id_jurusan' => 2147483648], 'id_jurusan'],
            'jurusan bool' => [['nama_kelas' => 'A', 'id_jurusan' => true], 'id_jurusan'],
            'tahun format' => [['nama_kelas' => 'A', 'tahun_ajaran' => '2026/2027'], 'tahun_ajaran'],
            'tahun impossible' => [['nama_kelas' => 'A', 'tahun_ajaran' => '2026-02-30'], 'tahun_ajaran'],
            'status unknown' => [['nama_kelas' => 'A', 'status' => 'lulus'], 'status'],
            'status null' => [['nama_kelas' => 'A', 'status' => null], 'status'],
            'wali unknown' => [['nama_kelas' => 'A', 'id_wali_kelas' => '99999999-9999-4999-8999-999999999999'], 'id_wali_kelas'],
            'wali malformed' => [['nama_kelas' => 'A', 'id_wali_kelas' => 'bukan-uuid'], 'id_wali_kelas'],
            'wali number' => [['nama_kelas' => 'A', 'id_wali_kelas' => 5], 'id_wali_kelas'],
            'wali array' => [['nama_kelas' => 'A', 'id_wali_kelas' => [self::GURU2]], 'id_wali_kelas'],
            'wali student account' => [['nama_kelas' => 'A', 'id_wali_kelas' => self::SISWA], 'id_wali_kelas'],
        ];
    }

    public function testDuplicateNameIsAConflictCaseInsensitively(): void
    {
        $response = $this->jsonCall('post', '/api/kelas', ['nama_kelas' => ' x rpl 1 ']);
        $response->assertStatus(409);
        $this->assertArrayHasKey('nama_kelas', $this->body($response)['errors']);

        $this->jsonCall('put', '/api/kelas/2', ['nama_kelas' => 'X RPL 1'])->assertStatus(409);
        $this->assertSame('X RPL 2', $this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->get()->getRow()->nama_kelas);
    }

    /** @dataProvider malformedBodies */
    public function testMalformedJsonIsRejected(string $body): void
    {
        $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])->withBody($body)
            ->post('/api/kelas')->assertStatus(400);
    }

    public static function malformedBodies(): array
    {
        return ['broken' => ['{"nama_kelas":'], 'list' => ['["X"]'], 'null' => ['null'], 'string' => ['"X"']];
    }

    public function testUpdateWithoutFieldsIsRejected(): void
    {
        $this->jsonCall('put', '/api/kelas/1', ['id_kelas' => 5])->assertStatus(422);
    }

    public function testClassWithStudentsCannotBeDeleted(): void
    {
        $this->asGuru()->delete('/api/kelas/1')->assertStatus(409);
        $this->assertSame(2, $this->isolatedDatabase->table('siswa')->where('id_kelas', 1)->countAllResults());
    }

    /** @dataProvider unknownOrMalformedIds */
    public function testUnknownOrMalformedIds(string $id, int $status): void
    {
        $this->asGuru()->get('/api/kelas/' . $id)->assertStatus($status);
        $this->jsonCall('put', '/api/kelas/' . $id, ['nama_kelas' => 'Baru'])->assertStatus($status);
        $this->asGuru()->delete('/api/kelas/' . $id)->assertStatus($status);
    }

    public static function unknownOrMalformedIds(): array
    {
        return [
            'unknown' => ['999', 404], 'zero' => ['0', 422], 'leading zero' => ['01', 422],
            'text' => ['abc', 422], 'overflow' => ['2147483648', 422], 'negative' => ['-1', 422],
        ];
    }

    /** @dataProvider invalidListFilters */
    public function testListRejectsInvalidFilters(string $query, string $field): void
    {
        $response = $this->asGuru()->get('/api/kelas?' . $query);
        $response->assertStatus(422);
        $this->assertArrayHasKey($field, $this->body($response)['errors']);
    }

    public static function invalidListFilters(): array
    {
        return [
            'page zero' => ['page=0', 'page'], 'page text' => ['page=a', 'page'], 'page array' => ['page[]=1', 'page'],
            'search array' => ['search[]=x', 'search'], 'search long' => ['search=' . str_repeat('a', 101), 'search'],
            'jurusan plus' => ['id_jurusan=%2B1', 'id_jurusan'], 'status' => ['status=lulus', 'status'],
        ];
    }

    public function testDatabaseFailureReturnsGenericJsonError(): void
    {
        $this->isolatedDatabase->query('DROP TABLE kls_siswa');
        $response = $this->asGuru()->get('/api/kelas');
        $response->assertStatus(500);
        $this->assertSame('Terjadi kesalahan saat memproses data kelas.', $this->body($response)['message']);
    }

    public function testCorsPreflightAllowsPutAndDelete(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'DELETE',
        ])->options('/api/kelas/1');
        $this->assertStringContainsString('DELETE', $response->response()->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('PUT', $response->response()->getHeaderLine('Access-Control-Allow-Methods'));
    }

    // ---- Helpers --------------------------------------------------------------------

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

        return array_column($this->body($response)['data'], 'id_kelas');
    }
}
