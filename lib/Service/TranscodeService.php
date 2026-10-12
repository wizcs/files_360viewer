<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Service;

use OCP\Files\File;

final class TranscodeService {
	private const RUNTIME = __DIR__ . '/../../runtime';
	private ?bool $vaapiAvailable = null;

	public function __construct(private FileAccessService $files, private MediaToolService $tools) {
	}

	public function start(array $session, ?string $requestedProfile = null): array {
		$dir = self::RUNTIME . '/' . $session['id'];
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException('runtime_directory_failed', 500);
		}
		$statePath = $dir . '/state.json';
		$state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
		if (is_array($state) && in_array($state['state'] ?? '', ['queued', 'processing', 'ready'], true)) {
			if ($requestedProfile !== null) {
				$this->startRequestedProfile($session, $state, $statePath, $requestedProfile);
				$state = json_decode((string)file_get_contents($statePath), true) ?: $state;
			}
			return $state;
		}

		$inputFiles = [];
		foreach (array_map('intval', $session['inputFileIds'] ?? [$session['fileId']]) as $id) {
			$inputFiles[] = $this->files->requireReadableFile($id);
		}
		if (count($inputFiles) === 0) {
			throw new \RuntimeException('no_media_inputs', 404);
		}
		$paths = array_map(fn (File $f): string => $this->files->localPath($f), $inputFiles);
		$bitrates = array_values(array_map('intval', $session['bitratesMbps'] ?? [5, 10]));
		sort($bitrates);
		if (count($bitrates) < 2) {
			$bitrates = [5, 10];
		}
		$profiles = $session['profiles'] ?? [['id'=>'v5','width'=>1280,'height'=>640,'bitrateMbps'=>5],['id'=>'v10','width'=>1920,'height'=>960,'bitrateMbps'=>10]];
		$this->writeMaster($dir, $profiles);
		$state = [
			'sessionId' => $session['id'],
			'expiresAt' => (int)($session['expiresAt'] ?? (time() + 1800)),
			'state' => 'processing',
			'progress' => 0,
			'startedAt' => microtime(true),
			'bitratesMbps' => $bitrates,
			'profiles' => $profiles,
			'width' => $profiles[0]['width'],
			'height' => $profiles[0]['height'],
			'master' => 'master/index.m3u8',
			'variants' => [],
			'logs' => [],
			'decode' => $this->canUseVaapi() ? 'vaapi' : 'software',
			'encoder' => $this->canUseVaapi() ? 'h264_vaapi (scaled <=2160)' : 'libx264 (VAAPI unavailable)',
		];
		$this->writeState($statePath, $state);
		foreach ($profiles as $profile) {
			$state['variants'][$profile['id']] = ['bitrateMbps' => $profile['bitrateMbps'], 'pid' => 0, 'requested' => false, 'playable' => false, 'width'=>$profile['width'], 'height'=>$profile['height'], 'playlist' => $profile['id'] . '/index.m3u8', 'log' => 'ffmpeg-' . $profile['id'] . '.log'];
		}
		$this->writeState($statePath, $state);
		$this->startRequestedProfile($session, $state, $statePath, $requestedProfile ?: (string)$profiles[0]['id']);
		$state = json_decode((string)file_get_contents($statePath), true) ?: $state;
		return $state;
	}

	public function status(array $session): array {
		$dir = self::RUNTIME . '/' . $session['id'];
		$path = $dir . '/state.json';
		if (!is_file($path)) {
			return ['sessionId' => $session['id'], 'state' => 'idle', 'progress' => 0, 'bitratesMbps' => [5, 10]];
		}
		$state = json_decode((string)file_get_contents($path), true);
		if (!is_array($state)) {
			return ['sessionId' => $session['id'], 'state' => 'error', 'progress' => 0, 'error' => 'invalid_state'];
		}
		$done = 0; $playable = 0; $requested = 0;
		$variants = $state['variants'] ?? [];
		foreach ($variants as $variantId => &$variant) {
			if (!($variant['requested'] ?? false)) continue;
			$requested++;
			$playlist = $dir . '/' . $variant['playlist'];
			if ($this->isCompletePlaylist($playlist)) {
				$done++;
				$text = (string)file_get_contents($playlist);
				file_put_contents($playlist, str_replace('#EXT-X-PLAYLIST-TYPE:EVENT', '#EXT-X-PLAYLIST-TYPE:VOD', $text));
			}
			$variant['playable'] = is_file($playlist) && str_contains((string)file_get_contents($playlist), '#EXTINF:');
			if ($variant['playable']) $playable++;
		}
		unset($variant);
		// Attach as soon as the first variant has a segment. Remaining variants
		// continue encoding in the background and become selectable as they arrive.
		$state['playable'] = $playable > 0;
		$state['variants'] = $variants;
		$state['progress'] = $requested > 0 ? $done / $requested : 0;
		if ($requested === count($variants) && $done === $requested) {
			$state['state'] = 'ready';
			$state['readyAt'] ??= microtime(true);
		} elseif ($this->hasFailure($dir, $variants) && $playable === 0) {
			$state['state'] = 'error';
			$state['error'] = 'ffmpeg_failed';
		} else {
			$state['state'] = 'processing';
		}
		$this->writeState($path, $state);
		return $state;
	}

	private function startRequestedProfile(array $session, array $state, string $statePath, string $profileId): void {
		$variants = $state['variants'] ?? [];
		if (!isset($variants[$profileId]) || ($variants[$profileId]['requested'] ?? false)) return;
		$inputFiles = [];
		foreach (array_map('intval', $session['inputFileIds'] ?? [$session['fileId']]) as $id) {
			$inputFiles[] = $this->files->requireReadableFile($id);
		}
		$paths = array_map(fn (File $f): string => $this->files->localPath($f), $inputFiles);
		$profile = null;
		foreach ($state['profiles'] ?? [] as $candidate) {
			if (($candidate['id'] ?? '') === $profileId) { $profile = $candidate; break; }
		}
		if (!is_array($profile)) throw new \RuntimeException('unknown_profile', 400);
		$dir = self::RUNTIME . '/' . $session['id'];
		$variantDir = $dir . '/' . $profileId;
		if (!is_dir($variantDir) && !mkdir($variantDir, 0700, true) && !is_dir($variantDir)) throw new \RuntimeException('runtime_variant_directory_failed', 500);
		$log = $dir . '/ffmpeg-' . $profileId . '.log';
		$args = $this->buildCommand($paths, $session['extension'] ?? '', (int)$profile['bitrateMbps'], $variantDir, $log, (int)$profile['width'], (int)$profile['height']);
		$variants[$profileId]['pid'] = $this->launch($args, $log);
		$variants[$profileId]['requested'] = true;
		$state['variants'] = $variants;
		$state['state'] = 'processing';
		$this->writeState($statePath, $state);
	}

	public function artifact(array $session, string $variant, string $file): array {
		if (!preg_match('/^(master|v[0-9]+)$/', $variant) || !preg_match('/^[A-Za-z0-9_.-]+$/', $file)) {
			throw new \RuntimeException('invalid_hls_path', 400);
		}
		$base = self::RUNTIME . '/' . $session['id'];
		$relative = $variant . '/' . $file;
		$path = $base . '/' . $relative;
		$realBase = realpath($base);
		$realPath = realpath($path);
		if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
			throw new \RuntimeException('hls_not_ready', 404);
		}
		$mime = str_ends_with($file, '.m3u8') ? 'application/vnd.apple.mpegurl' : (str_ends_with($file, '.mp4') || str_ends_with($file, '.m4s') ? 'video/mp4' : 'application/octet-stream');
		return [$realPath, $mime];
	}

	public function cleanup(string $sessionId): void {
		$dir = self::RUNTIME . '/' . $sessionId;
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') ?: [] as $path) {
			if (is_file($path)) {
				@unlink($path);
			} elseif (is_dir($path)) {
				foreach (glob($path . '/*') ?: [] as $child) {
					if (is_file($child)) {
						@unlink($child);
					}
				}
				@rmdir($path);
			}
		}
		@rmdir($dir);
	}

	/** @return list<string> */
	private function buildCommand(array $paths, string $extension, int $rate, string $outDir, string $log, int $width = 1280, int $height = 640): array {
		$args = [$this->tools->ffmpeg(), '-hide_banner', '-nostdin', '-y', '-filter_complex_threads', '2'];
		$vaapi = $this->canUseVaapi();
		if ($vaapi) {
			$args = array_merge($args, ['-vaapi_device', '/dev/dri/renderD128']);
		}
		$isOsv = strtolower($extension) === 'osv';
		foreach ($paths as $path) {
			$args = array_merge($args, ['-threads','2']);
			if ($vaapi) $args = array_merge($args, ['-hwaccel','vaapi','-hwaccel_device','/dev/dri/renderD128','-hwaccel_output_format','vaapi']);
			$args[] = '-i';
			$args[] = $path;
		}
		$hwDownload = $vaapi ? (strtolower($extension) === 'osv' ? 'hwdownload,format=p010le,format=yuv420p,' : 'hwdownload,format=nv12,') : '';
		if ($isOsv) {
			// Both fisheye streams are projected on CPU; VAAPI surfaces are downloaded
			// before v360, then uploaded for encoding. Profiles remain <=2160 high.
			$eye = $height;
			// The OSV camera pair is already stitched correctly, but its source
			// coordinate system is vertically inverted. Flip only the final
			// equirectangular frame so yaw, seam, and all other projection details stay unchanged.
			$filter = '[0:v:0]' . $hwDownload . 'scale=' . $eye . ':' . $eye . ':flags=fast_bilinear[a];[0:v:1]' . $hwDownload . 'scale=' . $eye . ':' . $eye . ':flags=fast_bilinear[b];[a][b]hstack=inputs=2,v360=input=dfisheye:output=equirect:ih_fov=190:iv_fov=190:interp=linear,vflip,scale=' . $width . ':' . $height . ':flags=fast_bilinear[v360]';
		} else {
			$eye = $height;
			$filter = '[0:v:0]' . $hwDownload . 'transpose=1,scale=' . $eye . ':' . $eye . ':flags=fast_bilinear[a];[1:v:0]' . $hwDownload . 'scale=' . $eye . ':' . $eye . ':flags=fast_bilinear,transpose=2[b];[a][b]hstack=inputs=2,v360=input=dfisheye:output=equirect:ih_fov=188.5:iv_fov=188.5:interp=linear,scale=' . $width . ':' . $height . ':flags=fast_bilinear[v360]';
		}
		$videoCodec = $vaapi
			? ['-c:v', 'h264_vaapi', '-profile:v', 'constrained_baseline', '-b:v', $rate . 'M', '-maxrate', $rate . 'M', '-bufsize', ($rate * 2) . 'M']
			: ['-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-profile:v', 'baseline', '-level:v', $height <= 640 ? '3.2' : ($height <= 960 ? '4.2' : '5.1'), '-b:v', $rate . 'M', '-maxrate', $rate . 'M', '-bufsize', ($rate * 2) . 'M'];
		if ($vaapi) {
			$filter = str_replace('[v360]', ',format=nv12,hwupload,scale_vaapi=w=' . $width . ':h=' . $height . '[v360]', $filter);
		}
		$pixelFormat = $vaapi ? [] : ['-pix_fmt', 'yuv420p'];
		$args = array_merge($args, [
			'-filter_complex', $filter,
			'-map', '[v360]', '-map', '0:a:0?',
			...$videoCodec,
			'-r', '30', '-threads','2', '-g', '120', '-keyint_min', '120', '-sc_threshold', '0', ...$pixelFormat,
			'-c:a', 'aac', '-b:a', '128k', '-ac', '2',
			'-f', 'hls', '-hls_time', '4', '-hls_playlist_type', 'event', '-hls_list_size', '0',
			'-hls_flags', 'independent_segments+temp_file', '-hls_segment_type', 'fmp4',
			'-hls_fmp4_init_filename', 'init.mp4',
			'-hls_segment_filename', $outDir . '/seg_%05d.m4s',
			$outDir . '/index.m3u8',
		]);
		return $args;
	}

	private function canUseVaapi(): bool {
		if ($this->vaapiAvailable !== null) {
			return $this->vaapiAvailable;
		}
		try {
			$ffmpeg = $this->tools->ffmpeg();
		} catch (\RuntimeException) {
			return $this->vaapiAvailable = false;
		}
		if (!is_readable('/dev/dri/renderD128') || !is_writable('/dev/dri/renderD128')) {
			return $this->vaapiAvailable = false;
		}
		$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$probe = [
			$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-vaapi_device', '/dev/dri/renderD128',
			'-f', 'lavfi', '-i', 'color=size=1280x640:rate=1:duration=0.2',
			'-vf', 'format=nv12,hwupload,scale_vaapi=w=1280:h=640',
			'-frames:v', '1', '-c:v', 'h264_vaapi', '-f', 'null', '-',
		];
		$process = proc_open($probe, $descriptor, $pipes);
		if (!is_resource($process)) {
			return $this->vaapiAvailable = false;
		}
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}
		$deadline = microtime(true) + 4.0;
		$running = true;
		$status = ['running' => true, 'exitcode' => -1];
		while ($running && microtime(true) < $deadline) {
			foreach ($pipes as $pipe) {
				stream_get_contents($pipe);
			}
			$status = proc_get_status($process);
			$running = (bool)($status['running'] ?? false);
			if ($running) {
				usleep(50000);
			}
		}
		if ($running) {
			proc_terminate($process, 9);
		}
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		$closedExit = proc_close($process);
		$exitCode = ($closedExit >= 0) ? $closedExit : (int)($status['exitcode'] ?? -1);
		return $this->vaapiAvailable = !$running && $exitCode === 0;
	}

	private function launch(array $args, string $log): int {
		$command = 'nohup ' . implode(' ', array_map('escapeshellarg', $args)) . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open(['/bin/sh', '-c', $command], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
		if (!is_resource($proc)) {
			throw new \RuntimeException('transcode_launch_failed', 500);
		}
		$pid = (int)trim(stream_get_contents($pipes[1]) ?: '0');
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);
		if ($pid <= 0) {
			throw new \RuntimeException('transcode_launch_failed', 500);
		}
		return $pid;
	}

	private function writeMaster(string $dir, array $profiles): void {
		$master = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n";
		foreach ($profiles as $profile) {
			$bandwidth = $profile['bitrateMbps'] * 1000000 + 128000;
			$master .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$profile['width']}x{$profile['height']}\n../{$profile['id']}/index.m3u8\n";
		}
		$masterDir = $dir . '/master';
		if (!is_dir($masterDir)) {
			mkdir($masterDir, 0700, true);
		}
		file_put_contents($masterDir . '/index.m3u8', $master);
	}

	private function writeState(string $path, array $state): void {
		file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
	}

	private function hasFailure(string $dir, array $variants = []): bool {
		$logs = $variants ? array_map(fn (array $variant): string => $dir . '/' . ($variant['log'] ?? ''), array_filter($variants, static fn (array $variant): bool => (bool)($variant['requested'] ?? false))) : (glob($dir . '/ffmpeg-*.log') ?: []);
		foreach ($logs as $log) {
			$text = (string)file_get_contents($log);
			if (str_contains($text, 'Conversion failed') || str_contains($text, 'Error reinitializing filters')) {
				return true;
			}
		}
		return false;
	}

	private function isCompletePlaylist(string $path): bool {
		if (!is_file($path) || !is_file(dirname($path) . '/init.mp4')) {
			return false;
		}
		$playlist = (string)file_get_contents($path);
		return str_contains($playlist, '#EXT-X-ENDLIST') && str_contains($playlist, '#EXTINF:');
	}
}
