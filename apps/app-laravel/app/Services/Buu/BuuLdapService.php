<?php

namespace App\Services\Buu;

/**
 * BUU LDAP login via Kong (Develop).
 */
class BuuLdapService
{
    public function __construct(private readonly BuuKongClient $kong) {}

    /**
     * @return array{fullname?: string, email?: string, facultyname?: string, citizenid?: string}
     */
    public function login(string $username, string $password, bool $secret = false): array
    {
        $response = $this->kong->postJson('ldap.login', [
            'username' => $username,
            'password' => $password,
            'secret' => $secret,
        ]);

        $result = $response['result'] ?? [];

        return is_array($result) ? $result : [];
    }
}
