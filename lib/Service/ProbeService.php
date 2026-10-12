<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Service;

use OCP\Files\File;

final class ProbeService {
	private const FFPROBE = '/usr/lib/jellyfin-ffmpeg/ffprobe';

	/** @return array{duration: float, bitRate: int} */
	public function probe(File $file, FileAccessService $files): array {
		$path = $files->localPath($file);
		$command = implode(' ', array_map('escapeshellarg', [
			self::FFPROBE, '-v', 'error', '-show_entries', 'format=duration,bit_rate:stream=codec_type,width,height,avg_frame_rate,pix_fmt', '-of', 'json', $path,
		]));
		$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptor, $pipes);
		if (!is_resource($process)) {
			throw new \RuntimeException('ffprobe_launch_failed', 503);
		}
		$output = stream_get_contents($pipes[1]) ?: '';
		$errors = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		if ($exit !== 0) {
			throw new \RuntimeException('ffprobe_failed: ' . trim($errors), 422);
		}
		$data = json_decode($output, true);
		$duration = (float)($data['format']['duration'] ?? 0);
		$bitRate = (int)($data['format']['bit_rate'] ?? 0);
		if ($duration <= 0) {
			throw new \RuntimeException('ffprobe_duration_missing', 422);
		}
		$video = array_values(array_filter($data['streams'] ?? [], static fn(array $stream): bool => ($stream['codec_type'] ?? '') === 'video'))[0] ?? [];
		return ['duration' => $duration, 'bitRate' => $bitRate, 'width' => (int)($video['width'] ?? 1280), 'height' => (int)($video['height'] ?? 640)];
	}
}
