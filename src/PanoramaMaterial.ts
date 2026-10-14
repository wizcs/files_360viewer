import * as THREE from 'three'

// Fullscreen ray projection: perspective and stereographic views share the
// same source sampler. Changing view never decodes or transcodes the source.
export function panoramaMaterial() {
  return new THREE.ShaderMaterial({
    uniforms: {
      mapA: { value: null }, mapB: { value: null }, dual: { value: 0 },
      aspect: { value: 1 }, zoom: { value: 1 }, yaw: { value: 0 },
      pitch: { value: 0 }, projection: { value: 0 }, flipY: { value: 0 }, directOsv: { value: 0 }, calibratedOsv: { value: 0 }, sourceWidth: { value: 3840 }, sourceHeight: { value: 3840 }, lensRotA: { value: new THREE.Matrix3() }, lensRotB: { value: new THREE.Matrix3() }, lensIntrA: { value: new THREE.Vector4() }, lensIntrB: { value: new THREE.Vector4() }, lensKA: { value: new THREE.Vector4() }, lensKB: { value: new THREE.Vector4() }, lensK4A: { value: 0 }, lensK4B: { value: 0 }, lensFov: { value: 188.5 * Math.PI / 360 },
    },
    vertexShader: `varying vec2 screen;
      void main() { screen=position.xy; gl_Position=vec4(position.xy,0.0,1.0); }`,
    fragmentShader: `
      uniform sampler2D mapA; uniform sampler2D mapB;
      uniform float dual, aspect, zoom, yaw, pitch, projection, flipY, directOsv, calibratedOsv, sourceWidth, sourceHeight, lensK4A, lensK4B, lensFov;
      uniform mat3 lensRotA, lensRotB; uniform vec4 lensIntrA, lensIntrB, lensKA, lensKB;
      varying vec2 screen;
      vec2 fisheyeUv(vec3 d, float back, out float angle) {
        angle=acos(clamp(mix(-d.z,d.z,back),-1.0,1.0));
        float sine=sin(angle);
        vec2 radial=sine>0.00001 ? vec2(mix(d.x,-d.x,back),d.y)/sine : vec2(0.0);
        vec2 fish=vec2(0.5)+radial*(angle/lensFov*0.5);
        vec2 base=vec2(fish.x,1.0-fish.y);
        // _00 and _10 have opposite 90-degree sensor rotations. VideoTexture
        // uses a bottom-left origin, so these preserve sky above ground.
        if(lensFov>1.7) return vec2(base.x,1.0-base.y);
        return back<0.5 ? vec2(base.y,1.0-base.x) : vec2(1.0-base.y,base.x);
      }
      vec2 calibratedUv(vec3 d, mat3 rot, vec4 intr, vec4 k, float k4, out float angle, out float valid) {
        vec3 q=rot*vec3(d.x,-d.z,d.y); float sine=max(length(q.xy),0.00001); angle=atan(sine,q.z); float t=angle*angle;
        float radius=angle*(1.0+t*(k.x+t*(k.y+t*(k.z+t*(k.w+t*k4)))));
        vec2 pixel=intr.zw+intr.xy*radius*q.xy/sine; valid=step(angle,1.703)+0.0;
        valid*=step(0.0,pixel.x)*step(pixel.x,sourceWidth-1.0)*step(0.0,pixel.y)*step(pixel.y,sourceHeight-1.0);
        return vec2(pixel.x/sourceWidth,1.0-pixel.y/sourceHeight);
      }
      void main() {
        vec2 p=screen*vec2(aspect,1.0)*zoom;
        vec3 d;
        if(projection<0.5) d=normalize(vec3(p,-1.0));
        else {
          float r2=dot(p,p);
          // Stereographic inverse: center looks at the ground (planet) or
          // sky (inverse planet); the horizon becomes a circle at radius 1.
          d=vec3(2.0*p.x,(r2-1.0)*(projection<1.5?1.0:-1.0),-2.0*p.y)/(1.0+r2);
        }
        float cp=cos(pitch),sp=sin(pitch),cy=cos(yaw),sy=sin(yaw);
        d=vec3(d.x,cp*d.y-sp*d.z,sp*d.y+cp*d.z);
        d=vec3(cy*d.x+sy*d.z,d.y,-sy*d.x+cy*d.z);
        if(dual<0.5) {
          // The calibrated OSV HLS raster has the opposite texture Y origin
          // from the native INSV path. Flip only that path so sky stays up.
          float v=0.5+(flipY>0.5?1.0:-1.0)*asin(clamp(d.y,-1.0,1.0))/3.14159265359;
          vec2 uv=vec2(0.5+atan(d.x,-d.z)/6.28318530718,v);
          gl_FragColor=texture2D(mapA,uv);
        } else {
          if(calibratedOsv>0.5) {
            float aa,ab,va,vb; vec2 ca=calibratedUv(d,lensRotA,lensIntrA,lensKA,lensK4A,aa,va), cb=calibratedUv(d,lensRotB,lensIntrB,lensKB,lensK4B,ab,vb);
            float w=smoothstep(-0.0261799,0.0261799,aa-ab); if(va<0.5) w=1.0; if(vb<0.5) w=0.0;
            gl_FragColor=mix(texture2D(mapA,ca),texture2D(mapB,cb),w);
          } else {
          float a,b; vec2 ua=fisheyeUv(d,0.0,a),ub=fisheyeUv(d,1.0,b);
          float overlap=max(0.001,lensFov*2.0-3.14159265359);
          float weight=smoothstep(-overlap,overlap,a-b);
          if(a>lensFov) weight=1.0;
          if(b>lensFov) weight=0.0;
          gl_FragColor=mix(texture2D(mapA,ua),texture2D(mapB,ub),weight);
          }
        }
        #include <tonemapping_fragment>
        #include <colorspace_fragment>
      }`,
    depthTest: false, depthWrite: false,
  })
}
