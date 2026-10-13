#version 300 es
precision highp float;
layout(location=0) in vec4 rectangle;
layout(location=1) in vec4 tint;
uniform vec2 viewport;
out vec4 color;
void main() {
    vec2 corners[6]=vec2[6](
        vec2(0,0),vec2(1,0),vec2(0,1),vec2(0,1),vec2(1,0),vec2(1,1)
    );
    vec2 point=(rectangle.xy+corners[gl_VertexID]*rectangle.zw)/viewport;
    gl_Position=vec4(point.x*2.-1.,1.-point.y*2.,0,1);
    color=tint;
}
