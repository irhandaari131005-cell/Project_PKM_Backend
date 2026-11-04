<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Apply authentication and write permissions to every API, including future routes. */
class ApiAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        $path = trim($request->getUri()->getRoutePath(), '/');
        if ($method === 'OPTIONS') {
            return null;
        }

        // Credential flows remain public.
        if (in_array($path, ['api/login', 'api/logout', 'api/change_password', 'api/forgot_password'], true)
            || ($path === 'api/data_kelas' && $method === 'GET')) {
            return null;
        }

        $failure = (new AuthFilter())->before($request);
        if ($failure !== null) {
            // Register tetap publik untuk pengunjung tanpa sesi valid, termasuk cookie sesi
            // yang sudah kedaluwarsa/akunnya terhapus (AuthFilter sudah membersihkannya).
            if ($path === 'api/register' && $failure->getStatusCode() === 401) {
                return null;
            }

            return $failure;
        }

        $type = session()->get('auth_user')['type'];
        // Siswa hanya bisa melihat (GET/HEAD) di semua API; tambah/ubah/hapus khusus guru.
        if ($type !== 'guru' && ! in_array($method, ['GET', 'HEAD'], true)) {
            return service('response')->setHeader('Cache-Control', 'no-store')->setStatusCode(403)->setJSON([
                'status' => 403,
                'message' => 'Akses ditolak. Akun siswa hanya dapat melihat data.',
            ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store');
    }
}
