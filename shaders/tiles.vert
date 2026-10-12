#version 300 es
precision highp float;
precision highp int;
precision highp usampler2DArray;
layout(location=0) in vec4 cellCorner;
layout(location=1) in vec4 plane;
layout(location=2) in vec4 placement;
layout(location=3) in vec4 tint;
uniform usampler2DArray tileIds;
uniform sampler2D metadata;
uniform vec2 tileSize;
uniform vec2 mapOrigin;
uniform vec2 camera;
uniform vec2 viewport;
uniform vec2 parallaxOrigin;
uniform float zoom;
uniform float isoOrigin;
uniform int isometric;
out vec2 uv;
out vec4 color;
flat out vec4 rect;
flat out int emptyTile;
void main() {
    uvec4 bytes=texelFetch(tileIds,ivec3(ivec2(cellCorner.xy),int(plane.z)),0);
    uint index=bytes.r+(bytes.g<<8u)+(bytes.b<<16u);
    uint flags=bytes.a>>4u;
    emptyTile=index==0u?1:0;
    rect=texelFetch(metadata,ivec2(int(index),0),0);
    vec2 offset=texelFetch(metadata,ivec2(int(index),1),0).xy;
    vec2 cell=plane.xy+cellCorner.xy;
    vec2 anchor=isometric==1?vec2((cell.x-cell.y)*tileSize.x*.5+isoOrigin,(cell.x+cell.y)*tileSize.y*.5):cell*tileSize;
    anchor.y+=tileSize.y-rect.w;
    if(isometric==1) anchor.x-=rect.z*.5;
    vec2 position=anchor+offset+cellCorner.zw*rect.zw+placement.xy+mapOrigin;
    vec2 halfView=viewport/zoom*.5;
    position-=(camera+halfView-parallaxOrigin)*placement.zw+parallaxOrigin-halfView;
    position=position*zoom/viewport;
    gl_Position=emptyTile==1?vec4(2,2,0,1):vec4(position.x*2.-1.,1.-position.y*2.,0,1);
    // Invert Tiled's forward transform to sample the untransformed image.
    uv=cellCorner.zw;
    if((flags&4u)!=0u) uv.y=1.-uv.y;
    if((flags&8u)!=0u) uv.x=1.-uv.x;
    if((flags&2u)!=0u) uv=uv.yx;
    color=tint*vec4(1,1,1,plane.w);
}
