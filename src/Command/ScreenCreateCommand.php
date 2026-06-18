<?php

declare(strict_types=1);

namespace App\Command;

use App\Screen\Screen;
use App\Service\Screen\ScreenException;
use App\Service\Screen\ScreenRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:screen:create', 'Create or update a screen (id + grid config)')]
final class ScreenCreateCommand extends Command
{
    public function __construct(
        private readonly ScreenRegistry $screens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Screen id (lowercase slug)');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name (defaults to the id)');
        $this->addOption('cols', null, InputOption::VALUE_REQUIRED, 'Grid columns');
        $this->addOption('rows', null, InputOption::VALUE_REQUIRED, 'Grid rows');
        $this->addOption('gap', null, InputOption::VALUE_REQUIRED, 'Grid gap (px)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        try {
            // getOrCreate validates the id and seeds defaults for a new screen.
            $current = $this->screens->getOrCreate($id);

            $screen = new Screen(
                $id,
                $input->getOption('name') !== null ? (string) $input->getOption('name') : $current->getName(),
                $input->getOption('cols') !== null ? max(1, (int) $input->getOption('cols')) : $current->getCols(),
                $input->getOption('rows') !== null ? max(1, (int) $input->getOption('rows')) : $current->getRows(),
                $input->getOption('gap') !== null ? max(0, (int) $input->getOption('gap')) : $current->getGap(),
            );
            $this->screens->save($screen);
        } catch (ScreenException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Screen "%s" saved.', $id));
        $io->definitionList(
            ['id' => $screen->getId()],
            ['name' => $screen->getName()],
            ['grid' => \sprintf('%d × %d, gap %d', $screen->getCols(), $screen->getRows(), $screen->getGap())],
            ['display' => '/screens/' . $screen->getId()],
        );

        return Command::SUCCESS;
    }
}
