<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Service\MediaSessionService;
use OCA\Files360viewer\Service\TranscodeService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class StatusController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private TranscodeService $transcoder) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	public function show(string $sessionId): JSONResponse {
		try {
			$s = $this->sessions->requireOwned($sessionId);
			return new JSONResponse($this->transcoder->status($s));
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
}
