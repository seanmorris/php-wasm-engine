#version 300 es
precision highp float;
uniform sampler2D scene;
uniform vec2 viewport;
uniform vec4 effectRect;
uniform float zoom;
uniform float strength;
uniform int twistEffect;
in vec2 screenUv;
out vec4 outputColor;

vec4 scenePixel(vec2 point) {
    return texture(scene,vec2(point.x,1.-point.y));
}

void main() {
    if(twistEffect==0) {
        outputColor=scenePixel(screenUv);
        return;
    }

    vec2 centered=(screenUv*viewport-effectRect.xy-effectRect.zw*.5)/zoom;
    float falloff=pow(max(0.,1.-length(centered)/32.),3.);
    vec2 offset=vec2(centered.y,-centered.x)*(4./255.)*falloff*strength*zoom/viewport;
    outputColor=scenePixel(screenUv+offset);
}
