<?php

namespace App\Services\Buu;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Looks up a staff record in UMS (ums.SPerson) to learn faculty / department / position.
 * National ID (psn_idcard) is never read into the result.
 */
class UmsPersonService
{
    private const TITLE_PATTERN = '/^(?:ว่าที่\s*(?:ร\.ต\.|ร้อยตรี)\s*|นางสาว|น\.ส\.|นาง|นาย|ผศ\.\s*ดร\.|รศ\.\s*ดร\.|ศ\.\s*ดร\.|อ\.\s*ดร\.|ผศ\.|รศ\.|ศ\.|ดร\.|อ\.|Mr\.?|Mrs\.?|Miss|Ms\.?|Dr\.?)\s*/u';

    public function __construct(private readonly BuuKongClient $kong) {}

    /**
     * @return array{usid: ?string, psn_id: ?string, uslogin: string, name: string, email: ?string, fac_id: ?string, fac_name: ?string, dep_id: ?string, dep_name: ?string, position: ?string, retired: bool}|null
     */
    public function findByLogin(string $username, string $fullname): ?array
    {
        $login = strtolower(trim($username));
        if ($login === '') {
            return null;
        }

        $cacheKey = 'ums.person.'.$login;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            foreach ($this->queries($fullname) as [$fname, $lname]) {
                $rows = $this->search($fname, $lname);
                foreach ($rows as $row) {
                    if (is_array($row) && strtolower(trim((string) ($row['uslogin'] ?? ''))) === $login) {
                        $person = $this->normalize($row, $login);
                        Cache::put($cacheKey, $person, (int) config('auth_ldap.ums_cache_seconds', 86400));

                        return $person;
                    }
                }
            }
        } catch (BuuApiException|ConnectionException $exception) {
            Log::warning('UMS person lookup failed', [
                'http_status' => $exception instanceof BuuApiException ? $exception->statusCode : null,
            ]);
        }

        return null;
    }

    /**
     * Candidate [fname, lname] pairs, most specific first.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function queries(string $fullname): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $fullname));
        if ($clean === '') {
            return [];
        }

        $stripped = trim((string) preg_replace(self::TITLE_PATTERN, '', $clean));

        $pairs = [];
        foreach (array_unique([$stripped, $clean]) as $name) {
            if ($name === '') {
                continue;
            }
            $parts = explode(' ', $name);
            $fname = $parts[0];
            $lname = count($parts) > 1 ? $parts[count($parts) - 1] : '';
            if ($lname !== '') {
                $pairs[] = [$fname, $lname];
            }
            $pairs[] = [$fname, ''];
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[implode('|', $pair)] = $pair;
        }

        return array_slice(array_values($unique), 0, 3);
    }

    /** @return list<mixed> */
    private function search(string $fname, string $lname): array
    {
        $response = $this->kong->postJson('ums.person', [
            'facid' => '',
            'depid' => '',
            'fname' => $fname,
            'lname' => $lname,
        ]);

        $result = $response['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    /** Public directory search returns only normalized fields, never raw SPerson rows. */
    public function searchPeople(string $query): array
    {
        $people = [];
        foreach ($this->queries($query) as [$first, $last]) {
            foreach ($this->search($first, $last) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $login = strtolower(trim((string) ($row['uslogin'] ?? '')));
                if ($login === '') {
                    continue;
                }
                $person = $this->normalize($row, $login);
                $person['username'] = $login;
                Cache::put('ums.directory.person.'.$login, $person, 600);
                $people[$login] = $person;
                if (count($people) >= 50) {
                    break 2;
                }
            }
        }

        return array_values($people);
    }

    public function directoryPerson(string $username): ?array
    {
        $person = Cache::get('ums.directory.person.'.strtolower(trim($username)));

        return is_array($person) ? $person : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{usid: ?string, psn_id: ?string, uslogin: string, name: string, email: ?string, fac_id: ?string, fac_name: ?string, dep_id: ?string, dep_name: ?string, position: ?string, retired: bool}
     */
    private function normalize(array $row, string $login): array
    {
        return [
            'usid' => $this->str($row['usid'] ?? null),
            'psn_id' => $this->str($row['psn_id'] ?? null),
            'uslogin' => $login,
            'name' => trim($this->str($row['psn_fnameth'] ?? null).' '.$this->str($row['psn_lnameth'] ?? null)),
            'email' => $this->str($row['psn_email'] ?? null) ?: null,
            'fac_id' => $this->str($row['fac_id'] ?? null) ?: null,
            'fac_name' => $this->str($row['fac_nameth'] ?? null) ?: null,
            'dep_id' => $this->str($row['dep_id'] ?? null) ?: null,
            'dep_name' => $this->str($row['dep_nameth'] ?? null) ?: null,
            'position' => $this->str($row['liw_nameth'] ?? null) ?: null,
            'retired' => strtoupper($this->str($row['psn_retire'] ?? null)) === 'Y',
        ];
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
