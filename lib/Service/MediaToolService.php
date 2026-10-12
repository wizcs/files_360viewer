<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Service;

use OCP\IConfig;

/** Resolves the external FFmpeg tools without assuming one distribution path. */
final class MediaToolService {
	private const APP_ID = 'files_360viewer';

	public function __construct(private IConfig $config) {
	}

	public function ffmpeg(): string {
		return $this->resolve('ffmpeg_path', 'ffmpeg', [
			'/usr/bin/ffmpeg',
			'/usr/local/bin/ffmpeg',
			'/usr/lib/jellyfin-ffmpeg/ffmpeg',
		]);
	}

	public function ffprobe(): string {
		return $this->resolve('ffprobe_path', 'ffprobe', [
			'/usr/bin/ffprobe',
			'/usr/local/bin/ffprobe',
			'/usr/lib/jellyfin-ffmpeg/ffprobe',
		]);
	}

	private function resolve(string $configKey, string $binary, array $knownPaths): string {
		$configured = trim($this->config->getAppValue(self::APP_ID, $configKey, ''));
		$candidates = $configured !== '' ? [$configured] : array_merge($knownPaths, $this->pathCandidates($binary));
		foreach (array_unique($candidates) as $candidate) {
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException(sprintf(
			'%s was not found. Install FFmpeg or set the %s app config value.',
			$binary,
			$configKey
		), 503);
	}

	/** @return list<string> */
	private function pathCandidates(string $binary): array {
		$paths = [];
		foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
			if ($directory !== '') {
				$paths[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
			}
		}
		return $paths;
	}
}
