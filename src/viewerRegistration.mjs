export const RAW_HANDLER_ID = 'files-360viewer-raw'
export const RAW_MIMES = ['video/x-insv', 'video/x-osv']

export function isRawFile(node) {
  return /\.(insv|osv)$/i.test(String(node?.basename || node?.name || node?.filename || ''))
}

// Match files_pdfviewer and camera RAW: append one handler during LoadViewer.
// Do not reorder or mutate the host Viewer's handler array; that shared array
// is also observed by Nextcloud's Files integrations.
export function registerRawHandler(viewer, handler) {
  if (!viewer) return false
  const handlers = viewer.availableHandlers
  if (!Array.isArray(handlers)) return false
  if (!handlers.some(candidate => candidate.id === handler.id)) viewer.registerHandler(handler)
  return handlers.some(candidate => candidate.id === handler.id)
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
    if (!win._oca_viewer_handlers) win._oca_viewer_handlers = new Map()
    if (typeof win._oca_viewer_handlers.set === 'function') win._oca_viewer_handlers.set(handler.id, handler)
    else if (typeof win._oca_viewer_handlers.add === 'function') win._oca_viewer_handlers.add(handler)
    else if (!win._oca_viewer_handlers.some(candidate => candidate.id === handler.id)) win._oca_viewer_handlers.push(handler)
  }
  // Capture precedes Viewer's DOMContentLoaded handler-map construction even
  // when Viewer was loaded after this app. No timer, global open() patch or DOM UI.
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', install, { capture: true, once: true })
  return handler
}
