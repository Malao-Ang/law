<?php

/**
 * Login via BUU LDAP (Kong) + session cookie (Sanctum SPA mode).
 *
 * Users are stored in the Mongo `users` collection, identified by LDAP username.
 * The session only holds `auth_username`; the profile and role are re-read per request.
 */
return [

    /** When false, /api/auth/* works but guarded API routes and the SPA router do not force login. */
    'dev_login' => filter_var(env('AUTH_DEV_LOGIN', false), FILTER_VALIDATE_BOOLEAN),

    'enforced' => filter_var(env('AUTH_ENFORCED', false), FILTER_VALIDATE_BOOLEAN),

    /** Usernames that always get role super_admin on login (comma separated). */
    'super_admins' => array_values(array_filter(array_map(
        static fn (string $name): string => strtolower(trim($name)),
        explode(',', (string) env('SUPER_ADMIN_USERNAMES', '')),
    ))),

    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,

    /** How long a UMS person lookup is cached per username. */
    'ums_cache_seconds' => 86400,
];
