import { ref } from 'vue';
import { getLookups, type LookupData, type SelectableOption } from '../api/client';

const documentTypes = ref<(SelectableOption & { source?: string })[]>([]);
const statuses = ref<SelectableOption[]>([]);
const changeStatusTypes = ref<(SelectableOption & { source?: string; has_details?: boolean })[]>([]);
const changeStatusDetails = ref<(SelectableOption & { source?: string })[]>([]);
const agencies = ref<SelectableOption[]>([]);
const lawGroups = ref<SelectableOption[]>([]);
const lawSources = ref<SelectableOption[]>([]);
let loaded = false;
let inFlight: Promise<void> | null = null;

async function load(): Promise<void> {
  if (loaded) return;

  if (!inFlight) {
    inFlight = getLookups().then((data: LookupData) => {
      documentTypes.value = data.document_types;
      statuses.value = data.statuses;
      changeStatusTypes.value = data.change_status_types;
      changeStatusDetails.value = data.change_status_details;
      agencies.value = data.agencies;
      lawGroups.value = data.law_groups;
      lawSources.value = data.law_sources;
      loaded = true;
    }).finally(() => {
      inFlight = null;
    });
  }

  await inFlight;
}

export function useLookups() {
  return { documentTypes, statuses, changeStatusTypes, changeStatusDetails, agencies, lawGroups, lawSources, load };
}
