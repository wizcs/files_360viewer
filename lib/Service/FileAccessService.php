<?php

declare(strict_types=1);
namespace OCA\Files360viewer\Service;

use OCP\Constants;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;

final class FileAccessService {
	public function __construct(private IRootFolder $rootFolder, private IUserSession $userSession) {
	}
	public function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('authentication_required', 401);
		}
		return $user;
	}
	public function requireReadableFile(int $fileId): File {
		$user = $this->requireUser();
		if ($fileId <= 0) {
			throw new \RuntimeException('invalid_file_id', 400);
		}
		$nodes = $this->rootFolder->getUserFolder($user->getUID())->getById($fileId);
		if (count($nodes) !== 1 || !($nodes[0] instanceof File)) {
			throw new \RuntimeException('file_not_found', 404);
		}
		$file = $nodes[0];
		if (($file->getPermissions() & Constants::PERMISSION_READ) === 0) {
			throw new \RuntimeException('read_permission_required', 403);
		}
		$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (!in_array($ext, ['insv', 'osv'], true)) {
			throw new \RuntimeException('unsupported_extension', 415);
		}
		return $file;
	}

	/** @return list<File> */
	public function resolveMediaInputs(File $file): array {
		$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if ($ext !== 'insv') {
			return [$file];
		}
		if (!preg_match('/^(.*)_(00|10)_([^_]*)\.insv$/i', $file->getName(), $m)) {
			return [$file];
		}
		$partnerName = $m[1] . '_' . ($m[2] === '00' ? '10' : '00') . '_' . $m[3] . '.insv';
		foreach ($file->getParent()->getDirectoryListing() as $node) {
			if ($node instanceof File && strcasecmp($node->getName(), $partnerName) === 0) {
				$inputs = [$file, $node];
				usort($inputs, static fn (File $a, File $b): int => strcmp($a->getName(), $b->getName()));
				return $inputs;
			}
		}
		throw new \RuntimeException('insv_pair_not_found', 404);
	}

	public function localPath(File $file): string {
		$path = $file->getStorage()->getLocalFile($file->getInternalPath());
		if (!is_string($path) || $path === '' || !is_readable($path)) {
			throw new \RuntimeException('source_storage_not_local', 503);
		}
		return $path;
	}
}
