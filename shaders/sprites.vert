#version 300 es
precision highp float;
layout(location=0) in vec4 position;
layout(location=1) in vec4 rectangle;
layout(location=2) in vec4 transform;
layout(location=3) in vec4 tint;
uniform vec2 camera;
uniform vec2 viewport;
uniform float zoom;
out vec2 uv;
flat out vec4 color;
flat out vec4 rect;
flat out vec2 outlinePixels;
flat out int smoothSampling;

void main() {
    vec2 corners[6]=vec2[6](
        vec2(0, 0), vec2(1, 0), vec2(0, 1), vec2(0, 1), vec2(1, 0), vec2(1, 1)
    );
    vec2 corner=corners[gl_VertexID];
    int flags=int(transform.w);
    vec2 size=abs(position.zw);
    bool outlined=(flags&4)!=0 && all(greaterThan(size, vec2(0)));

    // Expand each local edge by one world unit while preserving the original image anchor.
    vec2 padding=outlined?1./size:vec2(0);
    corner+=(corner*2.-1.)*padding;
    vec2 local=(corner-transform.xy)*position.zw;
    float c=cos(transform.z), s=sin(transform.z);
    vec2 point=(position.xy+vec2(local.x*c-local.y*s, local.x*s+local.y*c)-camera)*zoom/viewport;
    gl_Position=vec4(point.x*2.-1., 1.-point.y*2., 0, 1);

    uv=corner; if((flags&1)!=0) uv.x=1.-uv.x; if((flags&2)!=0) uv.y=1.-uv.y;
    rect=rectangle; color=tint;
    outlinePixels=padding*rectangle.zw;
    smoothSampling=flags&8;
}
