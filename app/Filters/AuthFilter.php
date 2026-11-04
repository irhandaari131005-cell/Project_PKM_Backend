<?php

namespace App\Filters;

use App\Models\M_users;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $user = $session->get('auth_user');
        $userId = is_array($user) ? M_users::normalizeId($user['id_user'] ?? null) : null;
        if ($userId === null || $userId !== $user['id_user']) {
            $session->remove(['auth_user', 'auth_password']);

            return $this->unauthenticated();
        }

        try {
            // Sesi hanya berlaku selama akun masih terdaftar. Identitas selalu dari database.
            // Sidik jari password hanya ada pada sesi hasil login dan dicek terhadap hash terbaru.
            $fingerprint = $session->get('auth_password');
            $identity = (new M_users())
                ->select('id_user, username, nama, type' . ($fingerprint === null ? '' : ', password'))
                ->find((string) $userId);
            $passwordHash = $identity['password'] ?? null;
            unset($identity['password']);
        } catch (Throwable $e) {
            log_message('error', 'Validasi sesi gagal: {message}', ['message' => $e->getMessage()]);

            return service('response')->setHeader('Cache-Control', 'no-store')->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Terjadi kesalahan pada server',
            ]);
        }

        $identityId = $identity === null ? null : M_users::normalizeId($identity['id_user'] ?? null);
        if ($identityId === null || ! in_array($identity['type'] ?? null, ['siswa', 'guru'], true)
            || (isset($user['type']) && $user['type'] !== $identity['type'])) {
            $session->remove(['auth_user', 'auth_password']);

            return $this->unauthenticated();
        }

        // Sesi yang dibuat sebelum password diganti/direset tidak berlaku lagi.
        if ($fingerprint !== null && (! is_string($fingerprint) || ! is_string($passwordHash)
            || ! hash_equals(M_users::passwordFingerprint($passwordHash), $fingerprint))) {
            $session->remove(['auth_user', 'auth_password']);

            return $this->unauthenticated();
        }

        $identity['id_user'] = $identityId;
        $session->set('auth_user', $identity);

        return null;
    }

    private function unauthenticated(): ResponseInterface
    {
        return service('response')->setHeader('Cache-Control', 'no-store')->setStatusCode(401)->setJSON([
            'status' => 401,
            'message' => 'Silakan login terlebih dahulu',
            'redirect' => '/login',
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store');
    }
}
