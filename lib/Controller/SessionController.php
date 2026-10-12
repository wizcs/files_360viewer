<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Service\MediaSessionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

final class SessionController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private IURLGenerator $urlGenerator) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	public function create(): JSONResponse {
		try {
			$body = $this->jsonBody();
			$fileId = filter_var($body['fileId'] ?? null, FILTER_VALIDATE_INT);
			$mode = (string)($body['mode'] ?? 'transcode');
			if ($fileId === false || !in_array($mode, ['direct','transcode'], true)) {
				throw new \RuntimeException('invalid_session_request', 400);
			}
			$bitrate = isset($body['targetBitrateMbps']) ? (int)$body['targetBitrateMbps'] : null;
			if ($bitrate !== null && ($bitrate < 1 || $bitrate > 100)) {
				throw new \RuntimeException('unsupported_target_bitrate', 400);
			}
			$s = $this->sessions->create((int)$fileId, $mode, $bitrate);
			$sourceUrls = [];
			foreach (array_keys($s['inputFileIds']) as $inputIndex) {
				$sourceUrls[] = $this->urlGenerator->linkToRoute('files_360viewer.source.read', ['sessionId' => $s['id']]) . ($inputIndex === 0 ? '' : '?input=' . $inputIndex);
			}
			return new JSONResponse(['sessionId' => $s['id'], 'state' => $s['state'], 'mode' => $s['mode'], 'fileId' => $s['fileId'], 'name' => $s['name'], 'size' => $s['size'], 'durationSeconds' => $s['durationSeconds'], 'bitratesMbps' => $s['bitratesMbps'], 'profiles' => $s['profiles'], 'defaultProfile' => $s['defaultProfile'] ?? null, 'expiresAt' => date(DATE_ATOM, $s['expiresAt']), 'sourceUrl' => $sourceUrls[0], 'sourceUrls' => $sourceUrls, 'statusUrl' => $this->urlGenerator->linkToRoute('files_360viewer.status.show', ['sessionId' => $s['id']]), 'transcodeUrl' => $this->urlGenerator->linkToRoute('files_360viewer.transcode.create', ['sessionId' => $s['id']]), 'capabilities' => ['seek' => true,'viewPan' => true,'audio' => true,'equirectangular' => true,'availableBitratesMbps' => $s['bitratesMbps'], 'direct' => true, 'transcode' => true]]);
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
	#[NoAdminRequired]
	public function show(string $sessionId): JSONResponse {
		try {
			$s = $this->sessions->requireOwned($sessionId);
			unset($s['file']);
			return new JSONResponse($s);
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
	#[NoAdminRequired]
	public function destroy(string $sessionId): JSONResponse {
		try {
			$this->sessions->delete($sessionId);
			return new JSONResponse(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
}
