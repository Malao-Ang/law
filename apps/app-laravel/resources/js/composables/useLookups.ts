import { ref } from 'vue';
import { getLookups, type LookupData, type SelectableOption } from '../api/client';

const documentTypes = ref<(SelectableOption & { source?: string })[]>([]);
const statuses = ref<SelectableOption[]>([]);
const changeStatuses = ref<SelectableOption[]>([]);
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
      changeStatuses.value = data.change_statuses;
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
  return { documentTypes, statuses, changeStatuses, agencies, lawGroups, lawSources, load };
}
