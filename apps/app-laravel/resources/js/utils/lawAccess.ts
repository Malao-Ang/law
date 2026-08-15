import type { LawSearchResult } from '../types/lawSearch';

export function canDisplayLawResult(law: LawSearchResult, isAuthenticated: boolean): boolean {
  if (!law.restricted) return true;
  if (!law.requires_permission) return true;

  return isAuthenticated;
}
