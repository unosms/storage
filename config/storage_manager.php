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
        'bin_useradd' => env('FTP_BIN_USERADD', '/usr/sbin/useradd'),
        'bin_userdel' => env('FTP_BIN_USERDEL', '/usr/sbin/userdel'),
        'bin_chpasswd' => env('FTP_BIN_CHPASSWD', '/usr/sbin/chpasswd'),
        'bin_mkdir' => env('FTP_BIN_MKDIR', '/bin/mkdir'),
        'bin_chown' => env('FTP_BIN_CHOWN', '/bin/chown'),
        'bin_chmod' => env('FTP_BIN_CHMOD', '/bin/chmod'),
        'bin_id' => env('FTP_BIN_ID', '/usr/bin/id'),
    ],
];
