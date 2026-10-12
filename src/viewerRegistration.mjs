export const RAW_HANDLER_ID = 'files-360viewer-raw'
export const RAW_MIMES = ['video/x-insv', 'video/x-osv']
const RAW_ALIASES = ['application/x-insv', 'application/x-osv']

export function isRawFile(node) {
  return /\.(insv|osv)$/i.test(String(node?.basename || node?.name || node?.filename || ''))
}

// Nextcloud Viewer registers exact MIME handlers in array order (first wins),
// then aliases; keep generic video and unknown-file handlers intact.
// Keep generic video and unknown-file handlers intact. Only reserve our formats.
export function registerRawHandler(viewer, handler) {
  if (!viewer) return false
  const handlers = viewer.availableHandlers
  if (!Array.isArray(handlers)) return false
  if (!handlers.some(candidate => candidate.id === handler.id)) viewer.registerHandler(handler)
  const index = handlers.findIndex(candidate => candidate.id === handler.id)
  if (index < 0) return false
  const [registered] = handlers.splice(index, 1)
  registered.mimes = [...RAW_MIMES]
  for (const candidate of handlers) {
    if (Array.isArray(candidate.mimes)) {
      candidate.mimes = candidate.mimes.filter(mime => !RAW_MIMES.includes(mime) && !RAW_ALIASES.includes(mime))
    }
    if (candidate.mimesAliases) {
      candidate.mimesAliases = Object.fromEntries(Object.entries(candidate.mimesAliases)
        .filter(([mime]) => !RAW_MIMES.includes(mime) && !RAW_ALIASES.includes(mime)))
    }
  }
  handlers.unshift(registered)
  return true
}

export function installRawHandler(win, doc, component) {
  const handler = {
    id: RAW_HANDLER_ID,
    group: 'files-360viewer',
    mimes: [...RAW_MIMES],
    mimesAliases: { 'application/x-insv': 'video/x-insv', 'application/x-osv': 'video/x-osv' },
    component,
  }
  const install = () => registerRawHandler(win.OCA?.Viewer, handler)
  if (!install()) {
    win._oca_viewer_handlers ||= []
    if (!win._oca_viewer_handlers.some(candidate => candidate.id === handler.id)) win._oca_viewer_handlers.push(handler)
  }
  // Capture precedes Viewer's DOMContentLoaded handler-map construction even
  // when Viewer was loaded after this app. No timer, global open() patch or DOM UI.
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', install, { capture: true, once: true })
  return handler
}
