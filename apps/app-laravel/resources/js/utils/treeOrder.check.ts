import { flattenTypeOrder, moveByDrag, moveWithin, positionLabels } from './treeOrder';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error(`FAIL: ${msg}`);
}

const families = [{ code: 'LFM01' }, { code: 'LFM02' }, { code: 'LFM03' }];

assert(moveWithin(families, 'LFM02', 'up').map((item) => item.code).join(',') === 'LFM02,LFM01,LFM03', 'move up swaps with previous item');
assert(moveWithin(families, 'LFM02', 'down').map((item) => item.code).join(',') === 'LFM01,LFM03,LFM02', 'move down swaps with next item');
assert(moveWithin(families, 'LFM01', 'up') === families, 'first item move up is no-op');
assert(moveWithin(families, 'LFM03', 'down') === families, 'last item move down is no-op');
assert(moveByDrag(families, 'LFM03', 'LFM01').map((item) => item.code).join(',') === 'LFM03,LFM01,LFM02', 'drag inserts before target');
assert(moveByDrag(families, 'LFM04', 'LFM01') === families, 'missing drag source is no-op');

const typesByFamily = {
  LFM01: ['LTY03'],
  LFM02: ['LTY02'],
  LFM03: ['LTY01', 'LTY09'],
};

const movedWithinFamily = {
  ...typesByFamily,
  LFM03: moveWithin(typesByFamily.LFM03.map((code) => ({ code })), 'LTY09', 'up').map((item) => item.code),
};

assert(movedWithinFamily.LFM03.join(',') === 'LTY09,LTY01', 'type moves inside its own family');
assert(typesByFamily.LFM02.join(',') === 'LTY02', 'type move does not cross families');
assert(flattenTypeOrder(['LFM02', 'LFM03', 'LFM01'], typesByFamily).join(',') === 'LTY02,LTY01,LTY09,LTY03', 'flatten follows family order');

const labels = positionLabels(['LFM01', 'LFM02', 'LFM03'], typesByFamily);
assert(labels.LFM03 === '3', 'family label is one-based position');
assert(labels.LTY01 === '3.1', 'type label includes family and type position');
assert(labels.LTY09 === '3.2', 'second type label increments inside family');

console.log('PASS: treeOrder helpers');
