// One RGBA32UI texel holds pigment RGB10, octahedral normal XY8 and log depth16,
// geometric slope and finish (three bytes), and the capture frame that owns it.
uniform int uSurfaceFrame;
vec3 encodePigment(vec3 pigment) { return sqrt(max(pigment,0.)/(1.+max(pigment,0.))); }
vec3 decodePigment(vec3 encoded) { vec3 p=encoded*encoded; return p/max(vec3(.0001),1.-p); }
vec2 packSurfaceNormal(vec3 n) {
	n/=abs(n.x)+abs(n.y)+abs(n.z);
	return .5+.5*(n.y>=0. ? n.xz : (1.-abs(n.zx))*mix(vec2(-1.),vec2(1.),greaterThanEqual(n.xz,vec2(0.))));
}
vec3 unpackSurfaceNormal(vec2 encoded) {
	vec2 p=2.*encoded-1.;
	vec3 n=vec3(p.x,1.-abs(p.x)-abs(p.y),p.y);
	n.xz-=clamp(-n.y,0.,1.)*mix(vec2(-1.),vec2(1.),greaterThanEqual(n.xz,vec2(0.)));
	return normalize(n);
}
#ifdef WRITE_SURFACE_SAMPLES
// The source grid covers the same viewport. Evaluate the procedural bands at the display's pixel footprint.
uniform vec2 uSourceScale;
#define dFdx(v) (dFdx(v)*uSourceScale.x)
#define dFdy(v) (dFdy(v)*uSourceScale.y)
#define fwidth(v) (abs(dFdx(v))+abs(dFdy(v)))
#endif
#ifdef READ_SURFACE_SAMPLES
uniform highp usampler2D uSurfaceData;
#ifdef NEAR_SURFACE
uniform float uSurfaceRange;
#endif
uniform vec2 uSurfacePixel;
vec2 nativeDepthSlope;
vec3 sampleColor(uint word) {
	return vec3(uvec3(word,word>>10,word>>20)&1023u)*(1./1023.);
}
vec2 samplePair(uint word) {
	return vec2(uvec2(word,word>>8)&255u)*(1./255.);
}

bool sampleSurface(vec3 normal,inout vec3 pigment,inout vec3 lighting,inout vec2 finish) {
	vec2 size=vec2(textureSize(uSurfaceData,0));
	vec2 pixel=gl_FragCoord.xy*uSurfacePixel*size-.5;
	vec2 f=fract(pixel), invScale=1./(uSurfacePixel*size);
	ivec2 cell=ivec2(floor(pixel)), last=ivec2(size)-1;
	// Predict the depth on this face at each sample center. A neighboring cliff can have a different depth or grade.
	float w=gl_FragCoord.w, center=log2(1.+1./w);
	vec2 depthSlope=(-1.44269504/(w*(1.+w)))*nativeDepthSlope*invScale;
	vec3 total=vec3(0.); vec2 tilt=vec2(0.), coating=vec2(0.);
	float coverage=0.;
	for(int y=0;y<2;y++) for(int x=0;x<2;x++) {
		uvec4 tap=texelFetch(uSurfaceData,clamp(cell+ivec2(x,y),ivec2(0),last),0);
		float expected=center+dot(vec2(x,y)-f,depthSlope);
		float logDepth=float(tap.g>>16)*(17./65535.);
		float grade=float(tap.b&255u)*(1./255.);
		if(tap.a!=uint(uSurfaceFrame) || abs(logDepth-expected)>.0025 || abs(grade-normal.y)>.035) continue;
		float weight=(x==0?1.-f.x:f.x)*(y==0?1.-f.y:f.y);
		total+=weight*sampleColor(tap.r);
		tilt+=weight*samplePair(tap.g);
		coating+=weight*samplePair(tap.b>>8);
		coverage+=weight;
	}
	if(coverage<.05) {
		float best=1.e10;
		uvec4 nearest=uvec4(0u);
		for(int y=-1;y<=2;y++) for(int x=-1;x<=2;x++) {
			uvec4 tap=texelFetch(uSurfaceData,clamp(cell+ivec2(x,y),ivec2(0),last),0);
			if(tap.a!=uint(uSurfaceFrame)) continue;
			vec2 offset=vec2(x,y)-f;
			float expected=center+dot(offset,depthSlope);
			float logDepth=float(tap.g>>16)*(17./65535.);
			float grade=float(tap.b&255u)*(1./255.);
			float cost=abs(logDepth-expected)+.08*abs(grade-normal.y)+.0001*dot(offset,offset);
			if(cost<best) {best=cost; nearest=tap;}
		}
		if(best==1.e10) return false;
		total=sampleColor(nearest.r);
		tilt=samplePair(nearest.g);
		coating=samplePair(nearest.b>>8);
		coverage=1.;
	}
	finish=coating/coverage; pigment=decodePigment(total/coverage); lighting=unpackSurfaceNormal(tilt/coverage);
	return true;
}
#endif
