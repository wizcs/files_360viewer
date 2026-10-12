# files_360viewer

Nextcloud app for authenticated playback of Insta360 INSV and DJI Osmo 360 OSV originals. Every request resolves the authenticated user's file by `IRootFolder::getUserFolder()->getById()`, rechecks READ permission, the paired INSV node, ETags and session ownership, and serves bounded HTTP ranges. Physical paths are never accepted from the client or returned in an API response.

The transcode worker uses the verified dual-fisheye `hstack` + `v360` filter, downscales the ERP to 1280x640, and emits event HLS at 5 and 10 Mbps. The browser uses hls.js in a normal video window with seek, audio and bitrate controls. Source files are never modified.

## Server requirements

- Nextcloud 34 or newer with the Files app enabled.
- PHP `proc_open` support and permission for the web worker to run the media tools.
- FFmpeg and FFprobe with the `v360` filter and HLS/fMP4 support. The app checks common paths and the worker `PATH` automatically. If the binaries are installed elsewhere, configure them with:

  ```bash
  occ config:app:set files_360viewer ffmpeg_path --value=/absolute/path/to/ffmpeg
  occ config:app:set files_360viewer ffprobe_path --value=/absolute/path/to/ffprobe
  ```

- Optional VAAPI acceleration requires a working `/dev/dri/renderD128` device. Software encoding remains the fallback.

## AI-assisted development disclosure

Parts of this application were developed with assistance from AI tools. Human maintainers remain responsible for the source code, architecture, security review, testing, licensing and release decisions.


## Release dependencies

