// Standalone uniform-based reference used to compare the layered renderer with the earlier material model.
uniform float uTerrainStyle;
uniform vec4 uBedContacts[2];
uniform vec3 uBedDatum;
uniform vec3 uMountainColors[6];
uniform vec3 uGranitePigments[2];
// TerrainAppearance. Colors are linear. The canyon palette runs rust, shale, ledge, sandstone, red beds, cap, dust,
// then the color of beds too fine to resolve.
uniform vec3 uGrassColor, uSoilColor, uRockColor, uRockBandColor, uSandColor, uSnowColor, uRoadColor;
uniform vec3 uCanyonColors[8];
uniform vec3 uBanding; // Strata phase per meter, band contrast and band sharpness.
float contact(float height, float base, float width, float footprint)
{
	float edge = max(width, footprint * 0.65);
	return smoothstep(base - edge, base + edge, height);
}

float fracture(vec2 face, float footprint)
{
	float column = floor(face.x / 30.0);
	float center = mix(0.19, 0.81, hashCell(vec2(column, 79.2)));
	float breaks = smoothNoise(vec2(column, face.y / 35.0));
	float offset = abs(fract(face.x / 30.0) - center - 0.14*(breaks - 0.5)) * 30.0;
	float edge = max(0.15, min(footprint, 3.0));
	return (1.0 - smoothstep(0.12, 0.28 + edge, offset)) * smoothstep(0.30, 0.68, breaks);
}

// Tilt a normal by the screen-space gradient of a relief height in meters. `fade` scales the tilt.
vec3 bump(vec3 normal, float relief, float fade)
{
	vec3 dx = dFdx(vWorld), dy = dFdy(vWorld);
	vec3 u = cross(dy, normal), v = cross(normal, dx);
	float det = dot(dx, u);
	vec3 gradient = (dFdx(relief)*u + dFdy(relief)*v) * sign(det) / max(abs(det), 0.0001);
	return normalize(normal - gradient * fade / (1.0 + length(gradient)));
}

void canyonMaterial(float footprint, vec3 weights, float slope, float macro,
	out vec3 rock, out vec3 debris, inout vec3 normal)
{
	float cliff = smoothstep(0.08, 0.45, slope);
	float xWeight = weights.x / max(weights.x + weights.z, 0.001);
	float grooves = cliffNoise(vWorld, vec2(16.0, 74.0), xWeight) - 0.5;
	float vertical = grooves * (1.0 - smoothstep(6.0, 24.0, footprint));
	float worn = (cliffNoise(vWorld, vec2(61.0, 36.0), xWeight) - 0.5)
		* (1.0 - smoothstep(14.0, 65.0, footprint));

	// These are the same dipping contacts as the height sampler. Wrap inside the
	// basal bed so the cap-to-base transition also receives a filtered edge.
	float height = (vWorld.y - dot(vWorld.xz, uBedDatum.xy)) / uBedDatum.z;
	float pixel = fwidth(height);
	vec4 lower = uBedContacts[0], upper = uBedContacts[1];
	float period = upper.w - lower.x, start = lower.x + 0.8;
	float h = mod(height - start, period) + start;
	// Sharper bands narrow each contact. contact() still widens one to the pixel footprint. The floor keeps a program
	// that never bound an appearance from dividing by zero; every valid sharpness is at least 1.
	float sharpness = max(uBanding.z, 1.0);
	float shale = contact(h, lower.y, 0.09 / sharpness, pixel);
	float ledge = contact(h, lower.z, 0.023 / sharpness, pixel);
	float sandstone = contact(h, lower.w, 0.043 / sharpness, pixel);
	float redBeds = contact(h, upper.x, 0.06 / sharpness, pixel);
	float upperCliff = contact(h, upper.y, 0.045 / sharpness, pixel);
	float cap = contact(h, upper.z, 0.026 / sharpness, pixel);
	float basal = contact(h, upper.w, 0.07 / sharpness, pixel);

	vec3 rust = uCanyonColors[0], coral = uCanyonColors[3], distant = uCanyonColors[7];
	rock = mix(rust, uCanyonColors[1], shale);
	rock = mix(rock, uCanyonColors[2], ledge);
	rock = mix(rock, coral, sandstone);
	rock = mix(rock, uCanyonColors[4], redBeds);
	rock = mix(rock, coral, upperCliff);
	rock = mix(rock, uCanyonColors[5], cap);
	rock = mix(rock, rust, basal);
	rock = mix(distant, rock, uBanding.y);
	// Very small authored spacings can fit a whole section inside a pixel.
	rock = mix(rock, distant, smoothstep(0.7, 3.0, pixel));

	float thinBeds = clamp(1.0 - shale + redBeds - upperCliff + basal, 0.0, 1.0);
	float parting = (smoothNoise(vec2(height * 13.0, 0.43)) - 0.5)
		* (1.0 - smoothstep(0.025, 0.085, pixel));
	float joints = mix(fracture(vWorld.xy, footprint), fracture(vWorld.zy, footprint), xWeight)
		* (1.0 - smoothstep(1.0, 5.0, footprint));
	rock *= 1.0 + mix(0.06, 0.40, thinBeds)*parting
		+ cliff*(0.62*vertical + 0.35*worn - 0.28*joints*(1.0-thinBeds));

	vec3 dust = uCanyonColors[6] * (0.91 + 0.24*macro + 0.19*worn);
	// Small aprons of rubble mask the rock at the foot of the resistant beds.
	float apron = 1.0 - smoothstep(0.02, 0.42, min(abs(h-lower.z), abs(h-upper.z)));
	rock = mix(rock, dust, apron * smoothstep(-0.12, 0.34, vertical + 0.45*worn) * 0.64);
	// Angular fragments give loose slopes a distinct texture at walking distances.
	vec2 gravel = mat2(0.8,0.6,-0.6,0.8)*vWorld.xz / 2.4;
	vec2 cell = floor(gravel);
	float seed = hashCell(cell);
	vec2 stone = abs(fract(gravel) - vec2(0.36 + 0.28*seed, 0.5));
	float radius = stone.x + stone.y*(0.7+seed);
	float aa = max(0.025, footprint/2.4);
	float chips = (1.0 - smoothstep(0.18, 0.23 + aa, radius)) * (seed - 0.28);
	debris = mix(dust, rock, 0.51 + 0.15*macro) * (1.0 + 0.53*chips*(1.0-smoothstep(0.6,2.3,footprint)));

	// Finite screen-space gradients of broad grooves perturb diffuse lighting.
	normal = bump(normal, cliff*5.0*grooves, 1.0 - smoothstep(6.0, 24.0, footprint));
}

// Granite mountains. Steep ground shows jointed slabs streaked down the fall line, talus lies on the moderately steep
// ground, and the cover runs from forest through subalpine meadow to tundra as the ground nears the snowline. Ribs and
// gullies running down the fall line move every boundary up or down, so none of them follows a contour.
void mountainMaterial(float footprint, vec3 weights, float slope, float macro, float snowline, float stipple,
	out vec3 rock, out vec3 cover, out float outBare, inout vec3 normal)
{
	vec3 forest = uMountainColors[0], meadow = uMountainColors[1], tundra = uMountainColors[2];
	vec3 stain = uMountainColors[4], talus = uMountainColors[5];
	vec3 granite = mix(uGranitePigments[0],uGranitePigments[1],
		jointedPigment(vWorld,normal,footprint,vec4(9.,1.,13.,23./13.),vec2(0.),vec2(0.)));

	vec3 pos = vWorld, relative = vRelative;
	float water = uWaterLevel;
	{
	// RELIEF FIELDS
	vec3 vegetation = mix(meadow*(1.+.5*tuft),mix(meadow,forest,.55)*mix(1.,crowns,.6),shrubs)*meadowShade;
	vegetation = mix(vegetation,tundra*(1.+.3*clump+.4*tuft+.3*rill)*groundShade,tundraCover);
	vegetation = mix(vegetation,uGrassColor*(1.+.2*blotch+.3*tuft)*groundShade,lowland*level);
	cover = mix(vegetation,forest*crowns*(1.+.3*blotch+.3*clump)*groundShade,trees);
	vec3 rubble = talus*blocks*boulders;
	cover = mix(cover,rubble*(1.+.15*blotch),scree);
	vec3 cliff = mix(granite,stain,sheets*clamp(.5+.42*streak+.4*rill,0.,.85))*jointTint;
	rock = mix(rubble,cliff,cliffFraction);
	outBare = bare;
	vec3 knob = blockTilt*(scree+bare*(1.-steep)) + vec3(offset.x,0.,offset.y)*.5*crownRelief*trees*cellFade;
	normal = normalize(normal + (facet*bare*steep+knob)*relief);
	vec3 tangential = lift-normal*dot(normal,lift);
	normal = normalize(normal-.5*tangential*relief/(1.+length(tangential)));
	}
}

vec3 materialSurface(vec3 pos,vec3 n,vec3 relative,float footprint,float water,float macro,inout vec3 lightingNormal) {
	vec3 weights=pow(abs(n),vec3(4.)); weights/=dot(weights,vec3(1.));
	float slope=1.-max(0.,n.y);
	vec3 grass=uGrassColor*(.85+.15*macro),rock=uRockColor,sand=uSandColor,snow=uSnowColor;
	vec3 albedo;
		vec3 cover;
		float bare = smoothstep(uSlopeBlend.x,uSlopeBlend.y,slope);
		float snowline = uSnowline.x + 25.0 * macro;
		float stipple = 0.0;
		if(uTerrainStyle > 0.5 && uTerrainStyle < 1.5)
		{
			stipple = stippleGrain(vWorld,1.6,1.0,footprint);
			mountainMaterial(footprint, weights, slope, macro, snowline, stipple, rock, cover, bare, lightingNormal);
		}
		else if(uTerrainStyle > 2.5)
			canyonMaterial(footprint, weights, slope, macro, rock, cover, lightingNormal);
		else
		{
			float strataPhase = vWorld.y * uBanding.x;
			float blur = fwidth(strataPhase);
			float strataFilter = exp(-0.125 * pow(blur, 2.0));
			// Clamping the sine adds odd harmonics and needs filtering before they fold back into the pixel rate.
			float sharpness = 1.0 + (uBanding.z - 1.0) * exp(-0.125 * pow(blur * (uBanding.z + 2.0), 2.0));
			float profile = clamp(sin(strataPhase) * sharpness, -1.0, 1.0);
			rock = mix(uRockColor, uRockBandColor, 0.5 + 0.5 * uBanding.y * profile * strataFilter);
			// Strong macro variation must not push the soil weight outside 0 to 1, past either color.
			cover = uTerrainStyle > 1.5 ? mix(uSoilColor, rock, clamp(0.4 + 0.2 * macro, 0.0, 1.0)) : grass;
		}
		albedo = mix(cover, rock, bare);
		albedo = mix(albedo, sand * (1.0 - 0.16*stipple),
			(1.0 - smoothstep(uWaterLevel + uHeightBlend.x,uWaterLevel + uHeightBlend.y,vWorld.y)) * n.y);
		if(uTerrainStyle < 1.5)
			albedo = mix(albedo, snow * (1.0 - 0.35*stipple), smoothstep(snowline,snowline + uSnowline.y,vWorld.y)
				* (1.0 - smoothstep(uSlopeBlend.z,uSlopeBlend.w,slope)));
	return albedo;
}
