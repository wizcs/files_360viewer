<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

abstract class ApiController extends Controller {
	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
	}
	protected function error(\Throwable $e): JSONResponse {
		$code = (int)$e->getCode();
		if (!in_array($code, [400,401,403,404,409,415,416,429,500,503], true)) {
			$code = 500;
		}
		return new JSONResponse(['error' => $e->getMessage()], $code);
	}
	protected function jsonBody(): array {
		return $this->request->getParams();
	}
}
