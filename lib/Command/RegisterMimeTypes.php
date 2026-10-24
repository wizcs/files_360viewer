<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Command;

use OCP\Files\IMimeTypeLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Idempotently installs the custom MIME mappings used by the viewer. */
final class RegisterMimeTypes extends Command {
	protected static $defaultName = 'files_360viewer:mime-types';

	public function __construct(private IMimeTypeLoader $mimeTypeLoader) {
		parent::__construct();
		$this->setName('files_360viewer:mime-types');
		$this->setDescription('Register INSV and OSV MIME types and repair the file cache mapping.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$map = ['insv' => 'video/x-insv', 'osv' => 'video/x-osv'];
		try {
			foreach ($map as $extension => $mime) {
				$mimeId = $this->mimeTypeLoader->getId($mime);
				if ($mimeId < 1) {
					throw new \RuntimeException(sprintf('Nextcloud did not return a MIME id for %s.', $mime));
				}
				$this->mimeTypeLoader->updateFilecache($extension, $mimeId);
				$output->writeln(sprintf('<info>.%s → %s (id %d)</info>', $extension, $mime, $mimeId));
			}
			$path = (string)\OC::$configDir . '/mimetypemapping.json';
			$mapping = [];
			if (is_file($path)) {
				$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					throw new \RuntimeException('Existing mimetypemapping.json is not an object.');
				}
				$mapping = $decoded;
			}
			foreach ($map as $extension => $mime) {
				$mapping[$extension] = [$mime];
			}
			ksort($mapping);
			$tmp = $path . '.files_360viewer.tmp';
			if (file_put_contents($tmp, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false || !rename($tmp, $path)) {
				@unlink($tmp);
				throw new \RuntimeException('Unable to atomically write ' . $path);
			}
			$output->writeln('<info>MIME mapping written idempotently to ' . $path . '</info>');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$output->writeln('<error>files_360viewer MIME registration failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
	}
}
