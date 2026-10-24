import { installRawHandler, RAW_HANDLER_ID } from './viewerRegistration.mjs'

const w = window as any
const isPublicShare = /\/s\/[A-Za-z0-9]+(?:\/|$)/.test(location.pathname)
const isFilesPage = /\/(?:index\.php\/)?apps\/files(?:\/|$)/.test(location.pathname)
const rootPath = String(w.OC?.webroot || `/${location.pathname.split('/').filter(Boolean)[0] || ''}`).replace(/\/$/, '')

if (isPublicShare && w.OC) {
	if (typeof w.OC.generateUrl !== 'function') w.OC.generateUrl = (path: string) => `${rootPath}${String(path).startsWith('/') ? path : `/${path}`}`
	if (typeof w.OC.filePath !== 'function') w.OC.filePath = (app: string, path = '') => `${rootPath}/apps/${app}/${String(path).replace(/^\/+/, '')}`
}

function loadComponent(): Promise<any> {
	if (w.Files360MountViewer) return Promise.resolve(w.Files360MountViewer)
	return new Promise((resolve, reject) => {
		const script = document.createElement('script')
		script.src = `${rootPath}/apps/files_360viewer/js/viewer-component.js`
		script.onload = () => resolve(w.Files360MountViewer)
		script.onerror = () => reject(new Error('360 viewer component failed to load'))
		document.head.appendChild(script)
	})
}

if (isPublicShare || isFilesPage) {
	document.addEventListener('click', (event: Event) => {
		const target = event.target as Element | null
		const link = target?.closest('[data-cy-files-list-row-name-link]') as HTMLElement | null
		const row = link?.closest('[data-cy-files-list-row]') as HTMLElement | null
		const name = (link?.innerText || row?.getAttribute('data-cy-files-list-row-name') || '').replace(/\s+/g, '')
		if (!link || !row || !/\.(insv|osv)$/i.test(name) || !w.OCA?.Viewer?.openWith) return
		event.preventDefault()
		event.stopImmediatePropagation()
		const dir = new URLSearchParams(location.search).get('dir') || ''
		const path = `${dir.replace(/\/+$/, '')}/${name}`.replace(/^\/+/, '/')
		const node = { fileid: Number(row.getAttribute('data-cy-files-list-row-fileid') || 0), basename: name, filename: name, name, mime: /\.osv$/i.test(name) ? 'video/x-osv' : 'video/x-insv', permissions: isPublicShare ? 'R' : 1, path }
		void loadComponent().then((mountViewer) => {
			const registered = w.OCA?.Viewer?.availableHandlers?.find((candidate: any) => candidate.id === RAW_HANDLER_ID)
			const queued = w._oca_viewer_handlers instanceof Map
				? w._oca_viewer_handlers.get(RAW_HANDLER_ID)
				: null
			const handler = registered || queued || installRawHandler(w, document, mountViewer.component)
			// A legacy music app on this server does not provide the optional
			// Sidebar fullscreen hook expected by Viewer 35. Keep the host Viewer
			// path, but make that optional hook a harmless no-op for this handler.
			if (!isPublicShare && w.OCA?.Files?.Sidebar && typeof w.OCA.Files.Sidebar.setFullScreenMode !== 'function') w.OCA.Files.Sidebar.setFullScreenMode = () => {}
			w.OCA.Viewer.openWith(handler.id, { path, fileInfo: node, list: [node], enableSidebar: false, loadMore: () => [], onPrev: () => {}, onNext: () => {}, onClose: () => {} })
		}).catch((error) => console.error('[files_360viewer] viewer load failed', error))
	}, true)
}
