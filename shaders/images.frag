#version 300 es
precision highp float;
uniform sampler2D image;
uniform vec4 tint;
uniform vec4 colorKey;
uniform ivec2 repeatAxes;
in vec2 uv;
out vec4 outputColor;
void main() {
    ivec2 size=textureSize(image,0);
    // Stabilize exact texel boundaries after interpolation, including NPOT repeats.
    ivec2 texel=ivec2(floor(uv*vec2(size)+vec2(.0001)));
    texel.x=repeatAxes.x==1?texel.x%size.x:clamp(texel.x,0,size.x-1);
    texel.y=repeatAxes.y==1?texel.y%size.y:clamp(texel.y,0,size.y-1);
    vec4 pixel=texelFetch(image,texel,0);
    if(colorKey.a>0. && all(lessThan(abs(pixel.rgb-colorKey.rgb),vec3(.5/255.)))) discard;
    outputColor=pixel*tint;
    if(outputColor.a==0.) discard;
}
