#version 300 es
precision highp float;

uniform sampler2D portalView;
uniform vec2 viewport;
out vec4 color;

void main()
{
	if(!gl_FrontFacing) discard;
	color = texture(portalView, gl_FragCoord.xy / viewport);
}
