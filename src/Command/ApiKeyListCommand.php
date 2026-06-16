<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApiKey\ApiKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:apikey:list', 'List API keys (ids and labels only)')]
final class ApiKeyListCommand extends Command
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = $this->keys->all();

        if ($keys === []) {
            $io->warning('No API keys — write requests are currently open.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'label', 'created'],
            array_map(
                static fn (array $k): array => [$k['id'], $k['label'], date('Y-m-d H:i', $k['created_at'])],
                $keys,
            ),
        );

        return Command::SUCCESS;
    }
}
