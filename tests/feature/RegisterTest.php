<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class RegisterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;

    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => 'test_',
            'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('PRAGMA foreign_keys = ON');
        $this->isolatedDatabase->query('CREATE TABLE test_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT)');
        $this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1']);
        $this->isolatedDatabase->query('CREATE TABLE test_users (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL DEFAULT \'\', username TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL, password TEXT NOT NULL, sn TEXT, type TEXT,
            created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE test_siswa (
            id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36), nama_siswa TEXT NOT NULL,
            status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT,
            id_kelas INTEGER REFERENCES test_kelas(id_kelas),
            FOREIGN KEY (id_user) REFERENCES test_users(id_user)
        )');
        $this->isolatedDatabase->query('CREATE TABLE test_guru (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, nama TEXT NOT NULL,
            sn TEXT, type TEXT, status TEXT NOT NULL, created_date TEXT, update_date TEXT
        )');
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);

        parent::tearDown();
    }

    /** @dataProvider typesAndFormats */
    public function testRegistrationSavesSnAndOnlyTheSelectedProfile(string $type, bool $json): void
    {
        $input = array_replace($this->validInput(), [
            'nama' => ' Nama Baru ', 'username' => ' user_baru ',
            'sn' => ' 001234 ', 'type' => ' ' . $type . ' ',
            'id_user' => 99, 'id_siswa' => 99, 'id_guru' => 99,
            'status' => 'nonaktif', 'created_date' => '2000-01-01 00:00:00',
            'update_date' => '2000-01-01 00:00:00',
        ]);
        $before = $this->nowWib();
        $response = $json
            ? $this->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/register')
            : $this->post('/api/register', $input);

        $response->assertStatus(201);
        $response->assertJSONExact([
            'status' => 201, 'message' => 'Registrasi berhasil', 'redirect' => '/login',
        ]);
        $this->assertSame(1, $this->isolatedDatabase->table('users')->countAllResults());
        $user = $this->isolatedDatabase->table('users')->get()->getRowArray();
        $this->assertSame('001234', $user['sn']);
        $this->assertSame($type, $user['type']);
        $this->assertSame('Nama Baru', $user['nama']);
        $this->assertSame('user_baru', $user['username']);
        $this->assertTrue(password_verify($input['password'], $user['password']));
        $this->assertUuid($user['id_user']);
        $this->assertGreaterThanOrEqual($before, $user['created_date']);
        $this->assertLessThanOrEqual($this->nowWib(), $user['created_date']);
        $this->assertSame($user['created_date'], $user['update_date']);

        $otherTable = $type === 'siswa' ? 'guru' : 'siswa';
        $this->assertSame(0, $this->isolatedDatabase->table($otherTable)->countAllResults());
        $this->assertSame(1, $this->isolatedDatabase->table($type)->countAllResults());
        $profile = $this->isolatedDatabase->table($type)->get()->getRowArray();
        $this->assertSame($user['id_user'], $profile['id_user']);
        $this->assertSame('Nama Baru', $profile[$type === 'siswa' ? 'nama_siswa' : 'nama']);
        foreach (['sn', 'type', 'created_date', 'update_date'] as $field) {
            $this->assertSame($user[$field], $profile[$field]);
        }
        if ($type === 'siswa') {
            $this->assertSame('ACTIVE', $profile['status']);
            $this->assertSame(1, (int) $profile['id_kelas']);
        } else {
            $this->assertArrayNotHasKey('id_kelas', $profile);
        }

        // Data hasil registrasi langsung terbaca oleh endpoint siswa/guru.
        $this->withSession(['auth_user' => ['id_user' => $user['id_user'], 'type' => $type]])
            ->withHeaders([])->withBody('')->get('/api/data_' . $type)
            ->assertJSONFragment(['data' => [array_diff_key($profile, $type === 'siswa' ? ['sn' => true] : [])]]);
        if ($type === 'guru') {
            $this->get('/api/data_guru?id_user=' . $user['id_user'])
                ->assertJSONFragment(['data' => $profile]);
            $this->get('/api/data_guru?id_user[]=invalid')->assertStatus(400);
        }
    }

    public static function typesAndFormats(): array
    {
        return [
            'siswa JSON' => ['siswa', true], 'siswa form' => ['siswa', false],
            'guru JSON' => ['guru', true], 'guru form' => ['guru', false],
        ];
    }

    /** @dataProvider typesAndFormats */
    public function testRegisteredAccountMustLoginBeforeUsingProtectedRoutes(string $type, bool $json): void
    {
        $input = array_replace($this->validInput(), ['type' => $type]);
        $registration = $json
            ? $this->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/register')
            : $this->post('/api/register', $input);
        $registration->assertStatus(201);
        $registration->assertJSONExact([
            'status' => 201, 'message' => 'Registrasi berhasil', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));

        foreach (['/api/me', '/api/dashboard'] as $route) {
            $anonymous = $this->withSession()->withHeaders([])->withBody('')->get($route);
            $anonymous->assertStatus(401);
            $anonymous->assertJSONExact([
                'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
            ]);
        }

        $credentials = ['username' => $input['username'], 'password' => $input['password'], 'type' => $type];
        $login = $json
            ? $this->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode($credentials, JSON_THROW_ON_ERROR))->post('/api/login')
            : $this->withHeaders([])->withBody('')->post('/api/login', $credentials);
        $login->assertStatus(200);
        $user = $this->isolatedDatabase->table('users')->get()->getRowArray();
        $this->assertUuid($user['id_user']);
        $identity = [
            'id_user' => $user['id_user'], 'username' => 'user_baru', 'nama' => 'Nama Baru', 'type' => $type,
        ];
        $login->assertJSONExact([
            'status' => 200, 'message' => 'Login berhasil',
            'redirect' => '/dashboard', 'data' => $identity,
        ]);

        $me = $this->withSession()->withHeaders([])->withBody('')->get('/api/me');
        $me->assertStatus(200);
        $me->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi', 'data' => $identity,
        ]);
        $this->assertSame($identity, session()->get('auth_user'));

        $dashboard = $this->withSession()->get('/api/dashboard');
        $dashboard->assertStatus(200);
        $rows = json_decode($dashboard->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $rows);
        $this->assertSame($identity['id_user'], $rows[0]['id_user']);
        $this->assertSame('user_baru', $rows[0]['username']);
        $this->assertArrayNotHasKey('password', $rows[0]);
        $this->assertArrayNotHasKey('sn', $rows[0]);
    }

    /** @dataProvider invalidFields */
    public function testInvalidRegistrationDoesNotCreateAnyRows(string $field, mixed $value): void
    {
        $input = array_replace($this->validInput(), [$field => $value]);
        $response = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/register');

        $response->assertStatus(422);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($field, $body['errors']);
        $this->assertNoRows();
    }

    public static function invalidFields(): array
    {
        return [
            'missing SN' => ['sn', null], 'blank SN' => ['sn', '   '],
            'numeric SN' => ['sn', 1234], 'array SN' => ['sn', ['001234']],
            'long SN' => ['sn', str_repeat('A', 51)], 'SN control' => ['sn', "001\n234"],
            'missing type' => ['type', null], 'unknown type' => ['type', 'admin'],
            'array type' => ['type', ['guru']], 'blank type' => ['type', '   '],
            'blank name' => ['nama', '   '], 'blank username' => ['username', '   '],
            'missing confirmation' => ['password_confirmation', null],
            'wrong confirmation' => ['password_confirmation', 'WrongPassword123!'],
            'missing class' => ['id_kelas', null], 'empty class' => ['id_kelas', ''],
            'unknown class' => ['id_kelas', 999], 'negative class' => ['id_kelas', -1],
            'zero class' => ['id_kelas', 0], 'array class' => ['id_kelas', [1]],
            'boolean class' => ['id_kelas', true], 'fractional class' => ['id_kelas', 1.5],
            'overflow class' => ['id_kelas', '2147483648'],
        ];
    }

    /** @dataProvider existingSnTables */
    public function testExistingSnCannotBeRegisteredAgain(string $table): void
    {
        $existing = ['sn' => '001234', 'type' => $table === 'guru' ? 'guru' : 'siswa'];
        $existing[$table === 'siswa' ? 'nama_siswa' : 'nama'] = 'Pemilik Kartu';
        if ($table === 'users') {
            $existing += ['username' => 'existing_user', 'password' => 'existing_hash'];
        }
        if ($table !== 'siswa') {
            $existing['id_user'] = '9e78ea4b-e635-4b6f-88dd-0eb946cc269a';
        }
        if ($table === 'guru') {
            $existing['status'] = 'ACTIVE';
        }
        $this->isolatedDatabase->table($table)->insert($existing);

        $this->post('/api/register', $this->validInput())->assertStatus(422);

        foreach (['users', 'siswa', 'guru'] as $candidate) {
            $this->assertSame($candidate === $table ? 1 : 0, $this->isolatedDatabase->table($candidate)->countAllResults());
        }
    }

    public static function existingSnTables(): array
    {
        return ['users' => ['users'], 'siswa' => ['siswa'], 'guru' => ['guru']];
    }

    public function testTrimmedUsernameIsCheckedForDuplicates(): void
    {
        $this->post('/api/register', $this->validInput())->assertStatus(201);
        $input = array_replace($this->validInput(), ['username' => ' user_baru ', 'sn' => 'SNLAIN']);
        $this->post('/api/register', $input)->assertStatus(422);
        $this->assertSame(1, $this->isolatedDatabase->table('users')->countAllResults());
        $this->assertSame(1, $this->isolatedDatabase->table('siswa')->countAllResults());
    }

    public function testRegistrationsGenerateDistinctIdsDespiteAnExistingEmptyIdAndClientSuppliedIds(): void
    {
        $existing = $this->insertExistingEmptyIdUser();
        $clientId = '05c0eaa2-d833-4454-9d33-e47a97ab83bf';
        $generatedIds = [];

        foreach (['siswa', 'guru'] as $type) {
            $input = array_replace($this->validInput(), [
                'username' => 'new_' . $type, 'sn' => 'SN_' . $type,
                'type' => $type, 'id_user' => $clientId,
            ]);
            $this->post('/api/register', $input)->assertStatus(201);

            $user = $this->isolatedDatabase->table('users')->where('username', $input['username'])
                ->get()->getRowArray();
            $this->assertUuid($user['id_user']);
            $this->assertNotSame($clientId, $user['id_user']);
            $this->assertNotContains($user['id_user'], $generatedIds);
            $generatedIds[] = $user['id_user'];
            $profile = $this->isolatedDatabase->table($type)->get()->getRowArray();
            $this->assertSame($user['id_user'], $profile['id_user']);
        }

        $this->assertSame(3, $this->isolatedDatabase->table('users')->countAllResults());
        $this->assertSame($existing, $this->isolatedDatabase->table('users')->where('id_user', '')
            ->get()->getRowArray());
    }

    /** @dataProvider typesAndDebugModes */
    public function testDatabaseFailureRollsBackTheAccountAndProfile(string $type, bool $debug): void
    {
        $existing = $this->insertExistingEmptyIdUser();
        $this->setPrivateProperty($this->isolatedDatabase, 'DBDebug', $debug);
        $this->isolatedDatabase->query("CREATE TRIGGER reject_profile BEFORE INSERT ON test_{$type}
            BEGIN SELECT RAISE(ABORT, 'private database failure'); END");

        $response = $this->post('/api/register', array_replace($this->validInput(), ['type' => $type]));
        $response->assertStatus(500);
        $response->assertJSONExact(['status' => 500, 'message' => 'Terjadi kesalahan pada server']);
        $this->assertSame([$existing], $this->isolatedDatabase->table('users')->get()->getResultArray());
        $this->assertSame(0, $this->isolatedDatabase->table('siswa')->countAllResults());
        $this->assertSame(0, $this->isolatedDatabase->table('guru')->countAllResults());
    }

    public static function typesAndDebugModes(): array
    {
        return [
            'siswa debug' => ['siswa', true], 'siswa no debug' => ['siswa', false],
            'guru debug' => ['guru', true], 'guru no debug' => ['guru', false],
        ];
    }

    public function testUserInsertFailureDoesNotCreateAProfile(): void
    {
        $this->isolatedDatabase->query("CREATE TRIGGER reject_user BEFORE INSERT ON test_users
            BEGIN SELECT RAISE(ABORT, 'private database failure'); END");
        $this->post('/api/register', $this->validInput())->assertStatus(500);
        $this->assertNoRows();
    }

    /** @dataProvider invalidJsonBodies */
    public function testMalformedOrNonObjectJsonIsRejected(string $body): void
    {
        $this->withHeaders(['Content-Type' => 'application/json'])->withBody($body)
            ->post('/api/register')->assertStatus(400);
        $this->assertNoRows();
    }

    public static function invalidJsonBodies(): array
    {
        return ['malformed' => ['{'], 'list' => ['[]'], 'null' => ['null']];
    }

    private function validInput(): array
    {
        return [
            'nama' => 'Nama Baru', 'username' => 'user_baru',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'sn' => '001234', 'type' => 'siswa', 'id_kelas' => '1',
        ];
    }

    private function assertNoRows(): void
    {
        foreach (['users', 'siswa', 'guru'] as $table) {
            $this->assertSame(0, $this->isolatedDatabase->table($table)->countAllResults());
        }
    }

    private function insertExistingEmptyIdUser(): array
    {
        $this->isolatedDatabase->table('users')->insert([
            'id_user' => '', 'username' => 'legacy_empty_id', 'nama' => 'Akun Lama',
            'password' => 'existing_hash', 'sn' => 'OLD_SN', 'type' => 'siswa',
        ]);

        return $this->isolatedDatabase->table('users')->where('id_user', '')->get()->getRowArray();
    }

    private function assertUuid(string $id): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    private function nowWib(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}
