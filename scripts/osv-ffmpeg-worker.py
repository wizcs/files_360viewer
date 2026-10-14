#!/usr/bin/env python3
"""Build per-clip OSV lens maps and replace this worker with FFmpeg.

Format/geometry references: OpenOSV's dvtm_osmo360.proto and GEOMETRY.md
(Apache-2.0). Maps use the recorded KB5 model and body-to-lens quaternion.
Requires Python 3 and NumPy; source files are always opened read-only.
"""
import json
import math
import os
from pathlib import Path
import struct
import subprocess
import sys

import numpy as np


def protobuf(data):
    pos, fields = 0, {}

    def varint():
        nonlocal pos
        value = 0
        for shift in range(0, 70, 7):
            if pos >= len(data):
                raise ValueError('truncated metadata')
            byte = data[pos]
            pos += 1
            value |= (byte & 127) << shift
            if not byte & 128:
                return value
        raise ValueError('oversized metadata varint')

    while pos < len(data):
        tag = varint()
        number, wire = tag >> 3, tag & 7
        if number == 0:
            raise ValueError('invalid metadata tag')
        if wire == 0:
            value = varint()
        elif wire in (1, 2, 5):
            size = varint() if wire == 2 else (8 if wire == 1 else 4)
            if pos + size > len(data):
                raise ValueError('truncated metadata value')
            value = data[pos:pos + size]
            pos += size
            if wire in (1, 5):
                value = struct.unpack('<d' if wire == 1 else '<f', value)[0]
        else:
            raise ValueError('unsupported metadata wire type')
        fields[number] = value
    return fields


def read_calibration(ffmpeg, source):
    packet = subprocess.run([ffmpeg, '-v', 'error', '-nostdin', '-i', source,
                             '-map', '0:d:0', '-c', 'copy', '-frames:d', '1',
                             '-f', 'data', 'pipe:1'], check=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=30).stdout
    if not packet or len(packet) > 1024 * 1024:
        raise ValueError('invalid calibration packet size')
    stream = protobuf(protobuf(packet)[2])
    slots = protobuf(stream[6])
    mode = protobuf(stream.get(7, b'')).get(1, 0)
    choices = {0: [(1, 2), (11, 12)], 1: [(5, 6)], 2: [(9, 10), (7, 8)]}
    for pair in choices.get(mode, []):
        lenses = [protobuf(slots.get(i, b'')) for i in pair]
        if all(all(i in l for i in (1, 2, 3, 4, 10, 11, 28))
               and min(l[1], l[2], l[10], l[11]) > 0 for l in lenses):
            return lenses, pair, protobuf(stream[3])
    raise ValueError('no calibration for recorded lens mode')


def rotation(lens):
    q = protobuf(lens[28])
    w, x, y, z = [q.get(i, 0) for i in range(1, 5)]
    norm = math.sqrt(w*w + x*x + y*y + z*z)
    if not math.isfinite(norm) or abs(norm - 1) > .05:
        raise ValueError('invalid lens rotation')
    w, x, y, z = [v / norm for v in (w, x, y, z)]
    return np.array([[1-2*(y*y+z*z), 2*(x*y-z*w), 2*(x*z+y*w)],
                     [2*(x*y+z*w), 1-2*(x*x+z*z), 2*(y*z-x*w)],
                     [2*(x*z-y*w), 2*(y*z+x*w), 1-2*(x*x+y*y)]])


def lens_maps(lenses, stream, width, height, eye):
    source_w, source_h = int(stream[1]), int(stream[2])
    if (source_w, source_h) not in [(3840, 3840), (3000, 3000), (1920, 1920), (1920, 960)]:
        raise ValueError('unsupported OSV sensor crop')
    eye_h = int(round(eye * source_h / source_w))
    crop_w = 3776.0 if source_w == 3000 else 3840.0
    scale = eye / crop_w
    lon = ((np.arange(width) + .5) / width - .5) * 2*np.pi
    lat = (.5 - (np.arange(height) + .5) / height) * np.pi
    ray = np.stack(np.broadcast_arrays(np.sin(lon)[None, :]*np.cos(lat)[:, None],
                                      np.cos(lon)[None, :]*np.cos(lat)[:, None],
                                      np.sin(lat)[:, None]), axis=-1)
    maps, angles, validities = [], [], []
    for l in lenses:
        if not all(math.isfinite(l.get(i, 0)) for i in [1, 2, 3, 4, 5, 6, 7, 8, 15]):
            raise ValueError('non-finite calibration')
        d = ray @ rotation(l).T
        sine = np.maximum(np.hypot(d[..., 0], d[..., 1]), 1e-12)
        theta = np.arctan2(sine, d[..., 2])
        t = theta*theta
        k = [l.get(i, 0) for i in [5, 6, 7, 8, 15]]
        radius = theta*(1+t*(k[0]+t*(k[1]+t*(k[2]+t*(k[3]+t*k[4])))))
        x = eye/2 + (l[3] + l[1]*radius*d[..., 0]/sine - l[10]/2)*scale - .5
        y = eye_h/2 + (l[4] + l[2]*radius*d[..., 1]/sine - l[11]/2)*scale - .5
        valid = (theta < np.deg2rad(97.59)) & (x >= 0) & (x < eye-1) & (y >= 0) & (y < eye_h-1)
        maps.append((np.where(valid, x, 65535), np.where(valid, y, 65535)))
        angles.append(theta)
        validities.append(valid)
    # Use the better-centred lens. A narrow feather reduces near-field ghosting;
    # only valid rays contribute, including rectangular high-speed sensor crops.
    delta = np.rad2deg(angles[0] - angles[1])
    a = np.clip((delta + 1.5)/3, 0, 1)
    a = np.where(~validities[0], 1, np.where(~validities[1], 0, a))
    uncovered = ~validities[0] & ~validities[1]
    return maps, (a*255).round().astype('uint8'), eye_h, float(uncovered.mean())


def pgm(path, array, depth):
    maximum = 65535 if depth == 16 else 255
    pixels = np.clip(np.rint(array), 0, maximum).astype('>u2' if depth == 16 else 'uint8')
    with open(path, 'wb') as f:
        f.write(f'P5\n{pixels.shape[1]} {pixels.shape[0]}\n{maximum}\n'.encode())
        f.write(pixels.tobytes())


def prepare(recipe):
    lenses, slots, stream = read_calibration(recipe['command'][0], recipe['source'])
    source_w, source_h = int(stream[1]), int(stream[2])
    calibration_only = bool(recipe.get('calibration_only'))
    if calibration_only:
        eye_h = int(round(float(recipe.get('eye', source_w)) * source_h / source_w))
        uncovered = 0.0
        maps, alpha = [], None
    else:
        maps, alpha, eye_h, uncovered = lens_maps(lenses, stream, recipe['width'], recipe['height'], recipe['eye'])
    out = Path(recipe['directory'])
    if not calibration_only:
        for i, (x, y) in enumerate(maps):
            pgm(out / f'osv-{i}-x.pgm', x, 16)
            pgm(out / f'osv-{i}-y.pgm', y, 16)
        pgm(out / 'osv-blend.pgm', alpha, 8)
    (out / 'calibration.json').write_text(json.dumps({
        'model': 'Kannala-Brandt-5', 'slots': slots, 'source': [source_w, source_h],
        'eye': [recipe['eye'], eye_h], 'uncoveredFraction': uncovered,
        'lenses': [{**{str(k): v for k, v in l.items() if isinstance(v, (int, float))},
                    'rotation': rotation(l).tolist()}
                   for l in lenses]}, indent=2))
    recipe['command'] = [s.replace('OSV_EYE_HEIGHT', str(eye_h)) for s in recipe['command']]
    return recipe['command']


if __name__ == '__main__':
    try:
        recipe = json.loads(Path(sys.argv[1]).read_text())
        recipe['calibration_only'] = len(sys.argv) >= 3 and sys.argv[2] == '--calibration-only'
        command = prepare(recipe)
        if len(sys.argv) < 3 or sys.argv[2] != '--calibration-only':
            os.execv(command[0], command)
    except Exception as error:
        print('Conversion failed: OSV calibration: ' + str(error), file=sys.stderr, flush=True)
        sys.exit(1)
