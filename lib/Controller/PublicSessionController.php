<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Controller;

use OCA\Files360viewer\Service\MediaSessionService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

final class PublicSessionController extends ApiController {
	public function __construct(string $appName, IRequest $request, private MediaSessionService $sessions, private IURLGenerator $urlGenerator) { parent::__construct($appName, $request); }
	#[PublicPage]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		try {
			$token = (string)$this->request->getParam('shareToken', $this->request->getParam('token', ''));
			$requestedPath = (string)$this->request->getParam('sharePath', '');
			$s = $this->sessions->createPublic($token, $requestedPath);
			$q = '?publicToken=' . rawurlencode($token);
			$sourceUrls = [];
			foreach (array_keys($s['inputFileIds']) as $inputIndex) $sourceUrls[] = $this->urlGenerator->linkToRoute('files_360viewer.source.read', ['sessionId'=>$s['id']]) . ($inputIndex === 0 ? '' : '?input=' . $inputIndex) . ($inputIndex === 0 ? $q : '&publicToken=' . rawurlencode($token));
			$directUrls = [];
			if (($s['mode'] ?? '') === 'direct-osv') foreach ([0,1] as $inputIndex) $directUrls[] = $this->urlGenerator->linkToRoute('files_360viewer.hls.read', ['sessionId'=>$s['id'],'variant'=>'v'.$inputIndex,'file'=>'index.m3u8']) . $q;
			return new JSONResponse(['sessionId'=>$s['id'],'state'=>$s['state'],'mode'=>$s['mode'],'fileId'=>$s['fileId'],'name'=>$s['name'],'size'=>$s['size'],'durationSeconds'=>$s['durationSeconds'],'profiles'=>$s['profiles'],'sourceUrls'=>$sourceUrls,'directUrls'=>$directUrls,'statusUrl'=>$this->urlGenerator->linkToRoute('files_360viewer.status.show',['sessionId'=>$s['id']]).$q,'transcodeUrl'=>$this->urlGenerator->linkToRoute('files_360viewer.transcode.create',['sessionId'=>$s['id']]).$q,'capabilities'=>['seek'=>true,'viewPan'=>true,'audio'=>true,'direct'=>true,'transcode'=>true]]);
		} catch (\Throwable $e) { return $this->error($e); }
	}
}
