<?php

// Nextcloud's FileDisplayResponse/StreamResponse provide normal file delivery,
// but neither parses Range nor emits 206 for an authenticated session artifact.
// This narrow response is retained solely for RFC 7233 byte ranges; full HLS
// objects use StreamResponse in HlsController.
declare(strict_types=1);
namespace OCA\Files360viewer\Http;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

final class RangeResponse extends Response implements ICallbackResponse {
	public function __construct(private $handle, private int $start, private int $length, private int $total, private string $mime, private string $etag, int $status = 206) {
		parent::__construct($status);
		$this->addHeader('Content-Type', $mime ?: 'application/octet-stream');
		$this->addHeader('Content-Length', (string)$length);
		$this->addHeader('Accept-Ranges', 'bytes');
		$this->addHeader('Content-Range', "bytes {$start}-".($start + $length - 1)."/{$total}");
		$this->addHeader('ETag', '"'.$etag.'"');
		$this->addHeader('Cache-Control', 'private, no-store');
		$this->addHeader('X-Content-Encoded-By', 'none');
		$this->addHeader('X-Accel-Buffering', 'no');
	}
	public function callback(IOutput $output): void {
		ignore_user_abort(false);
		set_time_limit(0);
		if (!is_resource($this->handle)) {
			$output->setHttpResponseCode(500);
			return;
		}
		if ($this->start > 0 && fseek($this->handle, $this->start) !== 0) {
			fclose($this->handle);
			$output->setHttpResponseCode(500);
			return;
		}
		stream_set_timeout($this->handle, 1);
		$left = $this->length;
		while ($left > 0 && !feof($this->handle)) {
			$chunk = fread($this->handle, min(1024 * 1024, $left));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$output->setOutput($chunk);
			$left -= strlen($chunk);
			if (connection_aborted() || connection_status() !== CONNECTION_NORMAL) {
				break;
			}
		}
		fclose($this->handle);
	}
}
