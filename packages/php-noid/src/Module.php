<?php
declare(strict_types=1);
namespace PhpNoid;
use Phonics\Package\Context;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Enemy;
use PhpNoid\System\PaddleSystem;
use PhpNoid\System\BallSystem;
use PhpNoid\System\CapsuleSystem;
use PhpNoid\System\EnemySystem;
use PhpNoid\System\ProjectileSystem;
use PhpNoid\System\StageSystem;
use PhpNoid\System\TransitionSystem;

/** Register authoring factories independently from game startup. */
final class Module
{

	/**
	 * Register the package entity factories with the session.
	 *
	 * @param Context $context Session registration and lifecycle services.
	 * @return void Completes the operation.
	 */
	public static function factories(Context $context): void
	{
		$context->factory('PhpNoid.Brick', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return array Result of this operation.
		 */
			static function(array $o): array {
				$p = $o['properties'];

				return [
					new Brick(
						(float)$o['x']
						, (float)$o['y']
						, (float)($o['width'] ?? 54)
						, (float)($o['height'] ?? 20)
						, (int)($p['hits'] ?? 1)
						, (bool)($p['unbreakable'] ?? false)
						, (string)($p['capsule'] ?? '')
						, (int)($p['score'] ?? 100)
						, (string)($p['material'] ?? 'normal')
					)
				];
			}
		);

		$context->factory('PhpNoid.Paddle', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return mixed Result of this operation.
		 */
			static fn(array $o) => [new Paddle((float)$o['x'], (float)$o['y'])]
		);

		$context->factory('PhpNoid.Enemy', /**
		 * Apply the registered session callback.
		 *
		 * @param array $o O value used by this operation.
		 * @return mixed Result of this operation.
		 */
			static fn(array $o) => [
				new Enemy(
					(float)$o['x']
					, (float)$o['y']
					, $o['properties']['family'] ?? 'menacer'
					, $o['properties']['effect'] ?? 'copper'
					, (float)($o['properties']['interval'] ?? 5)
					, (int)($o['properties']['hits'] ?? 3)
					, (string)($o['properties']['capsule'] ?? 'expand')
				)
			]
		);
	}

	/**
	 * Register independently composable Tiled components alongside legacy factories.
	 *
	 * Each constructor receives the component's resolved properties, the content scope that owns the entity, and the
	 * placed Tiled object, whose position is offset by its layer.
	 *
	 * @param Context $context Package context receiving the component constructors.
	 * @return void Registers the brick, paddle, and enemy components.
	 */
	public static function components(Context $context): void
	{

		/**
		 * Build a brick from its object's rectangle and its component properties.
		 *
		 * @param array<string,mixed> $p Resolved PhpNoid.Brick properties.
		 * @param \Phonics\World\ScopeContext $scope Content scope that owns the brick.
		 * @param \Phonics\Tiled\Target $target Placed Tiled object and its layer.
		 * @return Brick The brick component.
		 */
		$brick = static function(array $p, $scope, $target): Brick {
			$o = $target->value;

			return new Brick(
				(float)($o['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($o['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
				, (float)($o['width'] ?? 54)
				, (float)($o['height'] ?? 20)
				, (int)$p['hits']
				, (bool)$p['unbreakable']
				, (string)$p['capsule']
				, (int)$p['score']
				, (string)$p['material']
			);
		};

		/**
		 * Build a paddle at its object's position.
		 *
		 * @param array<string,mixed> $p Resolved PhpNoid.Paddle properties.
		 * @param \Phonics\World\ScopeContext $scope Content scope that owns the paddle.
		 * @param \Phonics\Tiled\Target $target Placed Tiled object and its layer.
		 * @return Paddle The paddle component.
		 */
		$paddle = static function(array $p, $scope, $target): Paddle {
			return new Paddle(
				(float)($target->value['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($target->value['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
			);
		};

		/**
		 * Build an enemy at its object's position from its component properties.
		 *
		 * @param array<string,mixed> $p Resolved PhpNoid.Enemy properties.
		 * @param \Phonics\World\ScopeContext $scope Content scope that owns the enemy.
		 * @param \Phonics\Tiled\Target $target Placed Tiled object and its layer.
		 * @return Enemy The enemy component.
		 */
		$enemy = static function(array $p, $scope, $target): Enemy {
			return new Enemy(
				(float)($target->value['x'] ?? 0) + ($target->layer?->offsetX ?? 0)
				, (float)($target->value['y'] ?? 0) + ($target->layer?->offsetY ?? 0)
				, $p['family']
				, $p['effect']
				, (float)$p['interval']
				, (int)$p['hits']
				, $p['capsule']
			);
		};

		$context->component('PhpNoid.Brick', $brick);
		$context->component('PhpNoid.Paddle', $paddle);
		$context->component('PhpNoid.Enemy', $enemy);
	}

	/**
	 * Register independently ordered gameplay systems.
	 *
	 * @param Playfield $field Playfield state shared by this system.
	 * @param \Phonics\World\ScopeContext|null $scope Scope that owns the systems, or null for the playfield's context.
	 * @return void Completes the operation.
	 */
	public static function systems(Playfield $field, ?\Phonics\World\ScopeContext $scope = null): void
	{
		$previous = [];

		foreach([
			PaddleSystem::class
			, EnemySystem::class
			, BallSystem::class
			, CapsuleSystem::class
			, ProjectileSystem::class, StageSystem::class, TransitionSystem::class
		] as $class)
		{
			$system = new $class($field);
			($scope ?? $field->context)->system($class, [$system, 'update'], $previous);
			$previous = [$class];
		}
	}
}
