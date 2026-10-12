<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Migration;

use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Installs the two extension mappings and repairs existing file-cache rows. */
final class MimeTypeRepairStep implements IRepairStep {
	/** @var array<string, list<string>> */
	private const MAP = ['insv' => ['video/x-insv'], 'osv' => ['video/x-osv']];

	public function __construct(private IMimeTypeLoader $mimeTypeLoader) {
	}
	public function getName(): string {
		return 'Install INSV/OSV MIME mappings';
	}

	public function run(IOutput $output): void {
		foreach (self::MAP as $extension => $mimes) {
			foreach ($mimes as $mime) {
				$id = $this->mimeTypeLoader->getId($mime);
				$this->mimeTypeLoader->updateFilecache($extension, $id);
				$output->info(sprintf('Mapped .%s to %s (id %d)', $extension, $mime, $id));
			}
		}

		$configDir = (string)\OC::$configDir;
		$path = $configDir . '/mimetypemapping.json';
		$mapping = [];
		if (is_file($path)) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded)) {
				$mapping = $decoded;
			}
		}
		foreach (self::MAP as $extension => $mimes) {
			$mapping[$extension] = $mimes;
		}
		ksort($mapping);
		$tmp = $path . '.files_360viewer.tmp';
		$json = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('Unable to write ' . $path);
		}
		$output->info('MIME mapping is installed idempotently at ' . $path);
	}
}
