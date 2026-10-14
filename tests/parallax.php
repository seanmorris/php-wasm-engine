<?php
use PhpWasmEngine\Render\
{
	Camera,Parallax
};
use PhpWasmEngine\Tiled\Layer;

test('image layers inherit group state and resolve animated backgrounds', function() {
	$properties = [['name' => 'frames'
	, 'value' =>
		'[{"image":"a.png","duration":100},{"image":"b.png","duration":200}]'
	]];

	$image = ['type' => 'imagelayer'
	, 'image' => '../sky.png'
	, 'imagewidth' => 64
	, 'imageheight' => 16
		, 'offsetx' => 2,'repeatx' => true,'repeaty' => false,'parallaxx' => .5
		, 'properties' =>
		$properties
	];

	$data = mapData(
		[['type' => 'group', 'offsetx' => 10, 'opacity' => .5, 'parallaxx' => .5
		, 'layers' => [
			$image
		]]]
	);

	$map = fixture(['/maps/a.tmj' => $data,'/maps/tiles.tsj' => tiles()])->map('/maps/a.tmj');
	$layer = $map->layers[0];
	check($layer->image === '/sky.png' && $layer->imageWidth === 64 && $layer->imageHeight === 16);
	check($layer->repeatX && !$layer->repeatY);
	near($layer->offsetX, 12);
	near($layer->parallaxX, .25);
	near($layer->opacity, .5);
	check(Parallax::image($layer, 99) === '/maps/a.png');
	check(Parallax::image($layer, 100) === '/maps/b.png');
	check(Parallax::image($layer, 300) === '/maps/a.png');
	check(Parallax::image($layer, -1) === '/maps/a.png');
});

test('Tiled parallax uses the view center and the world map origin', function() {
	$map = fixture(
		['a.tmj' => mapData([], ['parallaxoriginx' => 20,'parallaxoriginy' => 10])
		, 'tiles.tsj' =>
			tiles()
		]
	)->map('a.tmj');

	$map->x = 100;
	$map->y = 200;
	$layer = new Layer(1, 'sky', 'imagelayer');
	$layer->parallaxX = .5;
	$layer->parallaxY = .25;
	$camera = new Camera(70, 185, 200, 100, 2);
	[$x,$y] = Parallax::camera($layer, $map, $camera);
	near($x, 70);
	near($y, 185); // Camera center equals map parallax origin.
	$camera->x += 40;
	$camera->y += 20;
	[$x,$y] = Parallax::camera($layer, $map, $camera);
	near($x, 90);
	near($y, 190);
});

test('anchored strips preserve aspect and wrap negative coordinates without seams', function() {
	$map = fixture(['a.tmj' => mapData([]),'tiles.tsj' => tiles()])->map('a.tmj');
	$layer = new Layer(1, 'cloud', 'imagelayer');
	$layer->imageWidth = 32;
	$layer->imageHeight = 8;
	$layer->repeatX = true;
	$layer->parallaxX = .5;
	$layer->parallaxY = 0;
	$layer->properties = ['from' => 'bottom','scrollX' => 8];
	$camera = new Camera(-20, 900, 200, 100, 2);
	$layout = Parallax::layout($layer, $map, $camera, 1000, 16);
	check($layout['rect'] === [0.0,68.0,200.0,16.0]);
	near($layout['uv'][0], .4375);
	near($layout['uv'][2], 3.125);
	$camera->x += 64;
	$wrapped = Parallax::layout($layer, $map, $camera, 1000, 16);
	check($wrapped === $layout);
	$layer->properties['from'] = 'center';
	near(Parallax::layout($layer, $map, $camera, 1000, 16)['rect'][1], 34);
	$layer->properties['from'] = 'top';
	near(Parallax::layout($layer, $map, $camera, 1000, 16)['rect'][1], 0);
	$layer->visible = false;
	check(Parallax::layout($layer, $map, $camera, 0, 16) === null);
});

test('finite images clip while repeated images cover both axes', function() {
	$map = fixture(['a.tmj' => mapData([]),'tiles.tsj' => tiles()])->map('a.tmj');
	$layer = new Layer(1, 'image', 'imagelayer');
	$layer->imageWidth = 16;
	$layer->imageHeight = 8;
	$camera = new Camera(4, 2, 100, 80);
	$layout = Parallax::layout($layer, $map, $camera);
	check($layout['rect'] === [0.0,0.0,12.0,6.0]);
	check($layout['uv'] === [.25,.25,.75,.75]);
	$camera->x = 100;
	check(Parallax::layout($layer, $map, $camera) === null);
	$layer->repeatX = $layer->repeatY = true;
	check(Parallax::layout($layer, $map, $camera)['rect'] === [0.0,0.0,100.0,80.0]);
});

test(
	'anchored backgrounds keep their phase when their map placement and origin change'
	, function() {
		$map = fixture(['a.tmj' => mapData([]),'tiles.tsj' => tiles()])->map('a.tmj');
		$layer = new Layer(1, 'clouds', 'imagelayer');
		$layer->imageWidth = 7;
		$layer->imageHeight = 5;
		$layer->repeatX = $layer->repeatY = true;
		$layer->offsetX = 3;
		$layer->offsetY = -2;
		$layer->parallaxX = .43;
		$layer->parallaxY = .17;

		foreach(['top','center','bottom'] as $anchor)
		{
			foreach([.5,1,2.5] as $zoom)
			{
				$layer->properties = ['from' => $anchor,'scrollX' => 6,'scrollY' => -3];
				$camera = new Camera(-177.25, 36.75, 200, 100, $zoom);
				$map->x = $map->y = 0;
				$map->data['parallaxoriginx'] = $map->data['parallaxoriginy'] = 0;
				$expected = Parallax::layout($layer, $map, $camera, 3125, 24);

				foreach([[-1024,-640],[1024,640],[-1000004,500006]] as [$x,$y])
				{
					$map->x = $x;
					$map->y = $y;
					$map->data['parallaxoriginx'] = 11;
					$map->data['parallaxoriginy'] = -9;

					check(
						Parallax::layout($layer, $map, $camera, 3125, 24) === $expected
						, 'map placement changed an anchored background'
					);

					check($map->x === (float)$x && $map->y === (float)$y, 'layout changed the map placement');
				}
			}
		}
	}
);

test('background animation and anchors reject invalid definitions', function() {
	foreach(['[{"image":"x.png","duration":0}]','{}','[{"duration":10}]'] as $frames)
	{
		$loader = fixture(
			['a.tmj' => mapData(
				[['type' => 'imagelayer', 'image' => 'x.png'
				, 'properties' => [['name' => 'frames'
					, 'value' => $frames
				]]]]
			)
			, 'tiles.tsj' => tiles()
			]
		);

		rejects(fn() => $loader->map('a.tmj'));
	}

	$loader = fixture(
		['a.tmj' => mapData(
			[['type' => 'imagelayer', 'image' => 'x.png'
			, 'properties' => [['name' => 'from'
				, 'value' => 'sideways'
			]]]]
		)
		, 'tiles.tsj' => tiles()
		]
	);

	rejects(fn() => $loader->map('a.tmj'));
});
