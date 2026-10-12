import test from 'node:test'
import assert from 'node:assert/strict'
import { installRawHandler, registerRawHandler, isRawFile, RAW_MIMES } from '../src/viewerRegistration.mjs'

const component = { name: 'Files360Viewer' }
function viewer() {
  const availableHandlers = [
    { id: 'videos', mimes: ['video', 'video/mp4', ...RAW_MIMES], mimesAliases: { 'application/x-insv': 'video/x-insv' } },
    { id: 'images', mimes: ['image/jpeg'] },
  ]
  return {
    availableHandlers,
    registerHandler(handler) { availableHandlers.push(handler) },
  }
}

test('raw MIME types take precedence while normal handlers remain available', () => {
  const v = viewer()
  const handler = { id: 'files-360viewer-raw', mimes: RAW_MIMES, component }
  assert.equal(registerRawHandler(v, handler), true)
  assert.deepEqual(v.availableHandlers[0].mimes, RAW_MIMES)
  assert.equal(v.availableHandlers.find(h => h.id === 'videos').mimes.includes('video/x-insv'), false)
  assert.equal(v.availableHandlers.find(h => h.id === 'images').mimes[0], 'image/jpeg')
})

test('pre-init registration is reconciled when Viewer is not ready', () => {
  const win = {}; let ready
  const handler = installRawHandler(win, { readyState: 'loading', addEventListener(name, callback, options) {
    assert.equal(name, 'DOMContentLoaded'); assert.equal(options.capture, true); ready = callback
  } }, component)
  win.OCA = { Viewer: viewer() }
  ready()
  assert.equal(win.OCA.Viewer.availableHandlers[0].id, handler.id)
})

test('repeated registration is idempotent', () => {
  const v = viewer(); const win = { OCA: { Viewer: v } }; const doc = { readyState: 'complete' }
  const handler = installRawHandler(win, doc, component)
  installRawHandler(win, doc, component); registerRawHandler(v, handler)
  assert.equal(v.availableHandlers.filter(h => h.id === handler.id).length, 1)
})

test('Files action is restricted to raw extensions', () => {
  for (const name of ['VID_00_010.insv', 'VID_10_010.INSV', 'original.osv']) assert.equal(isRawFile({ basename: name }), true)
  for (const name of ['unknown.bin', 'movie.mp4', 'foo.insv.exe', 'document.pdf']) assert.equal(isRawFile({ basename: name }), false)
})
