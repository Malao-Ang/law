// ponytail: dev-only assertion — no JS test runner in this app. Run:
//   cd apps/app-laravel && npx tsx resources/js/composables/useShowRelations.check.ts
import {
  buildRelationTree,
  collectMixedSameLevelChains,
  collectSameLevelChains,
  currentFamilyTitle,
  regulationFamilyKey,
  sameLevelTreeSkipIds,
  shouldNestAsSectionPatch,
  shouldUnionSameLevel,
  shouldUnionSameLevelFamily,
  versionNodeSize,
  type ShowRelRow,
} from './useShowRelations';
import type { LawRelation } from '../types/document';

function assert(cond: boolean, msg: string): void {
  if (!cond) throw new Error('FAIL: ' + msg);
}

function row(over: Partial<ShowRelRow> & Pick<ShowRelRow, 'id' | 'title'>): ShowRelRow {
  return {
    lawType: 'ระเบียบ',
    typeShort: 'ระเบียบ',
    metaStatus: 'ยกเลิก',
    changeStatus: '',
    workflowStage: 'เผยแพร่',
    isParent: false,
    childCount: 0,
    org: '',
    group: '',
    pages: 1,
    sections: null,
    editedAt: '-',
    rawDate: '2024-01-01',
    parentIds: [],
    ...over,
  };
}

assert(
  regulationFamilyKey('ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยเอกสาร พ.ศ. ๒๕๖๖')
    === regulationFamilyKey('ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยเอกสาร พ.ศ. ๒๕๖๘'),
  'family key strips พ.ศ. year',
);

const v1 = row({
  id: 'v1',
  title: 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๖',
  metaStatus: 'ยกเลิก',
  changeStatus: 'กฎหมายใหม่',
  rawDate: '2023-01-01',
});
const v2 = row({
  id: 'v2',
  title: 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๗',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'ปรับปรุงรายข้อ',
  rawDate: '2024-01-01',
  parentIds: [],
});
const v3 = row({
  id: 'v3',
  title: 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๘',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'ปรับปรุงรายข้อ',
  rawDate: '2025-01-01',
  parentIds: [],
});
const v4 = row({
  id: 'v4',
  title: 'ระเบียบว่าด้วยเอกสาร พ.ศ. ๒๕๖๙',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'ปรับปรุงทั้งฉบับ',
  rawDate: '2026-01-01',
  parentIds: [],
});
const announcement = row({
  id: 'ann',
  title: 'ประกาศเรื่องอื่น',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['v3'],
});

assert(!shouldUnionSameLevel(v1, v2), 'section patches stay out of the whole-edition chain');
assert(versionNodeSize(v1.changeStatus) === 'big', 'original is a big node');
assert(versionNodeSize(v2.changeStatus) === 'small', 'section amendment is a small node');
assert(versionNodeSize(v4.changeStatus) === 'big', 'whole edition is a big node');
assert(!shouldUnionSameLevel(v3, announcement), 'different law type is not same-level');

const chainFromV2 = collectSameLevelChains('v2', [v1, v2, v3, v4, announcement], []);
assert(chainFromV2.length === 1, 'section node still shows the family whole-edition graph');
assert(chainFromV2[0].map((item) => item.id).join(',') === 'v1,v4', 'whole-edition graph is V1 and V4 only');
assert(currentFamilyTitle(chainFromV2[0]).includes('๒๕๖๙'), 'current title is the in-force whole edition');

const mixedFromV2 = collectMixedSameLevelChains([v1, v2, v3, v4, announcement], [], 'v2');
assert(mixedFromV2.length === 1, 'mixed family is one chain');
assert(mixedFromV2[0].map((item) => item.id).join(',') === 'v1,v2,v3,v4', 'mixed chain includes whole editions and section patches');

const mixedSkip = sameLevelTreeSkipIds('v4', mixedFromV2);
assert(mixedSkip.has('v1') && mixedSkip.has('v2') && mixedSkip.has('v3') && !mixedSkip.has('v4'), 'tree keeps latest whole edition and puts others on the horizontal chain');

assert(shouldNestAsSectionPatch(v1, v2), 'section patch nests under the original');
assert(shouldNestAsSectionPatch(v4, v2), 'section patch also nests under the latest whole edition of the family');

const actRoot = row({
  id: 'act-root',
  title: 'พ.ร.บ. แม่',
  lawType: 'พระราชบัญญัติ',
  typeShort: 'พ.ร.บ.',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'กฎหมายใหม่',
});
const v1UnderAct = { ...v1, parentIds: ['act-root'] };
const v2UnderAct = { ...v2, parentIds: ['act-root'] };
const v4UnderAct = { ...v4, parentIds: ['act-root'] };
const prakat = row({
  id: 'prakat-1',
  title: 'ประกาศลูก',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['act-root'],
});
const actRows = [actRoot, v1UnderAct, v2UnderAct, v4UnderAct, prakat];
const actChains = collectSameLevelChains('act-root', actRows, []);
const tree2 = buildRelationTree('act-root', actRows, [], null, sameLevelTreeSkipIds('act-root', actChains));
assert(Boolean(tree2), 'parent tree exists');
assert(tree2!.children.some((c) => c.row.id === 'v4'), 'latest whole edition stays at level 1');
assert(!tree2!.children.some((c) => c.row.id === 'v1'), 'older whole edition is not a sibling under the act');
assert(tree2!.children.some((c) => c.row.id === 'prakat-1'), 'issued-under announcement stays at level 1');

const childNewLaw = row({
  id: 'child-new',
  title: 'ระเบียบลูกที่ออกใหม่',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['b-parent'],
});
const parentReg = row({
  id: 'b-parent',
  title: 'ระเบียบแม่',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'มีผลบังคับใช้',
});
assert(!shouldUnionSameLevelFamily(parentReg, childNewLaw), 'new law under a parent is hierarchy, not same-level');
const treeHierarchy = buildRelationTree('b-parent', [parentReg, childNewLaw], [], null);
assert(treeHierarchy?.children.some((c) => c.row.id === 'child-new') === true, 'new law appears as a child under its parent');
assert((treeHierarchy?.sameLevelVersions.length ?? 0) === 0, 'new law parent has no same-level chain with its child');

const lawB = row({
  id: 'B',
  title: 'พ.ร.บ. แม่',
  lawType: 'พระราชบัญญัติ',
  typeShort: 'พ.ร.บ.',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'มีผลบังคับใช้',
});
const lawA = row({
  id: 'A',
  title: 'ระเบียบ ก',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['B'],
});
const lawC = row({
  id: 'C',
  title: 'ระเบียบ ก แก้ไขรายข้อ',
  changeStatus: 'ปรับปรุงรายข้อ',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['A'],
});
assert(!shouldUnionSameLevelFamily(lawA, lawC), 'parent pointer alone does not make a section patch same-level');
assert(shouldUnionSameLevelFamily(lawA, lawC, ['amends']), 'section amends of A is same-level');
const treeBAC = buildRelationTree('B', [lawB, lawA, lawC], {
  C: [{
    id: 'c-amends-a',
    scope: 'section',
    block_id: 'c-blk',
    type: 'amends',
    target_document_id: 'A',
    target_title: 'ระเบียบ ก',
    target_section: 'ข้อ 1',
    target_block_id: null,
    note: null,
    url: null,
  }],
});
assert(treeBAC?.children.some((c) => c.row.id === 'A') === true, 'A stays under B');
assert(treeBAC?.children.some((c) => c.row.id === 'C') !== true, 'C is not a sibling of A under B');
const aNode = treeBAC?.children.find((c) => c.row.id === 'A');
assert(aNode?.sameLevelVersions.some((item) => item.id === 'C') === true, 'C appears on A as a same-level version');

const firstLaw = row({
  id: 'first',
  title: 'กฎหมายฉบับแรก',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'รอส่ง eSign',
});
const secondLaw = row({
  id: 'second',
  title: 'กฎหมายออกใหม่2',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  changeStatus: 'กฎหมายใหม่',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['first'],
});
const torPatch = row({
  id: 'tor',
  title: 'ขอบเขตการดำเนินงาน (Term of Reference) ระบบฐานข้อมูลกฎหมาย',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  changeStatus: 'ปรับปรุงรายข้อ',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['second'],
});
assert(!shouldUnionSameLevelFamily(firstLaw, secondLaw), 'issued-under child stays below its parent');
assert(shouldUnionSameLevelFamily(secondLaw, torPatch, ['amends']), 'TOR section-amends the child law');
const treeFirst = buildRelationTree('first', [firstLaw, secondLaw, torPatch], {
  tor: [{
    id: 'tor-amends-second',
    scope: 'section',
    block_id: 'tor-blk',
    type: 'amends',
    target_document_id: 'second',
    target_title: 'กฎหมายออกใหม่2',
    target_section: 'ข้อ 1',
    target_block_id: null,
    note: null,
    url: null,
  }],
});
assert((treeFirst?.sameLevelVersions.length ?? 0) === 0, 'parent is not in the child version row');
assert(treeFirst?.children.some((c) => c.row.id === 'second') === true, 'second law is a child of the first');
assert(treeFirst?.children.some((c) => c.row.id === 'tor') !== true, 'TOR is not a sibling of the second law under the parent');
const secondNode = treeFirst?.children.find((c) => c.row.id === 'second');
assert(secondNode?.sameLevelVersions.some((item) => item.id === 'tor') === true, 'TOR sits on the second law row');
const v4Node = tree2!.children.find((c) => c.row.id === 'v4');
assert(!v4Node?.children.some((c) => c.row.id === 'v2'), 'section patch is not a vertical tree child');
assert(v4Node?.sameLevelVersions.map((item) => item.id).join(',') === 'v1,v2,v4', 'leaf stores same-level versions');
assert(v4Node?.children.length === 0, 'same-level editions are not hierarchy children');
assert(tree2!.children.length >= 2, 'parent-child issued-under stays as tree siblings');

const parent = row({
  id: 'act',
  title: 'พ.ร.บ. มหาวิทยาลัย',
  lawType: 'พระราชบัญญัติ',
  typeShort: 'พ.ร.บ.',
  metaStatus: 'มีผลบังคับใช้',
});
const sib1 = row({
  id: 's1',
  title: 'ระเบียบว่าด้วยการเงิน พ.ศ. ๒๕๖๕',
  changeStatus: 'กฎหมายใหม่',
  rawDate: '2022-01-01',
  parentIds: ['act'],
});
const sib2 = row({
  id: 's2',
  title: 'ระเบียบว่าด้วยการเงิน พ.ศ. ๒๕๖๗',
  changeStatus: 'ปรับปรุงทั้งฉบับ',
  rawDate: '2024-06-01',
  parentIds: ['act'],
});
const otherChild = row({
  id: 'prakat',
  title: 'ประกาศกำหนดหลักเกณฑ์',
  lawType: 'ประกาศ',
  typeShort: 'ประกาศ',
  metaStatus: 'มีผลบังคับใช้',
  parentIds: ['act'],
});

const fromParent = collectSameLevelChains('act', [parent, sib1, sib2, otherChild], []);
assert(fromParent.length === 1, 'parent shows child version chain');
assert(fromParent[0].map((item) => item.id).join(',') === 's1,s2', 'sibling versions grouped');

const amends: LawRelation[] = [{
  id: 'r1',
  scope: 'document',
  block_id: null,
  type: 'amends',
  target_document_id: 'old',
  target_title: 'ระเบียบเก่า',
  target_section: null,
  target_block_id: null,
  note: null,
  url: null,
}];
const newer = row({
  id: 'new',
  title: 'ระเบียบเรื่อง ก พ.ศ. ๒๕๖๘',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'ปรับปรุงทั้งฉบับ',
  rawDate: '2025-01-01',
});
const older = row({
  id: 'old',
  title: 'ระเบียบเรื่อง ก (ฉบับเดิม)',
  metaStatus: 'ยกเลิก',
  changeStatus: 'กฎหมายใหม่',
  rawDate: '2023-01-01',
});
assert(shouldUnionSameLevel(newer, older, ['amends']), 'amends edge unions even if titles differ');
const fromAmends = collectSameLevelChains('new', [newer, older], amends);
assert(fromAmends[0].map((item) => item.id).join(',') === 'old,new', 'amends chain ordered by date');

const sectionAmends: LawRelation[] = [{
  id: 'r-section',
  scope: 'section',
  block_id: 'blk-1',
  type: 'amends',
  target_document_id: 'orig',
  target_title: 'ระเบียบตั้งต้นคนละชื่อ',
  target_section: 'ข้อ 5',
  target_block_id: 'orig-blk',
  note: null,
  url: null,
}];
const sectionPatch = row({
  id: 'patch',
  title: 'ระเบียบแก้ไขรายข้อ พ.ศ. ๒๕๖๘',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'ปรับปรุงรายข้อ',
  rawDate: '2025-06-01',
});
const original = row({
  id: 'orig',
  title: 'ระเบียบตั้งต้นคนละชื่อ',
  metaStatus: 'มีผลบังคับใช้',
  changeStatus: 'กฎหมายใหม่',
  rawDate: '2023-06-01',
});
assert(shouldUnionSameLevelFamily(sectionPatch, original, ['amends']), 'section-level amends still links documents');
const fromSection = collectMixedSameLevelChains([sectionPatch, original], sectionAmends, 'patch');
assert(fromSection[0].map((item) => item.id).join(',') === 'orig,patch', 'section amends appear as a same-level document chain');

console.log('useShowRelations.check.ts: all passed');
