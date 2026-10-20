#version 300 es
precision highp float;
uniform sampler2D atlas;
in vec2 uv;
flat in vec4 color;
flat in vec4 rect;
flat in vec2 outlinePixels;
flat in int smoothSampling;
out vec4 outputColor;

// Interpolation can put an exact texel edge a few ulps below its integer coordinate.
vec2 snapTexel(vec2 point) {
    vec2 edge=round(point);
    return mix(point, edge, lessThan(abs(point-edge), vec2(.00001)));
}

// Filter in premultiplied alpha so transparent texels cannot darken or color the edge.
vec4 filterTexel(ivec2 point) {
    point=clamp(point, ivec2(0), ivec2(rect.zw)-1);
    vec4 pixel=texelFetch(atlas, ivec2(rect.xy)+point, 0);
    return vec4(pixel.rgb*pixel.a, pixel.a);
}

// Sampling outside the current frame or crop must never read an adjacent atlas tile.
vec4 spritePixel(vec2 point) {
    if(any(lessThan(point, vec2(0))) || any(greaterThanEqual(point, rect.zw)))
        return vec4(0);

    if(smoothSampling==0)
        return texelFetch(atlas, ivec2(rect.xy)+ivec2(floor(point)), 0);

    vec2 centered=point-.5;
    ivec2 first=ivec2(floor(centered));
    vec2 weight=fract(centered);
    vec4 pixel=mix(
        mix(filterTexel(first), filterTexel(first+ivec2(1, 0)), weight.x),
        mix(filterTexel(first+ivec2(0, 1)), filterTexel(first+ivec2(1, 1)), weight.x),
        weight.y
    );
    return pixel.a>0. ? vec4(pixel.rgb/pixel.a, pixel.a) : vec4(0);
}

float outlineAlpha(vec2 point) {
    ivec2 first=ivec2(clamp(floor(snapTexel(point-outlinePixels)), vec2(0), rect.zw));
    ivec2 last=ivec2(clamp(floor(snapTexel(point+outlinePixels)), vec2(-1), rect.zw-1.));
    float alpha=0.;

    // Check every source texel intersecting a one-world-pixel square. Downscaled features
    // need intermediate texels too; eight fixed offset samples can leave holes in the border.
    for(int y=first.y; y<=last.y; y++) {
        for(int x=first.x; x<=last.x; x++) {
            alpha=max(alpha, texelFetch(atlas, ivec2(rect.xy)+ivec2(x, y), 0).a);
            if(alpha==1.) return alpha;
        }
    }
    return alpha;
}

void main() {
    if(color.a<=0.) discard;

    vec2 point=snapTexel(uv*rect.zw);
    vec4 pixel=spritePixel(point);

    // Artwork and ink are separate instances. Drawing every entity's artwork first lets its
    // shared, farther outline depth fail anywhere any sibling has already contributed artwork.
    if(any(greaterThan(outlinePixels, vec2(0)))) {
        if(pixel.a>0.) discard;
        outputColor=vec4(0, 0, 0, outlineAlpha(point)*color.a);
    } else {
        outputColor=pixel*color;
    }

    if(outputColor.a==0.) discard;
}
