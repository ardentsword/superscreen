<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ApiKey\ApiKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:apikey:revoke', 'Revoke an API key by id')]
final class ApiKeyRevokeCommand extends Command
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The key id (see app:apikey:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        if (!$this->keys->revoke($id)) {
            $io->error(\sprintf('No API key with id "%s".', $id));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Revoked API key "%s".', $id));

        return Command::SUCCESS;
    }
}
