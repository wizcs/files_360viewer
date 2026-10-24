<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Http\ManifestResponse;
use OCA\Files360viewer\Http\RangeResponse;
use OCA\Files360viewer\Service\MediaSessionService;
use OCA\Files360viewer\Service\TranscodeService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

final class HlsController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private TranscodeService $transcoder) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function read(string $sessionId, string $variant, string $file): Response {
		try {
			$token = (string)$this->request->getParam('publicToken', '');
			$session = $token !== '' ? $this->sessions->requirePublicOwned($sessionId, $token) : $this->sessions->requireOwned($sessionId);
			[$path, $mime] = $this->transcoder->artifact($session, $variant, $file);
			$publicToken = (string)$this->request->getParam('publicToken', '');
			if (str_ends_with($file, '.m3u8') && $publicToken !== '') {
				$manifest = (string)file_get_contents($path);
				$manifest = preg_replace_callback('/^(?!#)(.+)$/m', static function (array $m) use ($publicToken): string {
					$uri = trim($m[1]);
					if ($uri === '' || str_contains($uri, '?')) return $uri;
					return $uri . '?publicToken=' . rawurlencode($publicToken);
				}, $manifest) ?? $manifest;
				$manifest = preg_replace_callback('/URI="([^"]+)"/', static function (array $m) use ($publicToken): string {
					$uri = $m[1];
					if ($uri === '' || str_contains($uri, '?')) return $m[0];
					return 'URI="' . $uri . '?publicToken=' . rawurlencode($publicToken) . '"';
				}, $manifest) ?? $manifest;
				return new ManifestResponse($manifest, sha1($path . ':' . filesize($path) . ':' . filemtime($path) . ':' . $publicToken));
			}
			$range = $this->request->getHeader('Range');
			$size = (int)filesize($path);
			$start = 0;
			$end = $size - 1;
			$partial = false;
			if ($range !== '' && !str_ends_with($file, '.m3u8')) {
				if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
					throw new \RuntimeException('invalid_range', 416);
				}
				if ($m[1] === '' && $m[2] === '') {
					throw new \RuntimeException('invalid_range', 416);
				}
				if ($m[1] === '') {
					$length = min((int)$m[2], $size);
					$start = $size - $length;
				} else {
					$start = (int)$m[1];
					$end = $m[2] === '' ? $size - 1 : (int)$m[2];
				}
				if ($start < 0 || $start >= $size || $end < $start) {
					throw new \RuntimeException('range_not_satisfiable', 416);
				}
				$end = min($end, $size - 1);
				$partial = true;
			}
			$handle = fopen($path, 'rb');
			if ($handle === false) {
				throw new \RuntimeException('hls_open_failed', 500);
			}
			$response = new RangeResponse($handle, $start, $end - $start + 1, $size, $mime, sha1($path . ':' . $size . ':' . filemtime($path)), $partial ? 206 : 200);
			// Growing manifests must be revalidated on every fetch. Only completed
			// segments/init objects are immutable within a session.
			$response->addHeader('Cache-Control', str_ends_with($file, '.m3u8') ? 'private, no-store' : 'private, max-age=300');
			return $response;
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
}
