<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Http;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

final class ManifestResponse extends Response implements ICallbackResponse {
	public function __construct(private string $body, string $etag) {
		parent::__construct(200);
		$this->addHeader('Content-Type', 'application/vnd.apple.mpegurl');
		$this->addHeader('Content-Length', (string)strlen($body));
		$this->addHeader('Cache-Control', 'private, no-store');
		$this->addHeader('ETag', '"' . $etag . '"');
	}
	public function callback(IOutput $output): void {
		$output->setOutput($this->body);
	}
}
