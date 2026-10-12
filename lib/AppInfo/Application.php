<?php

declare(strict_types=1);
namespace OCA\Files360viewer\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files360viewer\Listener\LoadAdditionalScripts;
use OCA\Files360viewer\Preview\Raw360PreviewProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'files_360viewer';
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScripts::class);
		$context->registerPreviewProvider(Raw360PreviewProvider::class, '#^video/x-(insv|osv)$#');
	}
	public function boot(IBootContext $context): void {
	}
}
