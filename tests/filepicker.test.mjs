import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/PlayerShell.vue', import.meta.url), 'utf8')

test('uses Nextcloud official file picker and resolves the selected DAV file', () => {
  assert.match(source, /OC\?\.dialogs\?\.filepicker/)
  assert.match(source, /FILEPICKER_TYPE_CHOOSE/)
  assert.match(source, /PROPFIND/)
  assert.match(source, /owncloud\.org\/ns.*fileid/)
  assert.doesNotMatch(source, /type:\s*['"]number['"]/)
})
