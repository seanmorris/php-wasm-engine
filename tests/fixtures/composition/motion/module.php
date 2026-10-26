<?php
return static function(\Phonics\Package\Context $context): void {
	$context->factory('Fixture.Position', static fn(array $object): array => [
		new \Phonics\Physics\Pose((float)$object['x'], (float)$object['y'], 8, 8)
	]);

	$context->system('fixture.motion', static function($entities, float $dt): void {
		foreach($entities->query(\Phonics\Physics\Pose::class) as $id)
		$entities->get($id, \Phonics\Physics\Pose::class)->x += 20 * $dt;
	});
};
