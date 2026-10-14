<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import Hls from 'hls.js'
import { tr } from './i18n'

const w = window as any
const apiBase = w.OC?.generateUrl('/apps/files_360viewer/api/v1') || '/index.php/apps/files_360viewer/api/v1'
const user = w.OC?.currentUser || ''
const currentPath = ref('')
const entries = ref<any[]>([])
const loading = ref(false)
const selected = ref<any | null>(null)
const status = ref('选择一个 .osv 或 .insv 文件')
const error = ref('')
const video = ref<HTMLVideoElement | null>(null)
const bitrate = ref(0)
const levels = ref<any[]>([])
const playing = ref(false)
const durationSeconds = ref(0)
let hls: Hls | null = null
let timer = 0
const tx = tr

function webdavUrl(path = '') {
  const prefix = w.OC?.generateUrl(`/remote.php/dav/files/${encodeURIComponent(user)}`) || `/remote.php/dav/files/${encodeURIComponent(user)}`
  return `${prefix}/${path.split('/').filter(Boolean).map(encodeURIComponent).join('/')}`
}
async function browse(path = currentPath.value) {
  loading.value = true; error.value = ''
  try {
    const headers = new Headers({ Depth: '1', 'Content-Type': 'application/xml' }); if (w.OC?.requestToken) headers.set('requesttoken', w.OC.requestToken)
    const body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/><d:resourcetype/></d:prop></d:propfind>'
    const response = await fetch(webdavUrl(path), { method: 'PROPFIND', headers, body, credentials: 'same-origin' })
    if (!response.ok) throw new Error(`文件列表请求失败 (${response.status})`)
    const xml = new DOMParser().parseFromString(await response.text(), 'application/xml')
    const rows = [...xml.getElementsByTagNameNS('DAV:', 'response')].map(row => {
      const href = row.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent || ''
      const prop = row.getElementsByTagNameNS('DAV:', 'prop')[0]
      const fileId = prop?.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent || ''
      const resourceType = prop?.getElementsByTagNameNS('DAV:', 'resourcetype')[0]
      const isDir = Boolean(resourceType?.getElementsByTagNameNS('DAV:', 'collection').length)
      const decoded = decodeURIComponent(new URL(href, location.origin).pathname)
      const marker = `/remote.php/dav/files/${user}`
      const relative = decoded.includes(marker) ? decoded.slice(decoded.indexOf(marker) + marker.length) : decoded
      const cleanPath = relative.replace(/^\/+|\/+$/g, '')
      const name = cleanPath.split('/').pop() || ''
      return { name, path: cleanPath, fileId: fileId ? Number(fileId) : 0, isDir }
    }).filter(row => row.path !== currentPath.value && row.name && (row.isDir || /\.(insv|osv)$/i.test(row.name)))
    entries.value = rows.sort((a, b) => Number(b.isDir) - Number(a.isDir) || a.name.localeCompare(b.name)); currentPath.value = path
  } catch (e: any) { error.value = e?.message || String(e) } finally { loading.value = false }
}
function openOfficialPicker() {
  const picker = w.OC?.dialogs?.filepicker
  if (typeof picker !== 'function') {
    error.value = '当前 Nextcloud 没有提供官方文件选择器，请在 Files 中双击 .insv/.osv。'
    return
  }
  picker('选择 360 原片', (picked: string | string[]) => {
    const value = Array.isArray(picked) ? picked[0] : picked
    if (!value) return
    void selectPickedPath(value).catch((e: any) => { error.value = e?.message || String(e) })
  }, false, 'video/x-insv,video/x-osv', true, w.OC.dialogs.FILEPICKER_TYPE_CHOOSE)
}
async function selectPickedPath(pickedPath: string) {
  const path = decodeURIComponent(String(pickedPath)).replace(/^\/+|\/+$/g, '')
  const response = await fetch(webdavUrl(path), { method: 'PROPFIND', headers: { Depth: '0', 'Content-Type': 'application/xml' }, credentials: 'same-origin' })
  if (!response.ok) throw new Error(`文件读取失败 (${response.status})`)
  const xml = new DOMParser().parseFromString(await response.text(), 'application/xml')
  const row = xml.getElementsByTagNameNS('DAV:', 'response')[0]
  const prop = row?.getElementsByTagNameNS('DAV:', 'prop')[0]
  const fileId = Number(prop?.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent || 0)
  const name = path.split('/').pop() || path
  if (!fileId || !/\.(insv|osv)$/i.test(name)) throw new Error(tx('Select a .osv or .insv file'))
  const entry = { name, path, fileId, isDir: false }
  selected.value = entry
  await play(entry)
}
function choose(entry: any) { if (entry.isDir) return browse(entry.path); if (/\.(insv|osv)$/i.test(entry.name)) { selected.value = entry; void play(entry) } }
async function api(path: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers || {}); headers.set('OCS-APIRequest', 'true'); if (w.OC?.requestToken) headers.set('requesttoken', w.OC.requestToken)
  const response = await fetch(path, { ...init, headers, credentials: 'same-origin' }); const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.error || `${response.status} request failed`); return body
}
async function play(entry: any) {
  stop(); status.value = `${entry.name}: ${tx('Preparing 360 playback…')}`; error.value = ''
  try {
    const session = await api(`${apiBase}/sessions`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ fileId: entry.fileId, mode: 'transcode' }) }); durationSeconds.value = Number(session.durationSeconds || 0)
    await api(session.transcodeUrl, { method: 'POST' }); const deadline = Date.now() + 15 * 60 * 1000
    while (Date.now() < deadline) { const s = await api(session.statusUrl); if (s.state === 'ready') { status.value = `${entry.name} · ${tx('HLS ready, click play')}`; attach(`${apiBase}/sessions/${session.sessionId}/hls/master/index.m3u8`); return }; if (s.state === 'error') throw new Error(s.error || 'Transcode failed'); status.value = `${entry.name}: ${tx('Stitching and transcoding… {progress}% · duration {duration}').replace('{progress}', String(Math.round(Number(s.progress || 0) * 100))).replace('{duration}', formatDuration(durationSeconds.value))}`; await new Promise<void>(resolve => { timer = window.setTimeout(resolve, 900) }) }
    throw new Error('转码超时')
  } catch (e: any) { error.value = e?.message || String(e); status.value = '无法播放' }
}
async function attach(url: string) {
  if (!video.value) return
  const target = video.value
  try {
    const probe = await fetch(url, { credentials: 'same-origin', cache: 'no-store' })
    const manifest = await probe.text()
    if (!probe.ok || !manifest.startsWith('#EXTM3U')) throw new Error(`HLS manifest ${probe.status}`)
    if (Hls.isSupported()) {
      let recoveryAttempts = 0
      hls = new Hls({ enableWorker: false, lowLatencyMode: false, xhrSetup: xhr => { xhr.withCredentials = true } })
      hls.on(Hls.Events.MEDIA_ATTACHED, () => hls?.loadSource(url))
      hls.on(Hls.Events.MANIFEST_PARSED, (_event, data) => { levels.value = data.levels; bitrate.value = hls?.currentLevel ?? 0; status.value = `${selected.value?.name || ''} · ${tx('HLS ready, click play')}` })
      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return
        if (recoveryAttempts < 2 && data.type === Hls.ErrorTypes.NETWORK_ERROR) { recoveryAttempts += 1; hls?.startLoad(); return }
        if (recoveryAttempts < 2 && data.type === Hls.ErrorTypes.MEDIA_ERROR) { recoveryAttempts += 1; hls?.recoverMediaError(); return }
        error.value = `HLS: ${data.details || data.type || 'unknown'}`
      })
      hls.attachMedia(target)
    } else if (target.canPlayType('application/vnd.apple.mpegurl')) {
      target.src = url
      target.addEventListener('loadedmetadata', () => { status.value = `${selected.value?.name || ''} · ${tx('HLS ready, click play')}` }, { once: true })
    } else throw new Error('浏览器不支持 HLS/MSE')
  } catch (e: any) { error.value = e?.message || String(e) }
}
function chooseBitrate(index: number) { bitrate.value = index; if (hls) hls.currentLevel = index }
function toggle() { if (!video.value) return; if (video.value.paused) void video.value.play(); else video.value.pause() }
function stop() { if (timer) clearTimeout(timer); hls?.destroy(); hls = null; if (video.value) { video.value.pause(); video.value.removeAttribute('src'); video.value.load() }; playing.value = false; durationSeconds.value = 0 }
function formatDuration(value: number) { if (!Number.isFinite(value) || value <= 0) return '00:00'; const seconds = Math.floor(value); return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}` }
onMounted(() => browse(''))
onBeforeUnmount(stop)
</script>

<template>
  <main class="nc360-page">
    <header><h1>{{ tx('360 Videos') }}</h1><span class="sub">{{ tx('Select a file to play') }}</span></header>
    <section class="picker">
      <div class="picker-bar"><button @click="openOfficialPicker">{{ tx('From Files') }}</button><span>{{ currentPath || 'All files' }}</span><span><button v-if="currentPath" @click="browse(currentPath.split('/').slice(0,-1).join('/'))">{{ tx('Up') }}</button><button @click="browse(currentPath)">{{ tx('Refresh') }}</button></span></div>
      <p v-if="loading">{{ tx('Loading file list…') }}</p><p v-else-if="!entries.length">{{ tx('No playable items in this folder. Open NAS1 or NAS2 and select an .osv or .insv file.') }}</p>
      <button v-for="entry in entries" :key="entry.path" class="entry" :class="{folder:entry.isDir}" @click="choose(entry)"><span>{{ entry.isDir ? tx('Folder') : tx('360 video') }}</span><b>{{ entry.name }}</b><small v-if="!entry.isDir">{{ entry.fileId ? `ID ${entry.fileId}` : 'File ID unavailable' }}</small></button>
    </section>
    <section v-if="selected" class="player"><div class="player-title">{{ selected.name }}</div><video ref="video" class="video" controls playsinline preload="metadata" @play="playing=true" @pause="playing=false"></video><div class="controls"><button @click="toggle">{{ playing ? tx('Pause') : tx('Play') }}</button><label>{{ tx('Bitrate') }} <select :value="bitrate" @change="chooseBitrate(Number(($event.target as HTMLSelectElement).value))"><option v-for="(level,index) in levels" :key="index" :value="index">{{ Math.round(level.bitrate / 1000000) || (index ? 10 : 5) }} Mbps</option></select></label><span v-if="durationSeconds">{{ formatDuration(durationSeconds) }}</span><span>{{ status }}</span></div><p v-if="error" class="error">{{ error }}</p></section>
  </main>
</template>
