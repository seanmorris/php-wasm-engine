#version 300 es
precision highp float;
uniform vec2 uFall;
uniform float uDay;
in vec2 vCorner;
in float vAlpha;
in vec3 vFlake;
out vec4 color;
void main()
{
	if(uFall.y>.5) {
		// A flake has a solid middle and a glow around it that is stronger and wider the nearer the flake is.
		float d=length(vCorner)*vFlake.x; // distance from the flake's middle in flake radii
		float core=1.-smoothstep(.5,1.,d);
		float glow=vFlake.y*exp(-.9*d*d)*(1.-smoothstep(.75*vFlake.x,vFlake.x,d));
		color=vec4(mix(vec3(1.),vec3(.84,.9,.97),vFlake.z)*mix(.48,1.,uDay),vAlpha*(core+(1.-core)*glow));
		return;
	}
	color=vec4(vec3(.46,.57,.65)*mix(.48,1.,uDay),vAlpha*(1.-smoothstep(.18,1.,abs(vCorner.x)))*.52);
}
