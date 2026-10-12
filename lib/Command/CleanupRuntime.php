<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Command;

use OCA\Files360viewer\Service\MediaSessionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanupRuntime extends Command {
	protected static $defaultName = 'files_360viewer:cleanup-runtime';

	public function __construct(private MediaSessionService $sessions) {
		parent::__construct();
		$this->setDescription('Remove expired 360 viewer sessions and their transcode artifacts.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Removed ' . $this->sessions->reapExpired() . ' expired session(s).</info>');
		return Command::SUCCESS;
	}
}
