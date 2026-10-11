#version 300 es
precision highp float;
uniform sampler2D atlas;
in vec2 uv;
in vec4 color;
flat in vec4 rect;
flat in int emptyTile;
out vec4 outputColor;
void main() {
    if(emptyTile==1) discard;
    vec2 pixel=rect.xy+clamp(uv*rect.zw,vec2(.001),rect.zw-vec2(.001));
    outputColor=texture(atlas,pixel/vec2(textureSize(atlas,0)))*color;
    if(outputColor.a==0.) discard;
}
