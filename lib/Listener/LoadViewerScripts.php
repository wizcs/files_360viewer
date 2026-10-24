<?php

declare(strict_types=1);

namespace OCA\Files360viewer\Listener;

use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadViewer> */
final class LoadViewerScripts implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadViewer) {
			return;
		}

		// Match files_pdfviewer/camerarawpreviews: load this bundle only when
		// Nextcloud is preparing the Viewer shell. The Files page itself stays
		// untouched, so files_sharing keeps its native capability resolution.
		Util::addInitScript('files_360viewer', 'viewer-component');
		Util::addInitScript('files_360viewer', 'bridge');
		Util::addStyle('files_360viewer', 'main');
	}
}
