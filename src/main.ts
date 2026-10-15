import { createApp } from 'vue'
import PlayerShell from './PlayerShell.vue'
import ViewerComponent from './ViewerComponent'
import './style.css'
const root = document.getElementById('files-360viewer-root')
if (root) createApp(PlayerShell).mount(root)
import { installRawHandler, isRawFile } from './viewerRegistration.mjs'
const w = window as any
const handler = installRawHandler(w, document, ViewerComponent)

import { DefaultType, Permission, registerFileAction } from '@nextcloud/files'
const extension = isRawFile
if (!(w.__files360ActionRegistered)) {
  w.__files360ActionRegistered = true
  registerFileAction({
    id: 'files-360viewer-open',
    displayName: () => 'Open 360 video',
    iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20m-1 14H9V8h2zm4 0h-2V8h2z"/></svg>',
    default: DefaultType.DEFAULT,
    order: -100,
    enabled: ({ nodes }: any) => nodes.length === 1 && extension(nodes[0]) && Boolean(nodes[0].permissions & Permission.READ),
    exec: async ({ nodes }: any) => {
      const node = nodes[0]
      if (!w.OCA?.Viewer?.openWith) return false
      // Use the Files node path so Viewer resolves the full fileInfo from DAV.
      w.OCA.Viewer.openWith(handler.id, { path: node.path, enableSidebar: true })
      return null
    },
  })
}
