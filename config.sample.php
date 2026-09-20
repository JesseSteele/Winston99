<?php
/**
 * Winston 99 — SysAdmin config.
 * Copy to config.php and edit. Never commit the live file.
 * The in-app Administrator does not edit this. Mail, host, and DB are SysAdmin work.
 */
return [
    'configured' => true,

    // Walk-in recovery: SysAdmin sets true, creates a Superintendent, sets false again.
    'allow_create_super' => false,

    // Everything after https:// — no scheme. Examples:
    //   write.pink
    //   99.example.org
    //   www.example.org
    //   example.org/99
    // www. is the SysAdmin's choice. The app always uses https://
    'host' => 'write.pink/99',

    'site_title' => 'Winston 99',

    'db' => [
        'host' => '127.0.0.1', // TCP. Do not use localhost (that is a Unix socket).
        'port' => 3306,
        'name' => 'winston99db',
        'user' => 'winston99db',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],

    // Mail is SysAdmin-only. SMTP password lives here on purpose.
    'mail' => [
        'transport' => 'mail', // mail | smtp | off
        'from' => 'noreply@write.pink',
        'from_name' => 'Winston 99',
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'tls', // tls | ssl | none
    ],

    'github' => 'https://github.com/JesseSteele/Winston99.git',

    // Update stream: Git branch pulled by bin/update. SysAdmin-only.
    // Never change this from the web app. Edit this file.
    //   main       — published default for new installs
    //   developer  — in-progress work
    //   master     — older published name; still valid
    'stream' => 'main',

    // SysAdmin OAuth. Empty id or secret = that provider is off (button hidden).
    // Not stored in the database. Not editable in the app, even by a Superintendent.
    // Callback for both: https://{host}/oauth.php
    // Google:  https://console.cloud.google.com/apis/credentials
    //          OAuth client type: Web application
    //          Authorized redirect URI: https://{host}/oauth.php
    // GitHub:  https://github.com/settings/developers  (OAuth Apps)
    //          Authorization callback URL: https://{host}/oauth.php
    'oauth' => [
        'google' => ['id' => '', 'secret' => ''],
        'github' => ['id' => '', 'secret' => ''],
    ],
];
