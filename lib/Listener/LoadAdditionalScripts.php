<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
final class LoadAdditionalScripts implements IEventListener {
	public function __construct(private IRequest $request) {
	}
	public function handle(Event $event): void {
		if ($event instanceof LoadAdditionalScriptsEvent) {
			$uri = $this->request->getRequestUri();
			if (!str_contains($uri, '/apps/files') && !str_contains($uri, '/apps/files_360viewer')) {
				return;
			}
			Util::addScript('files_360viewer', 'main');
			Util::addStyle('files_360viewer', 'main');
		}
	}
}
