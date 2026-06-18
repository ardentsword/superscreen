<?php

declare(strict_types=1);

namespace App\Command;

use App\Screen\Screen;
use App\Service\Screen\ScreenRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:screen:list', 'List the configured screens and their grids')]
final class ScreenListCommand extends Command
{
    public function __construct(
        private readonly ScreenRegistry $screens,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $screens = $this->screens->all();

        if ($screens === []) {
            $io->note('No screens yet — the "main" screen is created on first use.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'name', 'cols', 'rows', 'gap'],
            array_map(
                static fn (Screen $s): array => [$s->getId(), $s->getName(), $s->getCols(), $s->getRows(), $s->getGap()],
                $screens,
            ),
        );

        return Command::SUCCESS;
    }
}
