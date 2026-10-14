<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Service;

use OCP\ISession;
use OCP\Security\ISecureRandom;

final class MediaSessionService {
	private const PREFIX = 'files_360viewer.session.';
	private const TTL = 1800;
	private const RUNTIME = __DIR__ . '/../../runtime';
	private const CLEANUP_GRACE = 3600;
	private const RUNTIME_LIMIT_BYTES = 10 * 1024 * 1024 * 1024;
	public function __construct(private ISession $session, private ISecureRandom $random, private FileAccessService $files, private ProbeService $probe) {
	}
	public function create(int $fileId, string $mode, ?int $bitrate): array {
		$this->reapExpired();
		$file = $this->files->requireReadableFile($fileId);
		$inputs = $this->files->resolveMediaInputs($file);
		$probes = array_map(fn ($input): array => $this->probe->probe($input, $this->files), $inputs);
		$duration = min(array_map(static fn (array $p): float => $p['duration'], $probes));
		$sourceBitrate = max(array_map(static fn (array $p): int => $p['bitRate'], $probes));
		$sourceMbps = $sourceBitrate > 0 ? $sourceBitrate / 1000000 : 20.0;
		$sourceWidth = min(array_column($probes, 'width'));
		$sourceHeight = min(array_column($probes, 'height'));
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if ($extension === 'osv' && $mode === 'direct') {
			// OSV is served as calibrated, lossless dual-stream HLS by default.
			$mode = 'direct-osv';
		}
		$profiles = [];
		// OSV profiles keep the equirectangular 2:1 shape while offering a fast
		// preview and selectable native-size outputs for local networks.
		$presets = $extension === 'osv'
			? []
			: [['height'=>640, 'rate'=>3], ['height'=>960, 'rate'=>6], ['height'=>1280, 'rate'=>10], ['height'=>1920, 'rate'=>16]];
		if ($extension === 'osv') {
			$nativeHeight = $sourceHeight < $sourceWidth
				? min(2160, max(640, $sourceHeight))
				: min(2160, max(640, (int)$sourceWidth));
			// Start with a small panorama so the first HLS segment is available
			// quickly; full native output remains selectable in the bitrate menu.
			$presets = [
				['height'=>min($nativeHeight, 960), 'rate'=>8, 'label'=>'快速预览'],
				['height'=>min($nativeHeight, 1280), 'rate'=>12, 'label'=>'流畅'],
			];
			foreach ([16, 24, 32, 48, 64] as $nativeRate) {
				$presets[] = ['height'=>$nativeHeight, 'rate'=>$nativeRate, 'label'=>$nativeRate >= 32 ? '原始尺寸' : '原始尺寸·降码率'];
			}
		}
		foreach ($presets as $preset) {
			$maxHeight = $extension === 'osv' && $sourceHeight < $sourceWidth
				? $sourceHeight
				: (int)(floor($sourceWidth / 2) * 2);
			$height = min($preset['height'], $maxHeight);
			$rate = max(1, min($preset['rate'], (int)ceil($sourceMbps * 0.8)));
			if (array_filter($profiles, static fn(array $p): bool => $p['height'] === $height && $p['bitrateMbps'] === $rate)) continue;
			$profile = ['id'=>'v'.count($profiles), 'width'=>$height*2, 'height'=>$height, 'bitrateMbps'=>$rate];
			if (isset($preset['label'])) $profile['label'] = $preset['label'];
			$profiles[] = $profile;
		}
		$bitrates = array_column($profiles, 'bitrateMbps');
		$defaultProfile = null;
		if ($extension === 'osv' && isset($profiles[0]['id'])) {
			$defaultProfile = $profiles[0]['id'];
		}
		$defaultDistance = PHP_INT_MAX;
		foreach ($profiles as $profile) {
			if ($defaultProfile !== null) break;
			$distance = abs((int)$profile['bitrateMbps'] - ($extension === 'osv' ? 24 : 10));
			if ($distance < $defaultDistance) {
				$defaultDistance = $distance;
				$defaultProfile = $profile['id'];
			}
		}
		if ($extension === 'insv') {
			$mode = 'direct';
		}
		$uid = $this->files->requireUser()->getUID();
		$id = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$now = time();
		$data = ['id' => $id, 'uid' => $uid, 'fileId' => $fileId, 'inputFileIds' => array_map(static fn ($f): int => (int)$f->getId(), $inputs), 'etags' => array_map(static fn ($f): string => (string)$f->getEtag(), $inputs), 'etag' => $file->getEtag(), 'size' => (int)$file->getSize(), 'name' => $file->getName(), 'mime' => $file->getMimeType(), 'extension' => $extension, 'mode' => $mode, 'targetBitrateMbps' => $bitrate, 'durationSeconds' => round($duration, 3), 'sourceBitrate' => $sourceBitrate, 'bitratesMbps' => $bitrates, 'profiles' => $profiles, 'defaultProfile' => $defaultProfile, 'state' => 'ready', 'createdAt' => $now, 'expiresAt' => $now + self::TTL];
		$this->session->set(self::PREFIX.$id, $data);
		return $data;
	}
	public function requireOwned(string $id): array {
		$this->reapExpired();
		if (!preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
			throw new \RuntimeException('invalid_session', 400);
		}
		$data = $this->session->get(self::PREFIX.$id);
		if (!is_array($data) || ($data['expiresAt'] ?? 0) < time()) {
			throw new \RuntimeException('session_not_found', 404);
		}
		$uid = $this->files->requireUser()->getUID();
		if (($data['uid'] ?? null) !== $uid) {
			throw new \RuntimeException('session_owner_required', 403);
		}
		// Re-resolve the node on every request so revocation/permission changes take effect.
		$file = $this->files->requireReadableFile((int)$data['fileId']);
		if ($file->getEtag() !== ($data['etag'] ?? '')) {
			throw new \RuntimeException('source_changed', 409);
		}
		$currentInputs = $this->files->resolveMediaInputs($file);
		$expectedIds = array_map('intval', $data['inputFileIds'] ?? [$data['fileId']]);
		$currentIds = array_map(static fn ($f): int => (int)$f->getId(), $currentInputs);
		if ($expectedIds !== $currentIds) {
			throw new \RuntimeException('source_pair_changed', 409);
		}
		foreach ($currentInputs as $index => $input) {
			if (($data['etags'][$index] ?? '') !== (string)$input->getEtag()) {
				throw new \RuntimeException('source_changed', 409);
			}
		}
		$data['file'] = $file;
		return $data;
	}
	public function delete(string $id): void {
		$this->requireOwned($id);
		$this->session->remove(self::PREFIX.$id);
		$this->removeRuntime($id);
	}

	/** Remove only sessions past their TTL plus a grace period. */
	public function reapExpired(): int {
		$count = 0;
		foreach (glob(self::RUNTIME . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			$state = is_file($dir . '/state.json') ? json_decode((string)file_get_contents($dir . '/state.json'), true) : null;
			$expiresAt = is_array($state) ? (int)($state['expiresAt'] ?? 0) : 0;
			$staleAt = $expiresAt > 0 ? $expiresAt + self::CLEANUP_GRACE : ((int)@filemtime($dir) + self::CLEANUP_GRACE);
			if ($staleAt > time()) {
				continue;
			}
			$this->stopProcesses(is_array($state) ? $state : []);
			$this->removeRuntime(basename($dir));
			$count++;
		}
		$count += $this->enforceRuntimeLimit();
		return $count;
	}

	/** Keep old completed sessions from filling the disk with full OSV remuxes. */
	private function enforceRuntimeLimit(): int {
		$dirs = [];
		$total = 0;
		foreach (glob(self::RUNTIME . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			$state = is_file($dir . '/state.json') ? json_decode((string)file_get_contents($dir . '/state.json'), true) : null;
			$size = $this->runtimeDirectorySize($dir);
			$total += $size;
			$active = false;
			foreach (is_array($state) ? ($state['variants'] ?? []) : [] as $variant) {
				$pid = (int)($variant['pid'] ?? 0);
				if ($pid > 0 && is_file('/proc/' . $pid . '/cmdline')) { $active = true; break; }
			}
			// A completed session can still be serving HLS during its TTL. A
			// processing session without a live FFmpeg PID is stale and may be
			// reclaimed even if its state file was never updated.
			if (!$active && is_array($state) && ($state['state'] ?? '') === 'ready' && (int)($state['expiresAt'] ?? 0) > time()) $active = true;
			if (!$active) $dirs[] = ['path' => $dir, 'size' => $size, 'mtime' => (int)@filemtime($dir)];
		}
		usort($dirs, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
		$removed = 0;
		foreach ($dirs as $candidate) {
			if ($total <= self::RUNTIME_LIMIT_BYTES) break;
			$this->removeRuntime(basename($candidate['path']));
			$total -= $candidate['size'];
			$removed++;
		}
		return $removed;
	}

	private function runtimeDirectorySize(string $dir): int {
		$total = 0;
		foreach (glob($dir . '/*') ?: [] as $path) {
			$total += is_dir($path) ? $this->runtimeDirectorySize($path) : (int)@filesize($path);
		}
		return $total;
	}

	private function stopProcesses(array $state): void {
		foreach ($state['variants'] ?? [] as $variant) {
			$pid = (int)($variant['pid'] ?? 0);
			if ($pid < 1 || !is_file('/proc/' . $pid . '/cmdline')) continue;
			$cmdline = (string)@file_get_contents('/proc/' . $pid . '/cmdline');
			if (str_contains($cmdline, 'ffmpeg')) {
				@posix_kill($pid, 15);
			}
		}
	}

	private function removeRuntime(string $sessionId): void {
		if (!preg_match('/^[A-Za-z0-9]{32}$/', $sessionId)) return;
		$dir = self::RUNTIME . '/' . $sessionId;
		foreach (glob($dir . '/*') ?: [] as $path) {
			if (is_file($path) || is_link($path)) @unlink($path);
			elseif (is_dir($path)) {
				foreach (glob($path . '/*') ?: [] as $child) @unlink($child);
				@rmdir($path);
			}
		}
		@rmdir($dir);
	}
}
