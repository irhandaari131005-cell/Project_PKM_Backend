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

// routes untuk dashboard
$routes->get('/api/dashboard', 'Dashboard::Dashboard');

// routes untuk siswa, kelas & guru
$routes->get('/api/data_siswa', 'Data_siswa::DataSiswa');
$routes->get('/api/data_kelas', 'Data_siswa::DataKelas');
$routes->get('/api/data_guru', 'Data_guru::DataGuru');

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
