<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

final class Raw360PreviewProvider implements IProviderV2 {
	private const TIMEOUT_SECONDS = 8;

	public function __construct(private \OCA\Files360viewer\Service\MediaToolService $tools) {
	}

	public function getMimeType(): string {
		return '#^video/x-(insv|osv)$#';
	}
	public function isAvailable(FileInfo $file): bool {
		return $file->getSize() > 0;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$temp = tempnam(sys_get_temp_dir(), 'nc360-thumb-');
		if ($temp === false) {
			return null;
		}
		try {
			$paths = [$file->getStorage()->getLocalFile($file->getInternalPath())];
			$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
			if ($extension === 'insv' && preg_match('/^(.*)_(00|10)_([^_]*)\.insv$/i', $file->getName(), $m)) {
				$partner = $m[1] . '_' . ($m[2] === '00' ? '10' : '00') . '_' . $m[3] . '.insv';
				foreach ($file->getParent()->getDirectoryListing() as $node) {
					if ($node instanceof File && strcasecmp($node->getName(), $partner) === 0) {
						$paths[] = $node->getStorage()->getLocalFile($node->getInternalPath());
						break;
					}
				}
			}
			foreach ($paths as $path) {
				if (!is_readable($path)) {
					return null;
				}
			}

			$width = min(512, max(2, $maxX));
			$height = min(256, max(2, $maxY));
			$args = [$this->tools->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-ss', '1'];
			foreach ($paths as $path) {
				$args[] = '-i';
				$args[] = $path;
			}
			$filter = count($paths) === 1
				? "[0:v:0]scale=256:256:flags=fast_bilinear[a];[0:v:1]scale=256:256:flags=fast_bilinear[b];[a][b]hstack=inputs=2,v360=input=dfisheye:output=equirect:ih_fov=190:iv_fov=190,scale={$width}:{$height}:force_original_aspect_ratio=decrease"
				: "[0:v:0]scale=256:256:flags=fast_bilinear[a];[1:v:0]scale=256:256:flags=fast_bilinear[b];[a][b]hstack=inputs=2,v360=input=dfisheye:output=equirect:ih_fov=190:iv_fov=190,scale={$width}:{$height}:force_original_aspect_ratio=decrease";
			$args = array_merge($args, ['-an', '-frames:v', '1', '-filter_complex', $filter, '-f', 'mjpeg', $temp]);
			if (!$this->runFfmpeg($args) || !is_file($temp) || filesize($temp) === 0) {
				return null;
			}

			$image = new Image();
			$image->loadFromFile($temp);
			$image->scaleDownToFit($maxX, $maxY);
			return $image->valid() ? $image : null;
		} catch (\Throwable) {
			return null;
		} finally {
			@unlink($temp);
		}
	}

	/** @param list<string> $args */
	private function runFfmpeg(array $args): bool {
		$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($args, $descriptor, $pipes);
		if (!is_resource($proc)) {
			return false;
		}
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}
		$deadline = microtime(true) + self::TIMEOUT_SECONDS;
		$exit = null;
		$timedOut = false;
		do {
			foreach ($pipes as $pipe) {
				stream_get_contents($pipe);
			}
			$status = proc_get_status($proc);
			if (!$status['running']) {
				$exit = (int)$status['exitcode'];
				break;
			}
			if (microtime(true) >= $deadline) {
				$timedOut = true;
				proc_terminate($proc, 9);
				break;
			}
			usleep(100000);
		} while (true);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		$closedExit = proc_close($proc);
		return !$timedOut && ($exit ?? $closedExit) === 0;
	}
}
