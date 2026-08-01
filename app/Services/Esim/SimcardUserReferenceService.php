<?php

namespace App\Services\Esim;

use RuntimeException;

final class SimcardUserReferenceService
{
    public function derive(int $userId, ?int $version = null): string
    {
        if ($userId < 1) {
            throw new RuntimeException('User ID must be a positive integer.');
        }

        $version ??= $this->currentVersion();
        $key = $this->keyForVersion($version);

        return sprintf(
            'v%d:%s',
            $version,
            hash_hmac(
                'sha256',
                sprintf('stellar-simcard-user-reference:v%d:%d', $version, $userId),
                $key,
            ),
        );
    }


    /** @return array<int, string> */
    public function deriveAll(int $userId): array
    {
        $references = [];

        foreach ($this->configuredVersions() as $version) {
            $references[$version] = $this->derive($userId, $version);
        }

        return $references;
    }

    /** @return list<int> */
    public function configuredVersions(): array
    {
        $keys = (array) config('esim.user_reference.keys', []);
        $versions = [];

        foreach ($keys as $version => $key) {
            if ((string) $key === '') {
                continue;
            }

            $version = (int) $version;

            if ($version > 0) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            $versions[] = $this->currentVersion();
        }

        sort($versions);

        return array_values(array_unique($versions));
    }

    public function matches(string $storedReference, int $userId, ?int $version = null): bool
    {
        $version ??= $this->versionFromReference($storedReference) ?? $this->currentVersion();

        return hash_equals($storedReference, $this->derive($userId, $version));
    }

    public function currentVersion(): int
    {
        $version = (int) config('esim.user_reference.current_version', 1);

        if ($version < 1) {
            throw new RuntimeException('Invalid eSIM user reference hash version.');
        }

        return $version;
    }

    private function keyForVersion(int $version): string
    {
        $key = (string) config("esim.user_reference.keys.{$version}", '');

        if ($key === '') {
            throw new RuntimeException(
                "Missing eSIM user reference hash key for version {$version}."
            );
        }

        if (strlen($key) < 32) {
            throw new RuntimeException(
                "The eSIM user reference hash key for version {$version} must be at least 32 characters."
            );
        }

        return $key;
    }

    private function versionFromReference(string $reference): ?int
    {
        if (! preg_match('/^v(?<version>\d+):[a-f0-9]{64}$/', $reference, $matches)) {
            return null;
        }

        return (int) $matches['version'];
    }
}
