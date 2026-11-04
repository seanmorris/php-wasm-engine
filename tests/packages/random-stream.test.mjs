import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {RandomStream} from '../../browser/random/RandomStream.mjs';

test('PHP and browser streams agree on bytes, bounds, rejection, shuffles and restore', async() => {
	const root = fileURLToPath(new URL('../../', import.meta.url));

	const php = execFileSync('php', ['-r'
		, String.raw`
require $argv[1].'/autoload.php';
$r=new Phonics\Random\RandomStream('golden-seed','poker/vector');
$bytes=bin2hex($r->nextBytes(35)); $integers=[];
foreach([2,3,5,6,7,8,52,1073741825,2147483647] as $bound) $integers[]=$r->boundedInt($bound);
echo json_encode(['bytes'=>$bytes,'integers'=>$integers,'cards'=>$r->shuffle(range(0,51)),'state'=>$r->snapshot()]);
`
		, root
	], {encoding: 'utf8'}
	);

	const expected = JSON.parse(php),r = new RandomStream('golden-seed', 'poker/vector');
	assert.equal(Buffer.from(await r.nextBytes(35)).toString('hex'), expected.bytes);
	const integers = [];

	for(const bound of [2,3,5,6,7,8,52,1073741825,2147483647]) integers.push(await r.boundedInt(bound));
	assert.deepEqual(integers, expected.integers);
	assert.deepEqual(await r.shuffle(Array.from({length: 52}, (_, i) => i)), expected.cards);
	assert.deepEqual(r.snapshot(), expected.state);
	assert.deepEqual(await r.nextBytes(70), await RandomStream.restore(expected.state).nextBytes(70));
});

test('chunking, independent domains and concurrent byte reservations are stable', async() => {
	const a = new RandomStream('seed', 'a'),b = new RandomStream('seed', 'a');
	const parts = await Promise.all([a.nextBytes(17),a.nextBytes(49),a.nextBytes(1)]);
	assert.deepEqual(Buffer.concat(parts), Buffer.from(await b.nextBytes(67)));

	assert.notDeepEqual(
		await new RandomStream('seed', 'a').nextBytes(32)
		, await new RandomStream('seed', 'b').nextBytes(32)
	);

	await assert.rejects(a.boundedInt(0));
	await assert.rejects(a.nextBytes(-1));
	assert.throws(() => RandomStream.restore({...a.snapshot(),position: -1}));
});
