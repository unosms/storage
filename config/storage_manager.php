<?php

return [
    'ftp' => [
        'host' => env('FTP_DEFAULT_HOST', '127.0.0.1'),
        'port' => (int) env('FTP_DEFAULT_PORT', 21),
        'passive' => (bool) env('FTP_DEFAULT_PASSIVE', true),
        'ssl' => (bool) env('FTP_DEFAULT_SSL', false),
        'base_path' => env('FTP_USER_BASE_PATH', '/srv/ftp/users'),
        'user_shell' => env('FTP_USER_SHELL', '/usr/sbin/nologin'),
        'sudo_prefix' => env('FTP_PROVISION_SUDO', 'sudo -n'),
    ],
];
