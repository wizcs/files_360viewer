import ViewerComponent from './ViewerComponent'
import { createApp } from 'vue'
import { installRawHandler } from './viewerRegistration.mjs'

const w = window as any
w.Files360MountViewer = Object.assign((mount: Element, props: Record<string, unknown>) => createApp(ViewerComponent, props).mount(mount), { component: ViewerComponent })
installRawHandler(w, document, ViewerComponent)
