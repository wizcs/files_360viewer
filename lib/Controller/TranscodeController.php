<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Service\MediaSessionService;
use OCA\Files360viewer\Service\TranscodeService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class TranscodeController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private TranscodeService $transcoder) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[PublicPage]
	#[NoCSRFRequired]
	public function create(string $sessionId): JSONResponse {
		try {
			$token = (string)$this->request->getParam('publicToken', '');
			$session = $token !== '' ? $this->sessions->requirePublicOwned($sessionId, $token) : $this->sessions->requireOwned($sessionId);
			$body = $this->jsonBody();
			$profile = isset($body['profile']) ? (string)$body['profile'] : null;
			if ($profile !== null && !preg_match('/^v[0-9]+$/', $profile)) {
				throw new \RuntimeException('invalid_profile', 400);
			}
			$state = $this->transcoder->start($session, $profile);
			return new JSONResponse(['sessionId' => $sessionId, 'state' => $state['state'] ?? 'processing', 'progress' => $state['progress'] ?? 0, 'masterUrl' => 'hls/master/index.m3u8', 'bitratesMbps' => $state['bitratesMbps'] ?? ($session['bitratesMbps'] ?? [5,10]), 'profiles'=>$state['profiles'], 'width' => $state['width'], 'height' => $state['height'], 'durationSeconds' => $session['durationSeconds'] ?? null]);
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
}
