<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'Home::index');

// Argument pertama untuk path url, argumen kedua untuk definisi controller dan method yang akan dijalankan

// Routes auth
$routes->post('/api/register', 'Auth::Register');
$routes->post('/api/login', 'Auth::Login');
$routes->get('/api/me', 'Auth::me');
$routes->get('/api/logout', 'Auth::Logout');

// Routes absensi
$routes->get('/api/absensi', 'Absensi::index');
$routes->get('/api/absensi/riwayat', 'Riwayat_absen::index');
$routes->get('/api/absensi/(:segment)', 'Absensi::show/$1');
$routes->post('/api/absensi', 'Absensi::Absensi');
$routes->post('/api/absensi/tap', 'Absensi::tap');
$routes->post('/api/absensi/check-in', 'Absensi::checkIn');
$routes->post('/api/absensi/check-out', 'Absensi::checkOut');

// Profil Saya (guru & siswa mengubah profilnya sendiri). Update dengan foto: POST multipart + _method=PUT.
$routes->get('/api/profile', 'Profile::show');
$routes->put('/api/profile', 'Profile::update');
$routes->patch('/api/profile', 'Profile::update');

// routes untuk dashboard
$routes->get('/api/dashboard', 'Dashboard::Dashboard');

// routes untuk siswa, kelas & guru
$routes->get('/api/data_siswa', 'Data_siswa::DataSiswa');
// Tambah Siswa/Guru (khusus guru): data tanpa akun, akun nyusul lewat registrasi dengan SN yang sama.
$routes->post('/api/data_siswa', 'Data_siswa::create');
$routes->post('/api/data_guru', 'Data_guru::create');
$routes->get('/api/data_kelas', 'Data_siswa::DataKelas');
$routes->get('/api/data_guru', 'Data_guru::DataGuru');

// CRUD ekstrakurikuler + foto (baca: guru & siswa login, tulis: guru).
// Update dengan foto dikirim sebagai POST multipart + _method=PUT. Route /foto harus di atas (:segment).
$routes->get('/api/ekstrakurikuler', 'Ekstrakurikuler::index');
$routes->get('/api/ekstrakurikuler/foto', 'Ekstrakurikuler::photos');
$routes->get('/api/ekstrakurikuler/(:segment)', 'Ekstrakurikuler::show/$1');
$routes->post('/api/ekstrakurikuler', 'Ekstrakurikuler::create');
$routes->put('/api/ekstrakurikuler/(:segment)', 'Ekstrakurikuler::update/$1');
$routes->patch('/api/ekstrakurikuler/(:segment)', 'Ekstrakurikuler::update/$1');
$routes->delete('/api/ekstrakurikuler/(:segment)', 'Ekstrakurikuler::delete/$1');

// CRUD jadwal pelajaran (baca: guru & siswa login, tulis: guru). Bentrok kelas/guru/ruangan -> 409.
$routes->get('/api/jadwal', 'Jadwal::index');
$routes->get('/api/jadwal/(:segment)', 'Jadwal::show/$1');
$routes->post('/api/jadwal', 'Jadwal::create');
$routes->put('/api/jadwal/(:segment)', 'Jadwal::update/$1');
$routes->patch('/api/jadwal/(:segment)', 'Jadwal::update/$1');
$routes->delete('/api/jadwal/(:segment)', 'Jadwal::delete/$1');

// CRUD data kelas (baca: guru & siswa login, tulis: guru). /api/data_kelas tetap publik untuk form register.
$routes->get('/api/kelas', 'Kelas::index');
$routes->get('/api/kelas/(:segment)', 'Kelas::show/$1');
$routes->post('/api/kelas', 'Kelas::create');
$routes->put('/api/kelas/(:segment)', 'Kelas::update/$1');
$routes->patch('/api/kelas/(:segment)', 'Kelas::update/$1');
$routes->delete('/api/kelas/(:segment)', 'Kelas::delete/$1');

// Ganti password dengan password lama; reset password dengan username dan SN registrasi.
$routes->patch('/api/change_password', 'Auth::changePassword');
$routes->patch('/api/forgot_password', 'Auth::forgotPassword');

$routes->options('api/(:any)', static function () {});
