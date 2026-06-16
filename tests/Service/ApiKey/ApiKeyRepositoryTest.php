<?php

declare(strict_types=1);

namespace App\Tests\Service\ApiKey;

use App\Service\ApiKey\ApiKeyRepository;
use App\Service\SimpleDatabase\SimpleDataService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiKeyRepositoryTest extends TestCase
{
    private string $file;
    private ApiKeyRepository $keys;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ss_keys_' . bin2hex(random_bytes(6)) . '.json';
        $this->keys = new ApiKeyRepository(new SimpleDataService($this->file));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file . '*') ?: [] as $f) {
            @unlink($f);
        }
    }

    #[Test]
    public function created_key_resolves_to_its_id_and_others_do_not(): void
    {
        self::assertFalse($this->keys->hasAny());

        [$id, $token] = $this->keys->create('ci');

        self::assertNotSame('', $id);
        self::assertTrue($this->keys->hasAny());
        self::assertSame($id, $this->keys->resolve($token));
        self::assertNull($this->keys->resolve('wrong'));
        self::assertNull($this->keys->resolve(''));
    }

    #[Test]
    public function plaintext_token_is_never_stored(): void
    {
        [, $token] = $this->keys->create('ci');

        self::assertStringNotContainsString($token, (string) file_get_contents($this->file));
        self::assertStringContainsString(hash('sha256', $token), (string) file_get_contents($this->file));
    }

    #[Test]
    public function revoked_key_no_longer_verifies(): void
    {
        [$id, $token] = $this->keys->create('ci');

        self::assertTrue($this->keys->revoke($id));
        self::assertNull($this->keys->resolve($token));
        self::assertFalse($this->keys->hasAny());
        self::assertFalse($this->keys->revoke('nope'));
    }

    #[Test]
    public function all_lists_keys_without_secrets(): void
    {
        $this->keys->create('alpha');
        $this->keys->create('beta');

        $all = $this->keys->all();

        self::assertCount(2, $all);
        self::assertSame(['alpha', 'beta'], array_map(static fn (array $k): string => $k['label'], $all));
        foreach ($all as $entry) {
            self::assertArrayNotHasKey('hash', $entry);
        }
    }
}
