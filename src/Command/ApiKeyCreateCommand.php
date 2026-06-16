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

#[AsCommand('app:apikey:create', 'Create an API key for write requests')]
final class ApiKeyCreateCommand extends Command
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('label', InputArgument::REQUIRED, 'A label to identify the key (e.g. who/what uses it)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        [$id, $token] = $this->keys->create((string) $input->getArgument('label'));

        $io->success('API key created.');
        $io->definitionList(
            ['id' => $id],
            ['token' => $token],
        );
        $io->warning('Copy the token now — only its hash is stored, so it cannot be shown again.');
        $io->writeln('Send it on write requests as the header:  <info>X-Api-Key: ' . $token . '</info>');

        return Command::SUCCESS;
    }
}
