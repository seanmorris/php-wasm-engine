#version 300 es
precision highp float;
uniform sampler2D scene;
uniform vec2 viewport;
uniform vec2 direction;
uniform float sigma;
in vec2 screenUv;
out vec4 outputColor;

vec4 scenePixel(vec2 point) {
    return texture(scene,vec2(point.x,1.-point.y));
}

void main() {
    if(sigma<=0.) {
        outputColor=scenePixel(screenUv);
        return;
    }

    float radius=min(31.,sigma*3.);
    vec4 color=vec4(0);
    float total=0.;

    for(int i=-4;i<=4;i++) {
        float position=float(i)/4.;
        float distance=position*radius;
        float weight=exp(-.5*distance*distance/(sigma*sigma));
        color+=scenePixel(screenUv+direction*distance/viewport)*weight;
        total+=weight;
    }

    outputColor=color/total;
}
