<?php
return static function(\Phonics\Package\Context $context): void {
	$velocity = (new \Phonics\Asset\Assets)->json('@color/tuning')['velocity'];

	$context->system('fixture.color', static function($entities, float $dt) use ($velocity): void {
		foreach($entities->query(\Phonics\Physics\Pose::class) as $id)
		$entities->get($id, \Phonics\Physics\Pose::class)->y += $velocity * $dt;
	}, ['fixture.motion']);
};
