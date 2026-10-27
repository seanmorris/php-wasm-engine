#version 300 es
precision highp float;
layout(location=0) in vec2 pointA;
layout(location=1) in vec2 pointB;
layout(location=2) in vec2 pointC;
layout(location=3) in vec4 tint;
uniform vec2 viewport;
out vec4 color;
void main() {
    vec2 points[3]=vec2[3](pointA,pointB,pointC);
    vec2 point=points[gl_VertexID]/viewport;
    gl_Position=vec4(point.x*2.-1.,1.-point.y*2.,0,1);
    color=tint;
}
