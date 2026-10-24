<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Listener;

use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
final class LoadPublicShareScripts implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || $event->getScope() === BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH) return;
		// Public shares dispatch LoadViewer before this event. The viewer-scoped
		// listener adds the bundle there, just like files_pdfviewer does.
	}
}
