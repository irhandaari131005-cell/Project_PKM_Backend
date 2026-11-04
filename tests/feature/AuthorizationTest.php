<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class AuthorizationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'authz_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE authz_users (
            id_user VARCHAR(36) PRIMARY KEY, username TEXT UNIQUE, nama TEXT, password TEXT,
            sn TEXT UNIQUE, type TEXT, created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE authz_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT)');
        $this->isolatedDatabase->query('CREATE TABLE authz_siswa (
            id_siswa INTEGER PRIMARY KEY, id_user VARCHAR(36), id_kelas INTEGER,
            nama_siswa TEXT, status TEXT, sn TEXT, type TEXT, created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE authz_guru (
            id_user VARCHAR(36) PRIMARY KEY, nama TEXT, sn TEXT, type TEXT, status TEXT, created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE authz_absensi (
            id_absensi VARCHAR(36) PRIMARY KEY, id_user VARCHAR(36), sn TEXT, tanggal TEXT,
            check_in TEXT, check_out TEXT, created_date TEXT, update_date TEXT, UNIQUE(id_user, tanggal)
        )');
        $this->today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL']);
        foreach ([1 => 'siswa', 2 => 'siswa', 3 => 'guru'] as $id => $type) {
            $this->isolatedDatabase->table('users')->insert([
                'id_user' => $this->uuid($id), 'username' => 'user' . $id, 'nama' => 'User ' . $id,
                'password' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => '0000000' . $id, 'type' => $type,
            ]);
            $profile = ['id_user' => $this->uuid($id), 'sn' => '0000000' . $id, 'type' => $type];
            if ($type === 'siswa') {
                $profile += ['id_siswa' => $id, 'id_kelas' => 1, 'nama_siswa' => 'User ' . $id, 'status' => 'aktif'];
                $this->isolatedDatabase->table('absensi')->insert([
                    'id_absensi' => $this->uuid(10 + $id), 'id_user' => $this->uuid($id),
                    'sn' => $profile['sn'], 'tanggal' => $this->today,
                    'check_in' => $this->today . ' 00:00:00', 'check_out' => null,
                ]);
            } else {
                $profile['nama'] = 'User ' . $id;
            }
            $this->isolatedDatabase->table($type)->insert($profile);
        }
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    /** @dataProvider crossRoleLogins */
    public function testCrossRoleLoginClearsPreviousSession(int $id, string $type, bool $json): void
    {
        $this->asUser(3);
        $input = ['username' => 'user' . $id, 'password' => 'Password123!', 'type' => $type];
        $before = $this->snapshot();
        $result = $json ? $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/login')
            : $this->post('/api/login', $input);
        $result->assertStatus(403);
        $this->assertNull(session()->get('auth_user'));
        $this->assertSame($before, $this->snapshot());
        $this->withSession()->withHeaders([])->withBody('')->get('/api/me')->assertStatus(401);
    }

    public static function crossRoleLogins(): array
    {
        return [
            'siswa as guru JSON' => [1, 'guru', true], 'siswa as guru form' => [1, 'guru', false],
            'guru as siswa JSON' => [3, 'siswa', true], 'guru as siswa form' => [3, 'siswa', false],
        ];
    }

    /** @dataProvider invalidRoles */
    public function testLoginRequiresExplicitSupportedRole(mixed $type): void
    {
        $this->asUser(3);
        $input = ['username' => 'user1', 'password' => 'Password123!'];
        if ($type !== null) {
            $input['type'] = $type;
        }
        $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/login')->assertStatus(422);
        $this->assertNull(session()->get('auth_user'));
    }

    public static function invalidRoles(): array
    {
        return [
            'omitted' => [null], 'empty' => [''], 'blank' => ['   '], 'array' => [['guru']],
            'number' => [1], 'boolean' => [true], 'object' => [(object) ['type' => 'guru']],
            'admin' => ['admin'], 'unsupported parent' => ['wali_murid'], 'case' => ['Guru'],
        ];
    }

    /** @dataProvider studentWriteRoutes */
    public function testStudentCannotMutateDataEvenWithForgedRole(string $route): void
    {
        $this->asUser(1);
        $before = $this->snapshot();
        $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($this->registration('guru') + [
                'id_siswa' => 2, 'id_user' => $this->uuid(3), 'id_absensi' => $this->uuid(12),
                'auth_user' => ['id_user' => $this->uuid(3), 'type' => 'guru'],
            ], JSON_THROW_ON_ERROR))->post($route . '?type=guru&id_user=' . $this->uuid(3))->assertStatus(403);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('siswa', session()->get('auth_user')['type']);
    }

    public static function studentWriteRoutes(): array
    {
        return array_map(static fn ($route) => [$route], [
            '/api/register', '/api/absensi', '/api/absensi/tap',
            '/api/absensi/check-in', '/api/absensi/check-out',
        ]);
    }

    /** @dataProvider protectedReadRoutes */
    public function testAnonymousCannotReadPrivateData(string $route): void
    {
        $this->get($route . '?type=guru&auth_user[id_user]=' . $this->uuid(3))->assertStatus(401);
    }

    public static function protectedReadRoutes(): array
    {
        return array_map(static fn ($route) => [$route], [
            '/api/me', '/api/dashboard', '/api/data_siswa', '/api/data_guru',
            '/api/absensi', '/api/absensi/riwayat', '/api/absensi/00000000-0000-4000-8000-000000000011',
        ]);
    }

    /** @dataProvider studentWriteRoutes */
    public function testAnonymousCannotWriteAttendance(string $route): void
    {
        if ($route === '/api/register') {
            $this->post($route, $this->registration('guru'))->assertStatus(201);
            return;
        }
        $before = $this->snapshot();
        $this->post($route, ['sn' => '00000001', 'id_siswa' => 1, 'id_absensi' => $this->uuid(11)])
            ->assertStatus(401);
        $this->assertSame($before, $this->snapshot());
    }

    public function testStudentsCanOnlyReadTheirOwnRecordsAndCannotSeeRecoverySn(): void
    {
        $this->asUser(1);
        // Data siswa dibuka untuk seluruh siswa (lihat saja, tanpa SN).
        $students = $this->get('/api/data_siswa?type=guru');
        $students->assertStatus(200);
        $rows = json_decode($students->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'id_siswa')));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('sn', $row);
        }
        $other = $this->get('/api/data_siswa?id_siswa=2');
        $other->assertStatus(200);
        $this->assertStringNotContainsString('"sn"', $other->getJSON());
        $this->get('/api/data_siswa?id_siswa=999')->assertStatus(404);

        // Akun dan absensi tetap milik sendiri.
        foreach (['/api/dashboard', '/api/absensi', '/api/absensi/riwayat'] as $route) {
            $result = $this->get($route . '?type=guru&auth_user[type]=guru');
            $result->assertStatus(200);
            $body = json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(1, $body['data'], $route);
            $this->assertArrayNotHasKey('sn', $body['data'][0]);
            $this->assertStringNotContainsString('User 2', $result->getJSON());
            $this->assertStringNotContainsString('User 3', $result->getJSON());
            $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        }
        $this->get('/api/absensi/' . $this->uuid(11))->assertStatus(200);
        $this->get('/api/absensi/' . $this->uuid(12))->assertStatus(404);
        $this->get('/api/absensi?id_absensi=' . $this->uuid(12))->assertStatus(404);
        $this->get('/api/absensi?id_user=' . $this->uuid(2))->assertJSONFragment(['data' => []]);
        $this->get('/api/absensi?id_siswa=2')->assertJSONFragment(['data' => []]);
        // Siswa boleh melihat data guru (read-only) tanpa SN kartu yang dipakai untuk reset password.
        foreach (['/api/data_guru', '/api/data_guru?id_user=' . $this->uuid(3)] as $route) {
            $teachers = $this->get($route);
            $teachers->assertStatus(200);
            $this->assertStringContainsString('User 3', $teachers->getJSON());
            $this->assertStringNotContainsString('"sn"', $teachers->getJSON(), $route);
        }
    }

    /** @dataProvider teacherWriteAttempts */
    public function testStudentCannotWriteAnySchoolData(string $method, string $route): void
    {
        $this->asUser(1);
        $before = $this->snapshot();

        $result = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['nama' => 'Ubah', 'nama_kelas' => 'Ubah']))->call($method, $route);

        $result->assertStatus(403);
        $this->assertSame($before, $this->snapshot());
    }

    public static function teacherWriteAttempts(): array
    {
        return [
            'tambah kelas' => ['post', '/api/kelas'], 'ubah kelas' => ['put', '/api/kelas/1'],
            'patch kelas' => ['patch', '/api/kelas/1'], 'hapus kelas' => ['delete', '/api/kelas/1'],
            'check-in' => ['post', '/api/absensi/check-in'], 'tap' => ['post', '/api/absensi/tap'],
        ];
    }

    public function testTeacherCanReadAllRecordsAndCreateAnotherAccountWithoutChangingSession(): void
    {
        $this->asUser(3);
        $this->get('/api/data_siswa')->assertStatus(200);
        $this->get('/api/data_guru')->assertStatus(200);
        $result = $this->get('/api/dashboard');
        $result->assertStatus(200);
        $this->assertCount(3, json_decode($result->getJSON(), true)['data']);
        $this->post('/api/register', $this->registration('siswa'))->assertStatus(201);
        $this->assertSame($this->uuid(3), session()->get('auth_user')['id_user']);
        $this->assertSame(4, $this->isolatedDatabase->table('users')->countAllResults());
        $this->assertSame(3, $this->isolatedDatabase->table('siswa')->countAllResults());
        $this->post('/api/absensi/check-in', ['id_user' => $this->isolatedDatabase->table('users')
            ->where('username', 'new_user')->get()->getRowArray()['id_user']])->assertStatus(201);
        $this->post('/api/absensi/check-out', ['id_absensi' => $this->uuid(11)])->assertStatus(200);
        $this->post('/api/absensi/check-out', ['id_absensi' => $this->uuid(11)])->assertStatus(409);
    }

    public function testPublicTeacherRegistrationStillRequiresMatchingLoginRole(): void
    {
        $this->post('/api/register', $this->registration('guru'))->assertStatus(201);
        $this->assertNull(session()->get('auth_user'));
        $this->post('/api/login', ['username' => 'new_user', 'password' => 'Password123!', 'type' => 'siswa'])
            ->assertStatus(403);
        $this->withSession()->post('/api/login', [
            'username' => 'new_user', 'password' => 'Password123!', 'type' => ' guru ',
        ])->assertStatus(200);
        $this->assertSame('guru', session()->get('auth_user')['type']);
    }

    public function testDatabaseRoleChangeRevokesOldSession(): void
    {
        $this->asUser(3);
        $this->isolatedDatabase->table('users')->where('id_user', $this->uuid(3))->update(['type' => 'siswa']);
        // Sesi lama dibatalkan; register publik tetap bisa dipakai sebagai pengunjung anonim.
        $this->post('/api/register', $this->registration('guru'))->assertStatus(201);
        $this->assertNull(session()->get('auth_user'));
        $this->get('/api/me')->assertStatus(401);
    }

    public function testUnknownDatabaseRoleAndForgedSessionRoleCannotAuthorize(): void
    {
        $this->asUser(1);
        // Role sesi palsu tidak memberi hak guru: sesi dibuang dan request diperlakukan anonim.
        $this->withSession(['auth_user' => ['id_user' => $this->uuid(1), 'type' => 'guru']])
            ->post('/api/register', $this->registration('guru'))->assertStatus(201);
        $this->assertNull(session()->get('auth_user'));
        $this->isolatedDatabase->table('users')->where('id_user', $this->uuid(1))->update(['type' => 'admin']);
        $this->withSession()->post('/api/login', [
            'username' => 'user1', 'password' => 'Password123!', 'type' => 'guru',
        ])->assertStatus(403);
        $this->withSession(['auth_user' => ['id_user' => $this->uuid(1), 'type' => 'admin']])
            ->get('/api/me')->assertStatus(401);
    }

    public function testEmptyStudentListReturns200InsteadOf404(): void
    {
        $this->asUser(3);
        $this->isolatedDatabase->table('siswa')->emptyTable();
        $result = $this->get('/api/data_siswa');
        $result->assertStatus(200);
        $result->assertJSONFragment(['data' => []]);
    }

    /** @dataProvider writeMethods */
    public function testNewApiRoutesInheritWriteProtectionForEveryMethod(string $method): void
    {
        $this->withRoutes([[$method, 'api/permission-probe', static function () {
            db_connect()->table('users')->where('username', 'user2')->update(['nama' => 'Updated']);
            return service('response')->setJSON(['status' => 200]);
        }]]);
        $before = $this->snapshot();
        $this->call($method, '/api/permission-probe', ['type' => 'guru'])->assertStatus(401);
        $this->asUser(1);
        $this->call($method, '/api/permission-probe', ['type' => 'guru'])->assertStatus(403);
        $this->assertSame($before, $this->snapshot());
        $this->asUser(3);
        $this->call($method, '/api/permission-probe')->assertStatus(200);
        $this->assertSame('Updated', $this->isolatedDatabase->table('users')->where('username', 'user2')->get()->getRowArray()['nama']);
    }

    public static function writeMethods(): array
    {
        return ['POST' => ['post'], 'PUT' => ['put'], 'PATCH' => ['patch'], 'DELETE' => ['delete']];
    }

    private function asUser(int $id): void
    {
        $this->withSession(['auth_user' => ['id_user' => $this->uuid($id), 'type' => $id === 3 ? 'guru' : 'siswa']]);
    }

    private function uuid(int $id): string
    {
        return '00000000-0000-4000-8000-' . str_pad((string) $id, 12, '0', STR_PAD_LEFT);
    }

    private function registration(string $type): array
    {
        return [
            'nama' => 'User Baru', 'username' => 'new_user', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'sn' => '00999999', 'type' => $type, 'id_kelas' => 1,
        ];
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['users', 'siswa', 'guru', 'absensi'] as $table) {
            $rows[$table] = $this->isolatedDatabase->table($table)->get()->getResultArray();
        }
        return $rows;
    }
}
