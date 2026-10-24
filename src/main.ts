import { createApp } from 'vue'
import PlayerShell from './PlayerShell.vue'
import './style.css'

const root = document.getElementById('files-360viewer-root')
if (root) createApp(PlayerShell).mount(root)
