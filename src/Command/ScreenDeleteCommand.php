<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Screen\ScreenRegistry;
use App\Service\Screen\ScreenStoreFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:screen:delete', 'Delete a screen and its store')]
final class ScreenDeleteCommand extends Command
{
    public function __construct(
        private readonly ScreenRegistry $screens,
        private readonly ScreenStoreFactory $stores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Screen id to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        if ($id === ScreenRegistry::DEFAULT_ID) {
            $io->error(\sprintf('The "%s" screen cannot be deleted.', $id));

            return Command::FAILURE;
        }

        if (!$this->screens->has($id)) {
            $io->warning(\sprintf('No screen "%s".', $id));

            return Command::SUCCESS;
        }

        $this->screens->delete($id);
        $this->stores->deleteStore($id);
        $io->success(\sprintf('Screen "%s" deleted.', $id));

        return Command::SUCCESS;
    }
}
