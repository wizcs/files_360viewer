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
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		$profiles = [];
		// OSV is served at the source panorama dimensions. Its choices change
		// bitrate only; this keeps the browser's projection scale stable while
		// still offering a small range for slower clients and a source-quality
		// option for local networks.
		$presets = $extension === 'osv'
			? []
			: [['height'=>640, 'rate'=>3], ['height'=>960, 'rate'=>6], ['height'=>1280, 'rate'=>10], ['height'=>1920, 'rate'=>16]];
		if ($extension === 'osv') {
			$nativeHeight = min(2160, max(640, (int)min(array_column($probes, 'width'))));
			// Keep the native panorama dimensions while exposing several bitrate-only
			// choices. The high source profile remains available for local networks.
			foreach ([8, 12, 16, 24, 32, 48, 64] as $nativeRate) {
				$presets[] = ['height'=>$nativeHeight, 'rate'=>$nativeRate, 'label'=>$nativeRate >= 32 ? '原始尺寸' : '原始尺寸·降码率'];
			}
		}
		foreach ($presets as $preset) {
			$height = min($preset['height'], (int)(floor($sourceWidth / 2) * 2));
			$rate = max(1, min($preset['rate'], (int)ceil($sourceMbps * 0.8)));
			if (array_filter($profiles, static fn(array $p): bool => $p['height'] === $height && $p['bitrateMbps'] === $rate)) continue;
			$profile = ['id'=>'v'.count($profiles), 'width'=>$height*2, 'height'=>$height, 'bitrateMbps'=>$rate];
			if (isset($preset['label'])) $profile['label'] = $preset['label'];
			$profiles[] = $profile;
		}
		$bitrates = array_column($profiles, 'bitrateMbps');
		$defaultProfile = null;
		$defaultDistance = PHP_INT_MAX;
		foreach ($profiles as $profile) {
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
		return $count;
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
