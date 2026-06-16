<?php

declare(strict_types=1);

namespace App\Service\ApiKey;

use App\Service\SimpleDatabase\SimpleDataService;

/**
 * API keys for write requests. Only a SHA-256 hash of each token is stored
 * (never the plaintext), in a dedicated JSON store keyed by a short key id.
 * See docs/BACKEND.md §8.
 */
readonly class ApiKeyRepository
{
    public function __construct(
        private SimpleDataService $store,
    ) {}

    /**
     * Create a key and return [id, token]. The token is shown once and never
     * stored — only its hash is kept.
     *
     * @return array{0: string, 1: string}
     */
    public function create(string $label): array
    {
        $id = bin2hex(random_bytes(4));
        $token = bin2hex(random_bytes(32));

        $this->store->set($id, [
            'label' => $label,
            'hash' => hash('sha256', $token),
            'created_at' => time(),
        ]);

        return [$id, $token];
    }

    /**
     * The id of the key matching the token (constant-time comparison), or null
     * if none matches.
     */
    public function resolve(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        $candidate = hash('sha256', $token);
        foreach ($this->store->getAll() as $id => $entry) {
            if (isset($entry['hash']) && hash_equals((string) $entry['hash'], $candidate)) {
                return (string) $id;
            }
        }

        return null;
    }

    public function revoke(string $id): bool
    {
        if ($this->store->get($id) === null) {
            return false;
        }

        $this->store->remove($id);

        return true;
    }

    /**
     * @return list<array{id: string, label: string, created_at: int}>
     */
    public function all(): array
    {
        $keys = [];
        foreach ($this->store->getAll() as $id => $entry) {
            $keys[] = [
                'id' => (string) $id,
                'label' => (string) ($entry['label'] ?? ''),
                'created_at' => (int) ($entry['created_at'] ?? 0),
            ];
        }

        return $keys;
    }

    public function hasAny(): bool
    {
        return $this->store->getAll() !== [];
    }
}
