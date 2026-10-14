import Hls from 'hls.js'
import * as THREE from 'three'
import { panoramaMaterial } from './PanoramaMaterial'
import { tr } from './i18n'

const apiBase = (window as any).OC?.generateUrl('/apps/files_360viewer/api/v1') || '/index.php/apps/files_360viewer/api/v1'

const viewerComponent: any = {
  name: 'Files360Viewer',
  props: { active: { type: Boolean, default: false }, fileid: { type: [Number, String], required: true }, basename: { type: String, default: '' }, mime: { type: String, default: '' }, loaded: { type: Boolean, default: false } },
  render(h: any) {
    const levels = (this.levels || []).map((level: any, index: number) => h('option', { domProps: { value: index } }, [`${level?.label ? `${level.label} · ` : ''}${level?.width || '?'}×${level?.height || '?'} · ${level?.bitrate ? `${Math.round(level.bitrate / 1000000)} Mbps` : '原始码率'}`]))
    const children = [
      h('canvas', { ref: 'canvas', class: 'nc360-canvas', attrs: { 'aria-label': '360° viewer; drag to look around, scroll to zoom' } }),
      h('video', { ref: 'video', class: 'nc360-video', attrs: { playsinline: true, preload: 'auto' }, on: { loadedmetadata: this.onLoadedMetadata, timeupdate: this.onTimeUpdate, play: () => { this.playing = true }, pause: () => { this.playing = false } } }),
      h('video', { ref: 'videoSecondary', class: 'nc360-video-secondary', attrs: { playsinline: true, preload: 'auto', muted: true } }),
      h('div', { class: 'nc360-controls' }, [
        h('button', { on: { click: this.togglePlay }, attrs: { 'aria-label': 'Play/pause' } }, [this.playing ? this.tr('Pause') : this.tr('Play')]), h('button', { on: { click: this.toggleMute } }, [this.muted ? this.tr('Unmute') : this.tr('Mute')]), h('button', { on: { click: this.resetView } }, [this.tr('Reset view')]), h('button', { on: { click: () => this.setProjection('normal') } }, [this.tr('Normal')]), h('button', { on: { click: () => this.setProjection('little-planet') } }, [this.tr('Little planet')]), h('button', { on: { click: () => this.setProjection('reverse-little-planet') } }, [this.tr('Reverse little planet')]), h('label', { class: 'nc360-progress' }, [h('span', { class: 'nc360-time' }, [this.formatTime(this.currentTime)]), h('input', { attrs: { type: 'range', min: 0, max: this.durationSeconds || 0, step: 0.1, 'aria-label': this.tr('Seek') }, domProps: { value: this.currentTime }, on: { input: (e: Event) => this.seek(Number((e.target as HTMLInputElement).value)) } }), h('span', { class: 'nc360-time' }, [this.formatTime(this.durationSeconds)])]),
        h('label', { class: 'nc360-bitrate' }, [this.tr('Bitrate') + ' ', h('select', { domProps: { value: this.levelIndex }, on: { change: (e: Event) => this.changeLevel(Number((e.target as HTMLSelectElement).value)) } }, levels)]),
        h('span', { class: 'nc360-view-hint' }, [this.tr('Drag to look around · pinch/scroll to zoom · iPad touch drag')]),
      ]),
    ]
    if (this.busy) children.push(h('div', { class: 'nc360-loading' }, [this.message]))
    if (this.error) children.push(h('div', { class: 'nc360-error' }, [this.error]))
    return h('div', { class: 'nc360-viewer' }, children)
  },
  data() { return { busy: true, message: tr('Preparing 360 playback…'), error: '', hls: null as Hls | null, hlsSecondary: null as Hls | null, timer: 0 as number, sessionId: '', statusUrl: '', transcodeUrl: '', profileIds: [] as string[], defaultProfile: '', switchingProfile: false, mode: 'transcode', flipY: false, durationSeconds: 0, currentTime: 0, levels: [] as any[], levelIndex: -1, renderer: null as THREE.WebGLRenderer | null, scene: null as THREE.Scene | null, camera: null as THREE.PerspectiveCamera | null, sphere: null as THREE.Mesh | null, texture: null as THREE.VideoTexture | null, textureSecondary: null as THREE.VideoTexture | null, raf: 0, yaw: 0, pitch: 0, fov: 75, projectionMode: 'normal', dragging: false, lastX: 0, lastY: 0, hlsRecoveryAttempts: 0, started: false, playing: false, muted: true } },
  watch: { active(value: boolean) { if (value && !this.started) { this.initSphere(); this.start() } else if (!value) { (this.$refs.video as HTMLVideoElement)?.pause(); (this.$refs.videoSecondary as HTMLVideoElement)?.pause() } }, fileid(value: number | string, old: number | string) { if (String(value) === String(old)) return; this.resetPlayback(); if (this.active) { this.initSphere(); this.start() } } },
  mounted() { if (!this.active) return; document.documentElement.classList.add('nc360-active'); document.body.classList.add('nc360-active'); this.initSphere(); this.start() },
  beforeDestroy() { document.documentElement.classList.remove('nc360-active'); document.body.classList.remove('nc360-active'); if (this.timer) window.clearTimeout(this.timer); if (this.raf) cancelAnimationFrame(this.raf); this.hls?.destroy(); this.hlsSecondary?.destroy(); this.texture?.dispose(); this.textureSecondary?.dispose(); (this.$refs.video as HTMLVideoElement)?.pause(); (this.$refs.videoSecondary as HTMLVideoElement)?.pause(); this.sphere?.geometry.dispose(); (this.sphere?.material as THREE.Material | undefined)?.dispose(); this.renderer?.dispose() },
  methods: {
    tr,
    applyOsvCalibration(calibration: any) { const uniforms = (this.sphere!.material as THREE.ShaderMaterial).uniforms; const source = calibration?.source || [3840, 3840]; const lenses = calibration?.lenses || []; if (lenses.length < 2) throw new Error('OSV 标定数据不完整'); const setLens = (prefix: 'A' | 'B', lens: any) => { const r = (lens.rotation || [1,0,0,0,1,0,0,0,1]).flat().map(Number); const matrix = new THREE.Matrix3().set(r[0], r[1], r[2], r[3], r[4], r[5], r[6], r[7], r[8]); uniforms[`lensRot${prefix}`].value = matrix; uniforms[`lensIntr${prefix}`].value = new THREE.Vector4(Number(lens['1'] || 0), Number(lens['2'] || 0), Number(lens['3'] || 0), Number(lens['4'] || 0)); uniforms[`lensK${prefix}`].value = new THREE.Vector4(Number(lens['5'] || 0), Number(lens['6'] || 0), Number(lens['7'] || 0), Number(lens['8'] || 0)); uniforms[`lensK4${prefix}`].value = Number(lens['15'] || 0) }; setLens('A', lenses[0]); setLens('B', lenses[1]); uniforms.sourceWidth.value = Number(source[0] || 3840); uniforms.sourceHeight.value = Number(source[1] || 3840); uniforms.calibratedOsv.value = 1; uniforms.directOsv.value = 0 },
    togglePlay() { const v = this.$refs.video as HTMLVideoElement; if (v.paused) void v.play().catch((e: Error) => { this.error = e.message }); else v.pause() }, setProjection(mode: string) { this.projectionMode = mode; this.yaw = 0; this.pitch = 0; this.fov = mode === 'normal' ? 75 : 105; if (this.camera) { this.camera.fov = this.fov; this.camera.updateProjectionMatrix() } }, toggleMute() { this.muted = !this.muted; (this.$refs.video as HTMLVideoElement).muted = this.muted }, resetView() { this.yaw = 0; this.pitch = 0; this.fov = 75; this.projectionMode = 'normal'; if (this.camera) { this.camera.fov = 75; this.camera.updateProjectionMatrix() } }, resetPlayback() { if (this.timer) { window.clearTimeout(this.timer); this.timer = 0 } this.hls?.destroy(); this.hlsSecondary?.destroy(); this.hls = null; this.hlsSecondary = null; const primary = this.$refs.video as HTMLVideoElement | undefined; const secondary = this.$refs.videoSecondary as HTMLVideoElement | undefined; primary?.pause(); secondary?.pause(); if (primary) { primary.removeAttribute('src'); primary.load() } if (secondary) { secondary.removeAttribute('src'); secondary.load() } this.texture?.dispose(); this.textureSecondary?.dispose(); this.texture = null; this.textureSecondary = null; this.sessionId = ''; this.statusUrl = ''; this.transcodeUrl = ''; this.profileIds = []; this.defaultProfile = ''; this.switchingProfile = false; this.mode = 'transcode'; this.flipY = false; if (this.sphere) { const uniforms = (this.sphere.material as THREE.ShaderMaterial).uniforms; uniforms.flipY.value = 0; uniforms.lensFov.value = 188.5 * Math.PI / 360; } this.durationSeconds = 0; this.currentTime = 0; this.levels = []; this.levelIndex = -1; this.error = ''; this.message = '正在准备 360 播放…'; this.busy = true; this.started = false; this.playing = false },
    async api(path: string, init: RequestInit = {}) { const headers = new Headers(init.headers || {}); headers.set('OCS-APIRequest', 'true'); const token = (window as any).OC?.requestToken; if (token) headers.set('requesttoken', token); const response = await fetch(path, { ...init, headers, credentials: 'same-origin' }); const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.error || `${response.status} request failed`); return body },
    initSphere() {
      if (this.renderer) return
      const canvas = this.$refs.canvas as HTMLCanvasElement; this.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance' }); this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.0)); this.scene = new THREE.Scene(); this.camera = new THREE.PerspectiveCamera(this.fov, 1, 0.01, 100); this.camera.position.set(0, 0, 0.01); this.sphere = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), panoramaMaterial()); this.scene.add(this.sphere)
      const pointers = new Map<number, { x: number, y: number }>(); let pinchDistance = 0; const zoom = (delta: number) => { this.fov = Math.max(35, Math.min(120, this.fov + delta)); if (this.camera) { this.camera.fov = this.fov; this.camera.updateProjectionMatrix() } }; const onPointerDown = (e: PointerEvent) => { e.stopPropagation(); pointers.set(e.pointerId, { x: e.clientX, y: e.clientY }); if (pointers.size === 1) { this.dragging = true; this.lastX = e.clientX; this.lastY = e.clientY } else if (pointers.size === 2) { const [a, b] = [...pointers.values()]; pinchDistance = Math.hypot(a.x - b.x, a.y - b.y) } try { canvas.setPointerCapture(e.pointerId) } catch (_) {} }; const onPointerMove = (e: PointerEvent) => { e.stopPropagation(); if (!pointers.has(e.pointerId)) return; pointers.set(e.pointerId, { x: e.clientX, y: e.clientY }); if (pointers.size === 2) { const [a, b] = [...pointers.values()]; const next = Math.hypot(a.x - b.x, a.y - b.y); if (pinchDistance) zoom((pinchDistance - next) * 0.04); pinchDistance = next; return } if (!this.dragging) return; this.yaw -= (e.clientX - this.lastX) * 0.005; this.pitch -= (e.clientY - this.lastY) * 0.005; this.pitch = Math.max(-1.52, Math.min(1.52, this.pitch)); this.lastX = e.clientX; this.lastY = e.clientY }; const onPointerUp = (e: PointerEvent) => { e.stopPropagation(); pointers.delete(e.pointerId); this.dragging = pointers.size === 1; if (pointers.size === 1) { const a = [...pointers.values()][0]; this.lastX = a.x; this.lastY = a.y } pinchDistance = 0; try { canvas.releasePointerCapture(e.pointerId) } catch (_) {} }
      canvas.style.touchAction = 'none'; canvas.addEventListener('pointerdown', onPointerDown); canvas.addEventListener('pointermove', onPointerMove); canvas.addEventListener('pointerup', onPointerUp); canvas.addEventListener('pointercancel', onPointerUp); canvas.addEventListener('wheel', (e) => { e.preventDefault(); zoom(e.deltaY * 0.05) }, { passive: false })
      const resize = () => { if (!this.renderer || !this.camera) return; const box = canvas.parentElement?.getBoundingClientRect(); const w = Math.max(1, box?.width || 640); const h = Math.max(1, (box?.height || 420) - 64); this.renderer.setSize(w, h, false); this.camera.aspect = w / h; this.camera.updateProjectionMatrix() }; window.addEventListener('resize', resize); resize(); const render = () => { if (this.camera && this.scene && this.renderer) { const u = (this.sphere!.material as THREE.ShaderMaterial).uniforms; u.aspect.value = this.camera.aspect; u.zoom.value = Math.tan(this.fov * Math.PI / 360); u.yaw.value = this.yaw; u.pitch.value = this.pitch; u.projection.value = this.projectionMode === 'normal' ? 0 : (this.projectionMode === 'little-planet' ? 1 : 2); this.renderer.render(this.scene, this.camera) }; this.raf = requestAnimationFrame(render) }; render()
    },
    async start() {
      this.started = true; this.$emit('update:can-swipe', false); this.$emit('update:loaded', true);
      try { const session = await this.api(`${apiBase}/sessions`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ fileId: Number(this.fileid), mode: 'direct' }) }); this.sessionId = session.sessionId; this.statusUrl = session.statusUrl; this.transcodeUrl = session.transcodeUrl; this.profileIds = (session.profiles || []).map((p: any) => String(p.id)); this.defaultProfile = String(session.defaultProfile || this.profileIds[Math.floor(this.profileIds.length / 2)] || 'v0'); this.levelIndex = Math.max(0, this.profileIds.indexOf(this.defaultProfile)); this.durationSeconds = Number(session.durationSeconds || 0); this.levels = (session.profiles || []).map((p: any) => ({ ...p, bitrate: p.bitrateMbps * 1000000 })); this.mode = session.mode === 'direct-osv' ? 'direct-osv' : (/\.insv$/i.test(String(session.name || this.basename)) ? 'direct-insv' : 'transcode'); this.flipY = this.mode === 'transcode' && /\.osv$/i.test(String(session.name || this.basename)); if (this.sphere) { const uniforms = (this.sphere.material as THREE.ShaderMaterial).uniforms; uniforms.flipY.value = this.flipY ? 1 : 0; uniforms.lensFov.value = this.mode === 'direct-osv' ? 195 * Math.PI / 360 : 188.5 * Math.PI / 360; } if (this.mode === 'direct-insv') { const urls = Array.isArray(session.sourceUrls) ? session.sourceUrls.slice() : []; if (urls.length === 0 && session.sourceUrl) urls.push(session.sourceUrl); if (urls.length === 1 && session.sourceUrl) urls.push(`${session.sourceUrl}?input=1`); return this.attachRaw(urls) } if (this.mode === 'direct-osv') { await this.api(session.transcodeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); const deadline = Date.now() + 15 * 60 * 1000; while (Date.now() < deadline) { const status = await this.api(session.statusUrl); if (status.calibration) this.applyOsvCalibration(status.calibration); if (status.variants?.v0?.playable && status.variants?.v1?.playable) return this.attachDualHls(session.directUrls || [], status.profiles || []); if (status.state === 'error') throw new Error(status.error || 'OSV 原片直通失败'); this.message = '正在准备 OSV 无损直通…'; await new Promise<void>(resolve => { this.timer = window.setTimeout(resolve, 900) }) } throw new Error('OSV 直通超时') } await this.api(session.transcodeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ profile: this.defaultProfile || this.profileIds[0] || 'v0' }) }); const deadline = Date.now() + 15 * 60 * 1000; while (Date.now() < deadline) { const status = await this.api(session.statusUrl); if (status.variants?.[this.defaultProfile]?.playable) return this.attach(`${apiBase}/sessions/${this.sessionId}/hls/${this.defaultProfile}/index.m3u8`); if (status.state === 'error') throw new Error(status.error || '转码失败'); this.message = `正在拼接与转码… ${Math.round(Number(status.progress || 0) * 100)}% · 总时长 ${this.formatTime(this.durationSeconds)}`; await new Promise<void>(resolve => { this.timer = window.setTimeout(resolve, 900) }) } throw new Error('转码超时') } catch (e: any) { this.busy = false; this.error = e?.message || String(e); this.$emit('error', e) }
    },
    attachDualHls(urls: string[], profiles: any[] = []) { const primary = this.$refs.video as HTMLVideoElement; const secondary = this.$refs.videoSecondary as HTMLVideoElement; if (!primary || !secondary || urls.length < 2) { this.error = 'OSV 双路原片不可用'; this.busy = false; return } this.busy = true; this.error = ''; this.levels = [{ id: 'direct', label: '原片无损双路', width: Number(profiles[0]?.width || 3840), height: Number(profiles[0]?.height || 3840), bitrate: 0 }, ...profiles.slice(2).map((p: any) => ({ ...p, bitrate: Number(p.bitrateMbps || 0) * 1000000 }))]; this.profileIds = this.levels.slice(1).map((p: any) => String(p.id)); this.levelIndex = 0; primary.muted = true; secondary.muted = true; this.texture = new THREE.VideoTexture(primary); this.textureSecondary = new THREE.VideoTexture(secondary); this.texture.colorSpace = THREE.SRGBColorSpace; this.textureSecondary.colorSpace = THREE.SRGBColorSpace; const uniforms = (this.sphere!.material as THREE.ShaderMaterial).uniforms; uniforms.mapA.value = this.texture; uniforms.mapB.value = this.textureSecondary; uniforms.dual.value = 1; if (!Hls.isSupported()) { this.error = '浏览器不支持 OSV 双路 HLS'; this.busy = false; return } let parsed = 0; const attach = (video: HTMLVideoElement, url: string, slot: 'hls' | 'hlsSecondary') => { const hls = new Hls({ enableWorker: true, lowLatencyMode: false, capLevelToPlayerSize: false, startPosition: 0, maxBufferLength: 30, maxMaxBufferLength: 60, xhrSetup: xhr => { xhr.withCredentials = true } }); (this as any)[slot] = hls; hls.on(Hls.Events.MEDIA_ATTACHED, () => hls.loadSource(url)); hls.on(Hls.Events.MANIFEST_PARSED, () => { parsed += 1; if (parsed === 2) { primary.currentTime = 0; secondary.currentTime = 0; this.busy = false; this.message = 'OSV 原片无损直通已就绪'; void Promise.all([primary.play(), secondary.play()]).catch(() => {}) } }); hls.on(Hls.Events.ERROR, (_event, data) => { if (data.fatal) this.error = `OSV HLS: ${data.details || data.type || 'unknown'}` }); hls.attachMedia(video) }; attach(primary, urls[0], 'hls'); attach(secondary, urls[1], 'hlsSecondary'); primary.addEventListener('play', () => { if (secondary.paused) void secondary.play().catch(() => {}) }); primary.addEventListener('pause', () => secondary.pause()); primary.addEventListener('seeking', () => { if (secondary.readyState >= 2) secondary.currentTime = primary.currentTime }); primary.addEventListener('timeupdate', () => { if (secondary.readyState >= 3 && !secondary.seeking) { const drift = secondary.currentTime - primary.currentTime; if (Math.abs(drift) > 0.08) secondary.currentTime = primary.currentTime } }); },
    attachRaw(urls: string[]) { const primary = this.$refs.video as HTMLVideoElement; const secondary = this.$refs.videoSecondary as HTMLVideoElement; if (!primary || !secondary || urls.length < 2) { this.error = 'INSV 双文件源不可用'; this.busy = false; return } this.busy = false; this.levels = [{ label: '原片', width: 2880, height: 2880, bitrate: 0 }]; this.levelIndex = 0; primary.src = urls[0]; secondary.src = urls[1]; primary.muted = true; secondary.muted = true; this.texture = new THREE.VideoTexture(primary); this.textureSecondary = new THREE.VideoTexture(secondary); this.texture.colorSpace = THREE.SRGBColorSpace; this.textureSecondary.colorSpace = THREE.SRGBColorSpace; const uniforms = (this.sphere!.material as THREE.ShaderMaterial).uniforms; uniforms.mapA.value = this.texture; uniforms.mapB.value = this.textureSecondary; uniforms.dual.value = 1; primary.addEventListener('loadedmetadata', () => { this.onLoadedMetadata(); void secondary.play().catch(() => {}) }, { once: true }); primary.addEventListener('play', () => { void secondary.play().catch(() => {}) }); primary.addEventListener('pause', () => secondary.pause()); primary.addEventListener('seeked', () => { if (Math.abs(secondary.currentTime - primary.currentTime) > 0.15) secondary.currentTime = primary.currentTime }); primary.load(); secondary.load(); void primary.play().catch(() => {}); void secondary.play().catch(() => {}); },
    async attach(url: string, resumeTime = 0) {
      const video = this.$refs.video as HTMLVideoElement
      this.busy = true
      this.error = ''
      this.hlsRecoveryAttempts = 0
      this.message = '正在加载 HLS manifest…'
      try {
        const probe = await fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        const manifest = await probe.text()
        if (!probe.ok || !manifest.startsWith('#EXTM3U')) throw new Error(`HLS manifest ${probe.status}`)
        this.texture = new THREE.VideoTexture(video)
        this.texture.colorSpace = THREE.SRGBColorSpace
        const uniforms = (this.sphere!.material as THREE.ShaderMaterial).uniforms
        uniforms.mapA.value = this.texture
        uniforms.dual.value = 0
        if (Hls.isSupported()) {
          const hls = new Hls({ enableWorker: true, lowLatencyMode: false, capLevelToPlayerSize: false, startLevel: 0, maxBufferLength: 60, maxMaxBufferLength: 120, backBufferLength: 60, startPosition: resumeTime, xhrSetup: xhr => { xhr.withCredentials = true } })
          this.hls = hls
          hls.on(Hls.Events.MEDIA_ATTACHED, () => hls.loadSource(url))
          hls.on(Hls.Events.MANIFEST_PARSED, (_event, data) => { this.switchingProfile = false; video.muted = true; void video.play().catch(() => {}); this.busy = false; this.message = 'HLS 已就绪，点击播放'; this.$emit('update:loaded', true) })
          // Profile IDs are application choices, independent of the single HLS level.
          hls.on(Hls.Events.ERROR, (_event, data) => {
            if (!data.fatal) return
            if (data.type === Hls.ErrorTypes.NETWORK_ERROR && this.hlsRecoveryAttempts < 2) { this.hlsRecoveryAttempts += 1; this.message = '正在重试 HLS manifest…'; hls.startLoad(); return }
            if (data.type === Hls.ErrorTypes.MEDIA_ERROR && this.hlsRecoveryAttempts < 2) { this.hlsRecoveryAttempts += 1; hls.recoverMediaError(); return }
            this.busy = false
            this.error = `HLS: ${data.details || data.type || 'unknown'}`
          })
          hls.attachMedia(video)
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = url
          video.addEventListener('loadedmetadata', () => { this.busy = false; this.message = 'HLS 已就绪，点击播放'; this.$emit('update:loaded', true) }, { once: true })
        } else throw new Error('浏览器不支持 HLS/MSE')
      } catch (e: any) {
        this.busy = false
        this.error = e?.message || String(e)
      }
    },
    onLoadedMetadata() { /* ffprobe duration is authoritative, including while HLS is growing. */ }, onTimeUpdate() { const primary = this.$refs.video as HTMLVideoElement; const secondary = this.$refs.videoSecondary as HTMLVideoElement; this.currentTime = primary.currentTime || 0; if (this.mode === 'direct-insv' && secondary?.readyState >= 3 && !secondary.seeking) { const drift = secondary.currentTime - primary.currentTime; if (Math.abs(drift) > 0.18) secondary.currentTime = primary.currentTime; else secondary.playbackRate = Math.abs(drift) < 0.025 ? 1 : (drift > 0 ? 0.97 : 1.03) } }, seek(value: number) { const video = this.$refs.video as HTMLVideoElement; if (Number.isFinite(value)) { video.currentTime = value; const secondary = this.$refs.videoSecondary as HTMLVideoElement; if (this.mode === 'direct-insv' && secondary?.readyState >= 2) secondary.currentTime = value } }, async changeLevel(index: number) { if (!this.hls) { this.levelIndex = index; return } const profile = this.profileIds[index] || `v${index}`; this.levelIndex = index; if (!this.transcodeUrl || this.mode !== 'transcode') { this.hls.currentLevel = index; return } this.busy = true; this.message = `正在准备 ${this.levels[index]?.width || '?'}×${this.levels[index]?.height || '?'} 源画质…`; try { await this.api(this.transcodeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ profile }) }); const deadline = Date.now() + 15 * 60 * 1000; while (Date.now() < deadline) { const status = await this.api(this.statusUrl); if (status.variants?.[profile]?.playable || status.state === 'ready') break; if (status.state === 'error') throw new Error(status.error || '转码失败'); await new Promise<void>(resolve => { this.timer = window.setTimeout(resolve, 900) }) } const resumeTime = (this.$refs.video as HTMLVideoElement).currentTime; this.hls.destroy(); this.hls = null; this.texture?.dispose(); await this.attach(`${apiBase}/sessions/${this.sessionId}/hls/${profile}/index.m3u8`, resumeTime); this.busy = false; this.message = 'HLS 已就绪，点击播放' } catch (e: any) { this.busy = false; this.error = e?.message || String(e) } }, formatTime(value: number) { if (!Number.isFinite(value) || value <= 0) return '00:00'; const s = Math.floor(value); return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}` },
  },
}

const originalChangeLevel = viewerComponent.methods.changeLevel
const originalApplyOsvCalibration = viewerComponent.methods.applyOsvCalibration
viewerComponent.methods.applyOsvCalibration = function (calibration: any, scale = 1) {
  if (!calibration) return
  this.osvCalibration = calibration
  if (scale === 1) return originalApplyOsvCalibration.call(this, calibration)
  const scaled = JSON.parse(JSON.stringify(calibration))
  scaled.source = [Math.round(Number(calibration.source[0]) * scale), Math.round(Number(calibration.source[1]) * scale)]
  scaled.lenses = scaled.lenses.map((lens: any) => { for (const key of ['1', '2', '3', '4']) lens[key] = Number(lens[key] || 0) * scale; return lens })
  originalApplyOsvCalibration.call(this, scaled)
}
const originalResetPlayback = viewerComponent.methods.resetPlayback
viewerComponent.methods.resetPlayback = function () {
  if (this.osvSyncTimer) { window.clearInterval(this.osvSyncTimer); this.osvSyncTimer = 0 }
  originalResetPlayback.call(this)
  if (this.sphere) (this.sphere.material as THREE.ShaderMaterial).uniforms.calibratedOsv.value = 0
  this.osvOriginalUrls = null
  this.osvProfiles = null
}
viewerComponent.methods.startOsvSync = function () {
  if (this.osvSyncTimer) window.clearInterval(this.osvSyncTimer)
  const primary = this.$refs.video as HTMLVideoElement
  const secondary = this.$refs.videoSecondary as HTMLVideoElement
  const sync = () => {
    if (!primary || !secondary || primary.readyState < 3 || secondary.readyState < 3 || primary.paused || secondary.seeking) return
    const drift = secondary.currentTime - primary.currentTime
    if (Math.abs(drift) > 0.16) {
      secondary.currentTime = primary.currentTime
      secondary.playbackRate = 1
    } else {
      secondary.playbackRate = Math.max(0.97, Math.min(1.03, 1 - drift * 0.35))
    }
  }
  primary.playbackRate = 1
  secondary.playbackRate = 1
  this.osvSyncTimer = window.setInterval(sync, 250)
  sync()
}
const originalAttachDualHls = viewerComponent.methods.attachDualHls
viewerComponent.methods.attachDualHls = function (urls: string[], profiles: any[] = []) {
  this.osvOriginalUrls = urls
  this.osvProfiles = profiles
  originalAttachDualHls.call(this, urls, profiles)
  const reduced = profiles.slice(2).filter((_profile: any, index: number) => index % 2 === 0)
  this.levels = [this.levels[0], ...reduced.map((profile: any) => ({ ...profile, bitrate: Number(profile.bitrateMbps || 0) * 1000000 }))]
  this.profileIds = reduced.map((profile: any) => String(profile.id))
  this.levelIndex = 0
  this.startOsvSync()
}

viewerComponent.methods.switchOsvStreams = function (urls: string[], resumeTime: number) {
  const primary = this.$refs.video as HTMLVideoElement
  const secondary = this.$refs.videoSecondary as HTMLVideoElement
  this.hls?.destroy(); this.hlsSecondary?.destroy()
  this.hls = null; this.hlsSecondary = null
  primary.pause(); secondary.pause()
  let parsed = 0
  const attach = (video: HTMLVideoElement, url: string, slot: 'hls' | 'hlsSecondary') => {
    const hls = new Hls({ enableWorker: true, lowLatencyMode: false, capLevelToPlayerSize: false, startPosition: resumeTime, maxBufferLength: 30, maxMaxBufferLength: 60, xhrSetup: xhr => { xhr.withCredentials = true } })
    this[slot] = hls
    hls.on(Hls.Events.MEDIA_ATTACHED, () => hls.loadSource(url))
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      parsed++
      if (parsed === 2) {
        if (resumeTime > 0) { primary.currentTime = resumeTime; secondary.currentTime = resumeTime }
        this.busy = false
        this.message = 'OSV 画质已切换'
        void Promise.all([primary.play(), secondary.play()]).catch(() => {})
      }
    })
    hls.on(Hls.Events.ERROR, (_event: any, data: any) => { if (data.fatal) { this.busy = false; this.error = `OSV HLS: ${data.details || data.type || 'unknown'}` } })
    hls.attachMedia(video)
  }
  attach(primary, urls[0], 'hls')
  attach(secondary, urls[1], 'hlsSecondary')
  const sync = () => { if (secondary.readyState >= 3 && !secondary.seeking && Math.abs(secondary.currentTime - primary.currentTime) > 0.08) secondary.currentTime = primary.currentTime }
  primary.addEventListener('timeupdate', sync)
  primary.addEventListener('play', () => { if (secondary.paused) void secondary.play().catch(() => {}) })
  primary.addEventListener('pause', () => secondary.pause())
  this.startOsvSync()
}

viewerComponent.methods.changeLevel = async function (index: number) {
  if (this.mode !== 'direct-osv') return originalChangeLevel.call(this, index)
  if (index === this.levelIndex || index < 0 || index >= this.levels.length) return
  const previousIndex = this.levelIndex
  this.busy = true
  this.message = `正在准备 ${this.levels[index]?.width || '?'}×${this.levels[index]?.height || '?'} 快速版本…`
  try {
    let urls = this.osvOriginalUrls
    if (index > 0) {
      const profile = this.profileIds[index - 1]
      const companion = `v${Number(profile.slice(1)) + 1}`
      await this.api(this.transcodeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ profile }) })
      const deadline = Date.now() + 15 * 60 * 1000
      let playable = false
      while (Date.now() < deadline) {
        const status = await this.api(this.statusUrl)
        if (status.variants?.[profile]?.playable && status.variants?.[companion]?.playable) { playable = true; break }
        if (status.state === 'error') throw new Error(status.error || '转码失败')
        await new Promise<void>(resolve => { this.timer = window.setTimeout(resolve, 900) })
      }
      if (!playable) throw new Error('快速版本准备超时')
      urls = [profile, companion].map(id => `${apiBase}/sessions/${this.sessionId}/hls/${id}/index.m3u8`)
    }
    if (this.osvCalibration) this.applyOsvCalibration(this.osvCalibration, Number(this.levels[index].width) / Number(this.levels[0].width))
    const resumeTime = (this.$refs.video as HTMLVideoElement).currentTime
    this.levelIndex = index
    this.switchOsvStreams(urls, resumeTime)
  } catch (e: any) { this.levelIndex = previousIndex; this.busy = false; this.error = e?.message || String(e) }
}

export default viewerComponent
