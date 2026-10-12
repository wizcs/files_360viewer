<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Http\RangeResponse;
use OCA\Files360viewer\Service\FileAccessService;
use OCA\Files360viewer\Service\MediaSessionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

final class SourceController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private FileAccessService $files) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function read(string $sessionId): \OCP\AppFramework\Http\Response {
		try {
			$s = $this->sessions->requireOwned($sessionId);
			$inputIndex = (int)$this->request->getParam('input', 0);
			$inputIds = array_values(array_map('intval', $s['inputFileIds'] ?? [$s['fileId']]));
			if ($inputIndex < 0 || $inputIndex >= count($inputIds)) {
				throw new \RuntimeException('invalid_input_index', 400);
			}
			$file = $this->files->requireReadableFile($inputIds[$inputIndex]);
			$size = (int)$file->getSize();
			if ($size < 1) {
				throw new \RuntimeException('empty_source', 416);
			}
			$range = $this->request->getHeader('Range');
			$start = 0;
			$end = $size - 1;
			$partial = false;
			if ($range !== '') {
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
			if (!$partial) {
				$start = 0;
			}
			$length = $end - $start + 1;
			$h = $file->fopen('rb');
			if ($h === false) {
				throw new \RuntimeException('source_open_failed', 500);
			}
			$mime = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) === 'insv' ? 'video/mp4' : (string)$file->getMimeType();
			$response = new RangeResponse($h, $start, $length, $size, $mime, (string)$file->getEtag(), $partial ? 206 : 200);
			$response->addHeader('Content-Disposition', 'inline; filename=\"' . addcslashes($file->getName(), "\\\"") . '\"');
			return $response;
		} catch (\Throwable $e) {
			return $this->error($e);
		}
	}
}
