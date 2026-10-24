# Changelog

## 0.1.90

- Add permission-checked playback from public Nextcloud share links.
- Add browser HEVC detection with automatic compatible playback fallback.
- Support Nextcloud 35 and split the Viewer bridge from the playback component.
- Refresh the production dependency lockfile and remove the reported DOMPurify vulnerability.

## 0.1.54

- Keep the Files viewer sidebar available when opening a 360 video.

## 0.1.53

- Add calibrated direct OSV playback and selectable reduced profiles.
- Keep playback sessions user-scoped with permission, ETag and runtime checks.
- Bound runtime cache cleanup and preserve source files as read-only.
- Resolve FFmpeg and FFprobe from app configuration, common paths, or PATH.
- Improve the Files viewer registration and official file picker integration.
