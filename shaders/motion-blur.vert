#version 300 es
precision highp float;
out vec2 screenUv;
const vec2 corners[6]=vec2[6](vec2(0,0),vec2(1,0),vec2(0,1),vec2(0,1),vec2(1,0),vec2(1,1));
void main() {
    screenUv=corners[gl_VertexID];
    gl_Position=vec4(screenUv.x*2.-1.,1.-screenUv.y*2.,0,1);
}
