<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** Regresi untuk bug API: positif, negatif, dan edge case. */
final class ApiRegressionTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GURU = '11111111-1111-4111-8111-111111111111';
    private const SISWA = '22222222-2222-4222-8222-222222222222';
    private const PASSWORD = 'Password123!';

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'reg_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('CREATE TABLE reg_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT NOT NULL, nama TEXT NOT NULL,
            password TEXT NOT NULL, sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE reg_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT, id_jurusan INTEGER)');
        $db->query('CREATE TABLE reg_siswa (id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36), id_kelas INTEGER,
            nama_siswa TEXT NOT NULL, status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE reg_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE reg_absensi (id_absensi VARCHAR(36) PRIMARY KEY, id_user VARCHAR(36), sn TEXT, tanggal TEXT,
            check_in TEXT, check_out TEXT, created_date TEXT, update_date TEXT, UNIQUE(id_user, tanggal))');

        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $db->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL']);
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru1', 'nama' => 'Guru', 'password' => $hash, 'sn' => 'G001', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa1', 'nama' => 'Siswa', 'password' => $hash, 'sn' => 'S001', 'type' => 'siswa'],
        ]);
        $db->table('guru')->insert(['id_user' => self::GURU, 'nama' => 'Guru', 'sn' => 'G001', 'type' => 'guru', 'status' => 'ACTIVE']);
        $db->table('siswa')->insert([
            'id_siswa' => 1, 'id_user' => self::SISWA, 'id_kelas' => 1, 'nama_siswa' => 'Siswa',
            'status' => 'ACTIVE', 'sn' => 'S001', 'type' => 'siswa',
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    // ---- Register -------------------------------------------------------------------

    public function testRegisterIsPublicEvenWithAStaleSessionCookie(): void
    {
        $stale = ['auth_user' => ['id_user' => '99999999-9999-4999-8999-999999999999', 'type' => 'guru']];

        $this->withSession($stale)->post('/api/register', $this->registration())->assertStatus(201);
        $this->assertNull(session()->get('auth_user'));
    }

    public function testRegisterStillForbiddenForAValidStudentSession(): void
    {
        $this->withSession(['auth_user' => ['id_user' => self::SISWA, 'type' => 'siswa']])
            ->post('/api/register', $this->registration())->assertStatus(403);
        $this->assertSame(2, $this->isolatedDatabase->table('users')->countAllResults());
    }

    /** @dataProvider controlCharacters */
    public function testRegisterRejectsControlCharactersInNameAndUsername(string $field, string $value): void
    {
        $response = $this->withSession()->post('/api/register', array_replace($this->registration(), [$field => $value]));

        $response->assertStatus(422);
        $this->assertArrayHasKey($field, json_decode($response->getJSON(), true)['errors']);
        $this->assertSame(2, $this->isolatedDatabase->table('users')->countAllResults());
    }

    public static function controlCharacters(): array
    {
        return [
            'username newline' => ['username', "user\nbaru"], 'username tab' => ['username', "user\tbaru"],
            'nama null byte' => ['nama', "Nama\0Baru"], 'nama DEL' => ['nama', "Nama\x7F"],
        ];
    }

    public function testRegisterAcceptsUnicodeNames(): void
    {
        $this->withSession()->post('/api/register', array_replace($this->registration(), ['nama' => 'Ñoño Ā Đức']))
            ->assertStatus(201);
    }

    // ---- Login / password brute force -----------------------------------------------

    public function testLoginIsThrottledAfterRepeatedFailuresEvenWithTheCorrectPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('guru1', 'salah-password', 'guru')->assertStatus(400);
        }

        $blocked = $this->login('guru1', self::PASSWORD, 'guru');
        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After', '300');
        $this->assertNull(session()->get('auth_user'));

        // Akun lain dari IP yang sama tidak ikut terkunci.
        $this->login('siswa1', self::PASSWORD, 'siswa')->assertStatus(200);
    }

    public function testUnknownUsernamesAlsoCountTowardsTheLimitCaseInsensitively(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login($i % 2 === 0 ? 'Ghost' : 'ghost', self::PASSWORD, 'guru')->assertStatus(400);
        }
        $this->login('ghost', self::PASSWORD, 'guru')->assertStatus(429);
    }

    public function testSuccessfulLoginResetsTheFailureCounter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('guru1', 'salah-password', 'guru')->assertStatus(400);
        }
        $this->login('guru1', self::PASSWORD, 'guru')->assertStatus(200);
        for ($i = 0; $i < 4; $i++) {
            $this->login('guru1', 'salah-password', 'guru')->assertStatus(400);
        }
        $this->login('guru1', self::PASSWORD, 'guru')->assertStatus(200);
    }

    public function testChangePasswordSharesTheBruteForceLimitWithLogin(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->login('guru1', 'salah-password', 'guru')->assertStatus(400);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->changePassword('guru1', 'salah-password', 'PasswordBaru1!')->assertStatus(401);
        }

        $this->changePassword('guru1', self::PASSWORD, 'PasswordBaru1!')->assertStatus(429);
        $user = $this->isolatedDatabase->table('users')->where('id_user', self::GURU)->get()->getRowArray();
        $this->assertTrue(password_verify(self::PASSWORD, $user['password']));
    }

    // ---- Session invalidation after password change ---------------------------------

    public function testPasswordResetInvalidatesOtherSessionsOfTheAccount(): void
    {
        $this->login('guru1', self::PASSWORD, 'guru')->assertStatus(200);
        $stolenSession = $_SESSION;
        $this->withSession($stolenSession)->get('/api/me')->assertStatus(200);

        // Pemilik akun mereset password dari perangkat lain (tanpa sesi).
        $this->withSession([])->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['username' => 'guru1', 'sn' => 'G001', 'new_password' => 'PasswordBaru1!']))
            ->patch('/api/forgot_password')->assertStatus(200);

        $this->withHeaders([])->withBody('')->withSession($stolenSession)->get('/api/me')->assertStatus(401);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession([])->login('guru1', 'PasswordBaru1!', 'guru')->assertStatus(200);
    }

    public function testChangingYourOwnPasswordKeepsTheCurrentSessionLoggedIn(): void
    {
        $this->login('siswa1', self::PASSWORD, 'siswa')->assertStatus(200);
        $before = $_SESSION;

        $this->withSession($before)->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['username' => 'siswa1', 'password' => self::PASSWORD, 'new_password' => 'PasswordBaru1!']))
            ->patch('/api/change_password')->assertStatus(200);
        $current = $_SESSION;

        $this->withHeaders([])->withBody('')->withSession($current)->get('/api/me')->assertStatus(200);
        $this->withSession($before)->get('/api/me')->assertStatus(401);
    }

    public function testSessionsWithoutFingerprintKeepWorking(): void
    {
        $this->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']])
            ->get('/api/me')->assertStatus(200);
    }

    public function testForgedFingerprintIsRejected(): void
    {
        $this->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru'], 'auth_password' => ['x']])
            ->get('/api/me')->assertStatus(401);
        $this->assertNull(session()->get('auth_user'));
    }

    // ---- Absensi --------------------------------------------------------------------

    public function testCheckOutOfAPreviousDayIsRejectedAndTodaySucceeds(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        foreach (['44444444-4444-4444-8444-444444444441' => $yesterday, '44444444-4444-4444-8444-444444444442' => $today] as $id => $date) {
            $this->isolatedDatabase->table('absensi')->insert([
                'id_absensi' => $id, 'id_user' => self::SISWA, 'sn' => 'S001', 'tanggal' => $date,
                'check_in' => $date . ' 00:00:00', 'created_date' => $date . ' 00:00:00', 'update_date' => $date . ' 00:00:00',
            ]);
        }
        $guru = ['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']];

        $this->withSession($guru)->post('/api/absensi/check-out', ['id_absensi' => '44444444-4444-4444-8444-444444444441'])
            ->assertStatus(409);
        $this->withSession($guru)->post('/api/absensi/check-out', ['id_absensi' => '44444444-4444-4444-8444-444444444442'])
            ->assertStatus(200);
        $this->assertNull($this->isolatedDatabase->table('absensi')
            ->where('id_absensi', '44444444-4444-4444-8444-444444444441')->get()->getRowArray()['check_out']);
    }

    // ---- Query ID validation --------------------------------------------------------

    /** @dataProvider malformedQueryIds */
    public function testMalformedNumericQueryIdsAreRejected(string $url, int $status): void
    {
        $this->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']])->get($url)->assertStatus($status);
    }

    public static function malformedQueryIds(): array
    {
        return [
            'guru leading space' => ['/api/data_guru?id_guru=%205', 400],
            'guru plus sign' => ['/api/data_guru?id_guru=%2B5', 400],
            'guru zero' => ['/api/data_guru?id_guru=0', 400],
            'siswa plus sign' => ['/api/data_siswa?id_siswa=%2B1', 400],
            'siswa leading zero' => ['/api/data_siswa?id_siswa=01', 400],
            'siswa trailing space' => ['/api/data_siswa?id_siswa=1%20', 400],
            'siswa overflow' => ['/api/data_siswa?id_siswa=2147483648', 400],
            'kelas jurusan plus' => ['/api/data_kelas?id_jurusan=%2B1', 400],
            'riwayat page space' => ['/api/absensi/riwayat?page=%202', 422],
            'riwayat page plus' => ['/api/absensi/riwayat?page=%2B2', 422],
            'riwayat kelas zero' => ['/api/absensi/riwayat?id_kelas=0', 422],
        ];
    }

    /** @dataProvider validQueryIds */
    public function testValidNumericQueryIdsStillWork(string $url): void
    {
        $this->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']])->get($url)->assertStatus(200);
    }

    public static function validQueryIds(): array
    {
        return [
            'siswa' => ['/api/data_siswa?id_siswa=1'],
            'guru uuid' => ['/api/data_guru?id_user=' . self::GURU],
            'guru uuid uppercase' => ['/api/data_guru?id_user=' . strtoupper(self::GURU)],
            'riwayat' => ['/api/absensi/riwayat?page=1&id_kelas=1'],
            'riwayat max page' => ['/api/absensi/riwayat?page=2147483647'],
        ];
    }

    // ---- Helpers --------------------------------------------------------------------

    private function registration(): array
    {
        return [
            'nama' => 'Nama Baru', 'username' => 'user_baru', 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'sn' => 'SN-BARU', 'type' => 'siswa', 'id_kelas' => '1',
        ];
    }

    private function login(string $username, string $password, string $type)
    {
        return $this->withHeaders([])->withBody('')->post('/api/login', compact('username', 'password', 'type'));
    }

    private function changePassword(string $username, string $password, string $newPassword)
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['username' => $username, 'password' => $password, 'new_password' => $newPassword]))
            ->patch('/api/change_password');
    }
}
