# files_360viewer

Nextcloud app for permission-checked playback of Insta360 INSV and DJI Osmo 360 OSV originals. Every request resolves the authenticated user's file by `IRootFolder::getUserFolder()->getById()`, rechecks read permission, paired INSV nodes, ETags and session ownership, and serves bounded HTTP ranges. Physical paths are never accepted from the client or returned in an API response.

The app provides server-side dual-fisheye stitching and equirectangular HLS playback with seek, audio and selectable bitrate profiles. Source files are opened read-only and are never modified. Public share links opened without a logged-in Nextcloud user are not supported by the current playback endpoints.

## Server requirements

- Nextcloud 34 with the Files app enabled.
- PHP `proc_open` support and permission for the web worker to run media tools.
- FFmpeg and FFprobe with the `v360` filter and HLS/fMP4 support.
- Optional Python 3 with NumPy for calibrated OSV processing.
- Optional VAAPI acceleration requires a working `/dev/dri/renderD128` device; software encoding remains the fallback.

The app checks configured paths, common system paths, and the worker `PATH`. If the binaries are installed elsewhere, configure them with:

```bash
occ config:app:set files_360viewer ffmpeg_path --value=/absolute/path/to/ffmpeg
occ config:app:set files_360viewer ffprobe_path --value=/absolute/path/to/ffprobe
```

## Development

```bash
npm ci
npm run build
node --test tests/*.mjs
```

The generated browser bundle is committed under `js/`. Development dependencies and runtime cache files are excluded from release archives.

## License and notices

This app is licensed under AGPL-3.0-or-later. See [LICENSE](LICENSE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

Parts of this application were developed with assistance from AI tools. Human maintainers remain responsible for the source code, architecture, security review, testing, licensing and release decisions.
