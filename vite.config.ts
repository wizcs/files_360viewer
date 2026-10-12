import { defineConfig } from 'vite'; import vue from '@vitejs/plugin-vue';
export default defineConfig({ plugins:[vue()], build:{ outDir:'js', emptyOutDir:true, rollupOptions:{ output:{ entryFileNames:'main.js', assetFileNames:'[name][extname]' }}}})
