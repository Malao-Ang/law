import type { LawSearchResult } from '../types/lawSearch';

export function canDisplayLawResult(law: LawSearchResult, isAuthenticated: boolean): boolean {
  if (!law.restricted) return true;
  return isAuthenticated;
}
