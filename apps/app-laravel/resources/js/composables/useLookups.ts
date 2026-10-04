import { ref } from 'vue';
import {
  getLookups,
  type DocumentTypeOption,
  type LawFamilyOption,
  type LawStatusOption,
  type LookupData,
  type SelectableOption,
} from '../api/client';

const documentTypes = ref<DocumentTypeOption[]>([]);
const documentTypesAll = ref<DocumentTypeOption[]>([]);
const lawFamilies = ref<LawFamilyOption[]>([]);
const lawFamiliesAll = ref<LawFamilyOption[]>([]);
const statuses = ref<LawStatusOption[]>([]);
const statusesAll = ref<LawStatusOption[]>([]);
const changeStatusTypes = ref<(SelectableOption & { source?: string; has_details?: boolean })[]>([]);
const changeStatusDetails = ref<(SelectableOption & { source?: string })[]>([]);
const agencies = ref<SelectableOption[]>([]);
const lawGroups = ref<(SelectableOption & { code: string; sort_order?: number })[]>([]);
const lawGroupsAll = ref<(SelectableOption & { code: string; sort_order?: number })[]>([]);
const lawSources = ref<SelectableOption[]>([]);
let loaded = false;
let inFlight: Promise<void> | null = null;

async function load(): Promise<void> {
  if (loaded) return;

  if (!inFlight) {
    inFlight = getLookups().then((data: LookupData) => {
      documentTypes.value = data.document_types;
      documentTypesAll.value = data.document_types_all ?? data.document_types;
      lawFamilies.value = data.law_families;
      lawFamiliesAll.value = data.law_families_all ?? data.law_families;
      statuses.value = data.statuses;
      statusesAll.value = data.statuses_all ?? data.statuses;
      changeStatusTypes.value = data.change_status_types;
      changeStatusDetails.value = data.change_status_details;
      agencies.value = data.agencies;
      lawGroups.value = data.law_groups;
      lawGroupsAll.value = data.law_groups_all ?? data.law_groups;
      lawSources.value = data.law_sources;
      loaded = true;
    }).finally(() => {
      inFlight = null;
    });
  }

  await inFlight;
}

async function reload(): Promise<void> {
  loaded = false;
  await load();
}

export function useLookups() {
  return {
    documentTypes,
    documentTypesAll,
    lawFamilies,
    lawFamiliesAll,
    statuses,
    statusesAll,
    changeStatusTypes,
    changeStatusDetails,
    agencies,
    lawGroups,
    lawGroupsAll,
    lawSources,
    load,
    reload,
  };
}
