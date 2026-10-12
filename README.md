# files_360viewer

Nextcloud 34 app for authenticated playback of Insta360 INSV and DJI Osmo 360 OSV originals. Every request resolves the authenticated user's file by `IRootFolder::getUserFolder()->getById()`, rechecks READ permission, the paired INSV node, ETags and session ownership, and serves bounded HTTP ranges. Physical paths are never accepted from the client or returned in an API response.

The transcode worker uses the verified dual-fisheye `hstack` + `v360` filter, downscales the ERP to 1280x640, and emits event HLS at 5 and 10 Mbps. The browser uses hls.js in a normal video window with seek, audio and bitrate controls. Source files are never modified.


## Release dependencies

This app requires a server-side FFmpeg/FFprobe installation with the `v360` filter and HLS/fMP4 support. The current staging build uses the Jellyfin FFmpeg paths configured by the deployment environment; configure or revise these paths before publishing for general installation.
