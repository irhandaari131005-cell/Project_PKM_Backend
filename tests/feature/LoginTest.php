<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;

final class LoginTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;

    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'test_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE test_users (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, username TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL, password TEXT NOT NULL, sn TEXT, type TEXT,
            created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->table('users')->insertBatch([
            [
                'id_user' => 7, 'username' => 'siswa_baru', 'nama' => 'Siswa Baru',
                'password' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => '001234', 'type' => 'siswa',
            ],
            [
                'id_user' => 42, 'username' => 'guru_baru', 'nama' => 'Guru Baru',
                'password' => password_hash('  PasswordGuru123!  ', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => '005678', 'type' => 'guru',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);

        parent::tearDown();
    }

    /** @dataProvider validAccountsAndFormats */
    public function testValidLoginCreatesSessionAndAllowsDashboard(string $username, string $password, bool $json): void
    {
        $before = $this->isolatedDatabase->table('users')->get()->getResultArray();
        $input = [
            'username' => ' ' . $username . ' ', 'password' => $password,
            'id_user' => 999, 'type' => $username === 'siswa_baru' ? 'siswa' : 'guru', 'redirect' => 'https://example.org',
            'auth_user' => ['id_user' => 999],
        ];
        $response = $json ? $this->jsonLogin($input) : $this->post('/api/login', $input);

        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $identity = [
            'id_user' => $username === 'siswa_baru' ? 7 : 42,
            'username' => $username,
            'nama' => $username === 'siswa_baru' ? 'Siswa Baru' : 'Guru Baru',
            'type' => $username === 'siswa_baru' ? 'siswa' : 'guru',
        ];
        $this->assertSame([
            'status' => 200, 'message' => 'Login berhasil',
            'redirect' => '/dashboard', 'data' => $identity,
        ], $body);
        $this->assertSame($identity, session()->get('auth_user'));
        $this->assertTrue(service('session')->didRegenerate);
        $this->assertContains('no-store', array_map('trim', explode(',', $response->response()->getHeaderLine('Cache-Control'))));
        $this->assertSame($before, $this->isolatedDatabase->table('users')->get()->getResultArray());

        // The authenticated identity comes from the session, never query parameters.
        $me = $this->withSession()->withHeaders([])->withBody('')
            ->get('/api/me?id_user=999&username=other&type=admin&auth_user[id_user]=999');
        $me->assertStatus(200);
        $me->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi', 'data' => $identity,
        ]);
        $this->assertContains('no-store', array_map('trim', explode(',', $me->response()->getHeaderLine('Cache-Control'))));

        // Reuse the server-side session produced by login for the next request.
        $dashboard = $this->withSession()->withHeaders([])->withBody('')->get('/api/dashboard');
        $dashboard->assertStatus(200);
        $rows = json_decode($dashboard->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount($identity['type'] === 'siswa' ? 1 : 2, $rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayNotHasKey('sn', $row);
        }
        $this->assertContains('no-store', array_map('trim', explode(',', $dashboard->response()->getHeaderLine('Cache-Control'))));
    }

    public static function validAccountsAndFormats(): array
    {
        return [
            'siswa JSON' => ['siswa_baru', 'Password123!', true],
            'siswa form' => ['siswa_baru', 'Password123!', false],
            'guru JSON preserves password spaces' => ['guru_baru', '  PasswordGuru123!  ', true],
            'guru form preserves password spaces' => ['guru_baru', '  PasswordGuru123!  ', false],
        ];
    }

    /** @dataProvider uuidAccountFormats */
    public function testExistingUuidAccountCanLoginAndAccessProtectedRoutes(bool $json): void
    {
        $identity = $this->insertUuidAccount();
        $credentials = ['username' => $identity['username'], 'password' => 'Password123!', 'type' => $identity['type']];
        $login = $json ? $this->jsonLogin($credentials) : $this->post('/api/login', $credentials);
        $login->assertStatus(200);
        $login->assertJSONExact([
            'status' => 200, 'message' => 'Login berhasil',
            'redirect' => '/dashboard', 'data' => $identity,
        ]);
        $this->assertSame($identity, session()->get('auth_user'));

        $this->withSession()->withHeaders([])->withBody('')->get('/api/me')->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi', 'data' => $identity,
        ]);
        $dashboard = $this->withSession()->get('/api/dashboard');
        $dashboard->assertStatus(200);
        $rows = json_decode($dashboard->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $rowsById = array_column($rows, null, 'id_user');
        $this->assertArrayHasKey($identity['id_user'], $rowsById);
        $this->assertSame($identity, array_intersect_key($rowsById[$identity['id_user']], $identity));
        $this->assertSame($identity, session()->get('auth_user'));
    }

    public static function uuidAccountFormats(): array
    {
        return ['JSON' => [true], 'form' => [false]];
    }

    /** @dataProvider incorrectCredentials */
    public function testIncorrectCredentialsReturn400AndCannotAccessDashboard(string $username, string $password, string $message): void
    {
        // Even an existing authenticated session must be cleared after a failed login.
        $this->withSession(['auth_user' => ['id_user' => 7]]);
        $response = $this->jsonLogin(['username' => $username, 'password' => $password]);

        $response->assertStatus(400);
        $response->assertJSONExact(['status' => 400, 'message' => $message]);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->withHeaders([])->withBody('')->get('/api/dashboard')->assertStatus(401);
    }

    public static function incorrectCredentials(): array
    {
        return [
            'unknown username' => ['unknown', 'Password123!', 'User tidak ditemukan'],
            'both wrong' => ['unknown', 'WrongPassword!', 'User tidak ditemukan'],
            'wrong password' => ['siswa_baru', 'WrongPassword!', 'Username/password salah'],
            'another user password' => ['siswa_baru', '  PasswordGuru123!  ', 'Username/password salah'],
            'case-sensitive password' => ['siswa_baru', 'password123!', 'Username/password salah'],
        ];
    }

    /** @dataProvider invalidInputs */
    public function testInvalidInputsReturn400WithoutCreatingSession(array $input): void
    {
        $this->jsonLogin($input)->assertStatus(400);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->withHeaders([])->withBody('')->get('/api/dashboard')->assertStatus(401);
    }

    public static function invalidInputs(): array
    {
        $valid = ['username' => 'siswa_baru', 'password' => 'Password123!'];

        return [
            'missing username' => [['password' => 'Password123!']],
            'missing password' => [['username' => 'siswa_baru']],
            'blank username' => [array_replace($valid, ['username' => '   '])],
            'empty password' => [array_replace($valid, ['password' => ''])],
            'array username' => [array_replace($valid, ['username' => ['siswa_baru']])],
            'array password' => [array_replace($valid, ['password' => ['Password123!']])],
            'numeric username' => [array_replace($valid, ['username' => 7])],
            'numeric password' => [array_replace($valid, ['password' => 12345678])],
            'null password' => [array_replace($valid, ['password' => null])],
            'long username' => [array_replace($valid, ['username' => str_repeat('a', 101)])],
            'long password' => [array_replace($valid, ['password' => str_repeat('a', 73)])],
            'null byte password' => [array_replace($valid, ['password' => "Password123!\0"])],
        ];
    }

    /** @dataProvider malformedBodies */
    public function testMalformedJsonClearsExistingLogin(string $body): void
    {
        $this->withSession(['auth_user' => ['id_user' => 7]])
            ->withHeaders(['Content-Type' => 'application/json'])->withBody($body)
            ->post('/api/login')->assertStatus(400);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->withHeaders([])->withBody('')->get('/api/dashboard')->assertStatus(401);
    }

    public static function malformedBodies(): array
    {
        return ['invalid JSON' => ['{'], 'array' => ['[]'], 'null' => ['null'], 'empty' => ['']];
    }

    public function testDashboardRejectsAnonymousRequestAndClientSuppliedIdentity(): void
    {
        $response = $this->get('/api/dashboard?auth_user[id_user]=7&id_user=7&logged_in=true');
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
    }

    public function testMeRejectsAnonymousRequestAndClientSuppliedIdentity(): void
    {
        $response = $this->get('/api/me?auth_user[id_user]=7&id_user=7&logged_in=true');
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));
        $this->assertContains('no-store', array_map('trim', explode(',', $response->response()->getHeaderLine('Cache-Control'))));
    }

    public function testLogoutRevokesDashboardAccess(): void
    {
        $this->jsonLogin(['username' => 'siswa_baru', 'password' => 'Password123!'])->assertStatus(200);
        $logout = $this->withSession()->withHeaders([])->withBody('')->get('/api/logout');
        $logout->assertStatus(200);
        $logout->assertJSONExact([
            'status' => 200, 'message' => 'Logout berhasil', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->get('/api/dashboard')->assertStatus(401);
        $this->withSession()->get('/api/me')->assertStatus(401);
    }

    public function testReloginWithAnotherAccountReplacesThePreviousIdentity(): void
    {
        $uuidIdentity = $this->insertUuidAccount();
        $this->jsonLogin(['username' => 'uuid_user', 'password' => 'Password123!'])->assertStatus(200);
        $this->withSession()->withHeaders([])->withBody('')->get('/api/logout')->assertStatus(200);
        $this->withSession()->get('/api/me')->assertStatus(401);

        $this->withSession();
        $this->jsonLogin(['username' => 'guru_baru', 'password' => '  PasswordGuru123!  '])->assertStatus(200);
        $this->withSession()->withHeaders([])->withBody('')->get('/api/me')->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi',
            'data' => ['id_user' => 42, 'username' => 'guru_baru', 'nama' => 'Guru Baru', 'type' => 'guru'],
        ]);

        // Login lagi pada sesi aktif juga mengganti identitas akun sepenuhnya.
        $this->withSession();
        $this->jsonLogin(['username' => 'uuid_user', 'password' => 'Password123!'])->assertStatus(200);
        $this->withSession()->withHeaders([])->withBody('')->get('/api/me')->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi', 'data' => $uuidIdentity,
        ]);
        $this->assertSame($uuidIdentity, session()->get('auth_user'));
    }

    /** @dataProvider protectedRoutes */
    public function testDeletedAccountCannotKeepUsingItsSession(string $route): void
    {
        $this->jsonLogin(['username' => 'siswa_baru', 'password' => 'Password123!'])->assertStatus(200);
        $this->isolatedDatabase->table('users')->where('id_user', 7)->delete();

        $response = $this->withSession()->withHeaders([])->withBody('')->get($route);
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));
    }

    /** @dataProvider protectedRoutes */
    public function testDeletedUuidAccountCannotKeepUsingItsSession(string $route): void
    {
        $identity = $this->insertUuidAccount();
        $this->jsonLogin(['username' => $identity['username'], 'password' => 'Password123!'])
            ->assertStatus(200);
        $this->isolatedDatabase->table('users')->where('id_user', $identity['id_user'])->delete();

        $response = $this->withSession()->withHeaders([])->withBody('')->get($route);
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));
    }

    /** @dataProvider protectedRoutes */
    public function testSessionIdentityIsRefreshedFromTheRegisteredAccount(string $route): void
    {
        $this->jsonLogin(['username' => 'siswa_baru', 'password' => 'Password123!'])->assertStatus(200);
        $this->isolatedDatabase->table('users')->where('id_user', 7)->update([
            'username' => 'username_baru', 'nama' => 'Nama Diperbarui', 'type' => 'siswa',
        ]);

        $this->withSession()->withHeaders([])->withBody('')->get($route)->assertStatus(200);
        $identity = [
            'id_user' => 7, 'username' => 'username_baru', 'nama' => 'Nama Diperbarui', 'type' => 'siswa',
        ];
        $this->assertSame($identity, session()->get('auth_user'));
        $this->withSession()->get('/api/me')->assertJSONExact([
            'status' => 200, 'message' => 'User terautentikasi', 'data' => $identity,
        ]);
    }

    public static function protectedRoutes(): array
    {
        return ['current user' => ['/api/me'], 'dashboard' => ['/api/dashboard']];
    }

    /** @dataProvider invalidSessionIdentities */
    public function testInvalidSessionIdentityCannotAuthenticate(mixed $identity): void
    {
        $response = $this->withSession(['auth_user' => $identity])->get('/api/me');
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->get('/api/dashboard')->assertStatus(401);
    }

    public static function invalidSessionIdentities(): array
    {
        return [
            'null identity' => [null], 'scalar identity' => [7], 'empty identity' => [[]],
            'missing ID' => [['username' => 'siswa_baru']],
            'null ID' => [['id_user' => null]], 'zero ID' => [['id_user' => 0]],
            'negative ID' => [['id_user' => -7]], 'string ID' => [['id_user' => '7']],
            'float ID' => [['id_user' => 7.0]], 'boolean ID' => [['id_user' => true]],
            'array ID' => [['id_user' => [7]]], 'unknown ID' => [['id_user' => 999]],
            'empty string ID' => [['id_user' => '']], 'padded numeric ID' => [['id_user' => '007']],
            'malformed UUID' => [['id_user' => 'not-a-valid-uuid']],
            'unknown UUID' => [['id_user' => '29af5d4e-49fc-4cf2-836c-b7ac87fc0264']],
        ];
    }

    /** @dataProvider protectedRoutesAndDebugModes */
    public function testProtectedRoutesReturnGeneric500WhenDatabaseIsUnavailable(string $route, bool $debug): void
    {
        $this->jsonLogin(['username' => 'siswa_baru', 'password' => 'Password123!'])->assertStatus(200);
        $this->setPrivateProperty($this->isolatedDatabase, 'DBDebug', $debug);
        $this->isolatedDatabase->query('DROP TABLE test_users');

        $response = $this->withSession()->withHeaders([])->withBody('')->get($route);
        $response->assertStatus(500);
        $response->assertJSONExact(['status' => 500, 'message' => 'Terjadi kesalahan pada server']);
        $this->assertContains('no-store', array_map('trim', explode(',', $response->response()->getHeaderLine('Cache-Control'))));
    }

    public static function protectedRoutesAndDebugModes(): array
    {
        return [
            'current user debug' => ['/api/me', true], 'current user no debug' => ['/api/me', false],
            'dashboard debug' => ['/api/dashboard', true], 'dashboard no debug' => ['/api/dashboard', false],
        ];
    }

    public function testQueryParametersCannotSupplyLoginCredentials(): void
    {
        $this->post('/api/login?username=siswa_baru&password=Password123!')->assertStatus(400);
        $this->assertNull(session()->get('auth_user'));
    }

    public function testDatabaseFailureReturnsGeneric500WithoutAuthentication(): void
    {
        $this->isolatedDatabase->query('DROP TABLE test_users');
        $response = $this->withSession(['auth_user' => ['id_user' => 7]])
            ->post('/api/login', ['username' => 'siswa_baru', 'password' => 'Password123!', 'type' => 'siswa']);
        $response->assertStatus(500);
        $response->assertJSONExact(['status' => 500, 'message' => 'Terjadi kesalahan pada server']);
        $this->assertNull(session()->get('auth_user'));
        $this->withSession()->get('/api/dashboard')->assertStatus(401);
    }

    /** @dataProvider corsFrontendPreflights */
    public function testCorsAllowsSessionCookiesAndUnauthenticatedPreflight(string $origin, string $route, string $method): void
    {
        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => 'content-type',
        ])->options($route);
        $response->assertStatus(204);
        $this->assertSame($origin, $response->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->response()->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertContains($method, array_map('trim', explode(',', $response->response()->getHeaderLine('Access-Control-Allow-Methods'))));
        $this->assertContains('Content-Type', array_map('trim', explode(',', $response->response()->getHeaderLine('Access-Control-Allow-Headers'))));
        $this->assertContains('Origin', array_map('trim', explode(',', $response->response()->getHeaderLine('Vary'))));
        $this->assertNull(session()->get('auth_user'));
    }

    public static function corsFrontendPreflights(): array
    {
        return [
            '5173 session check' => ['http://localhost:5173', '/api/me', 'GET'],
            '5174 session check' => ['http://localhost:5174', '/api/me', 'GET'],
            '5173 JSON login' => ['http://localhost:5173', '/api/login', 'POST'],
            '5174 JSON login' => ['http://localhost:5174', '/api/login', 'POST'],
        ];
    }

    /** @dataProvider corsFrontendOrigins */
    public function testCorsAllowsFrontendToReadUnauthenticatedSessionResponse(string $origin): void
    {
        $response = $this->withHeaders(['Origin' => $origin, 'Accept' => 'application/json'])->get('/api/me');
        $response->assertStatus(401);
        $response->assertJSONExact([
            'status' => 401, 'message' => 'Silakan login terlebih dahulu', 'redirect' => '/login',
        ]);
        $this->assertSame($origin, $response->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->response()->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertContains('Origin', array_map('trim', explode(',', $response->response()->getHeaderLine('Vary'))));
    }

    /** @dataProvider corsFrontendOrigins */
    public function testCorsAllowsFrontendLoginAndSubsequentSessionCheck(string $origin): void
    {
        $identity = ['id_user' => 7, 'username' => 'siswa_baru', 'nama' => 'Siswa Baru', 'type' => 'siswa'];
        $login = $this->withHeaders(['Origin' => $origin, 'Content-Type' => 'application/json'])
            ->withBody(json_encode(['username' => 'siswa_baru', 'password' => 'Password123!', 'type' => 'siswa'], JSON_THROW_ON_ERROR))
            ->post('/api/login');
        $login->assertStatus(200);
        $login->assertJSONExact([
            'status' => 200, 'message' => 'Login berhasil', 'redirect' => '/dashboard', 'data' => $identity,
        ]);
        $this->assertSame($origin, $login->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $login->response()->getHeaderLine('Access-Control-Allow-Credentials'));

        $me = $this->withSession()->withHeaders(['Origin' => $origin, 'Accept' => 'application/json'])
            ->withBody('')->get('/api/me');
        $me->assertStatus(200);
        $me->assertJSONExact(['status' => 200, 'message' => 'User terautentikasi', 'data' => $identity]);
        $this->assertSame($origin, $me->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $me->response()->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public static function corsFrontendOrigins(): array
    {
        return ['5173' => ['http://localhost:5173'], '5174' => ['http://localhost:5174']];
    }

    /** @dataProvider corsUnapprovedOrigins */
    public function testCorsDoesNotAllowUnapprovedOrigins(string $origin): void
    {
        // The feature-test response service is shared; real HTTP requests start with fresh headers.
        service('response')->removeHeader('Access-Control-Allow-Origin')->removeHeader('Access-Control-Allow-Credentials');

        $response = $this->withHeaders(['Origin' => $origin])->get('/api/me');
        $response->assertStatus(401);
        $this->assertSame('', $response->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $response->response()->getHeaderLine('Access-Control-Allow-Credentials'));

        $preflight = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/login');
        $preflight->assertStatus(204);
        $this->assertSame('', $preflight->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $preflight->response()->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public static function corsUnapprovedOrigins(): array
    {
        return [
            'unapproved port' => ['http://localhost:5175'],
            'unapproved host' => ['https://example.org'],
            'similar host' => ['http://localhost:5174.example.org'],
        ];
    }

    private function jsonLogin(array $input): TestResponse
    {
        $input += ['type' => ($input['username'] ?? '') === 'guru_baru' || ($input['username'] ?? '') === 'uuid_user' ? 'guru' : 'siswa'];
        return $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post('/api/login');
    }

    private function insertUuidAccount(): array
    {
        $identity = [
            'id_user' => '99d183e6-c0fd-4645-baf7-3e3085dbdf78',
            'username' => 'uuid_user', 'nama' => 'Pengguna UUID', 'type' => 'guru',
        ];
        $this->isolatedDatabase->table('users')->insert($identity + [
            'password' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]), 'sn' => 'UUID_SN',
        ]);

        return $identity;
    }
}
