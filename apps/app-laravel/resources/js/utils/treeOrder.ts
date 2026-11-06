export type OrderedCode = {
  code: string;
};

export type MoveDirection = 'up' | 'down';

export function moveWithin<T extends OrderedCode>(list: T[], code: string, dir: MoveDirection): T[] {
  const index = list.findIndex((item) => item.code === code);
  const target = dir === 'up' ? index - 1 : index + 1;
  if (index < 0 || target < 0 || target >= list.length) return list;

  const next = [...list];
  [next[index], next[target]] = [next[target], next[index]];
  return next;
}

export function moveByDrag<T extends OrderedCode>(list: T[], fromCode: string, toCode: string): T[] {
  if (fromCode === toCode) return list;
  const fromIndex = list.findIndex((item) => item.code === fromCode);
  const toIndex = list.findIndex((item) => item.code === toCode);
  if (fromIndex < 0 || toIndex < 0) return list;

  const next = [...list];
  const [moved] = next.splice(fromIndex, 1);
  next.splice(toIndex, 0, moved);
  return next;
}

export function flattenTypeOrder(familyCodes: string[], typesByFamily: Record<string, string[]>): string[] {
  return familyCodes.flatMap((familyCode) => typesByFamily[familyCode] ?? []);
}

export function positionLabels(familyCodes: string[], typesByFamily: Record<string, string[]>): Record<string, string> {
  const labels: Record<string, string> = {};
  familyCodes.forEach((familyCode, familyIndex) => {
    const familyLabel = String(familyIndex + 1);
    labels[familyCode] = familyLabel;
    (typesByFamily[familyCode] ?? []).forEach((typeCode, typeIndex) => {
      labels[typeCode] = `${familyLabel}.${typeIndex + 1}`;
    });
  });
  return labels;
}
