<template>
  <v-menu
    v-model="open"
    :close-on-content-click="false"
    max-width="360"
    transition="scale-transition"
  >
    <template #activator="{ props: menuProps }">
      <v-text-field
        :model-value="displayValue"
        :label="label"
        :rules="rules"
        :required="required"
        :disabled="disabled"
        :placeholder="disabled ? disabledPlaceholder : ''"
        :prepend-inner-icon="icon"
        variant="outlined"
        readonly
        v-bind="menuProps"
        @click:clear="emit('update:modelValue', null)"
      />
    </template>

    <v-locale-provider locale="th">
      <v-date-picker
        :model-value="pickerValue"
        color="admin-primary"
        title="เลือกวันที่"
        header="วันที่เลือก"
        hide-header
        show-adjacent-months
        @update:model-value="onPick"
      />
    </v-locale-provider>
  </v-menu>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
  modelValue: string | null | undefined;
  label?: string;
  required?: boolean;
  disabled?: boolean;
  disabledPlaceholder?: string;
  rules?: ((v: unknown) => boolean | string)[];
  icon?: string;
}>(), {
  label: '',
  required: false,
  disabled: false,
  disabledPlaceholder: '',
  rules: () => [],
  icon: 'mdi-calendar',
});

const emit = defineEmits<{
  (e: 'update:modelValue', value: string | null): void;
}>();

const open = ref(false);

// Display: Buddhist Era Thai locale, e.g. "25 กรกฎาคม 2569"
const displayValue = computed(() => {
  if (!props.modelValue) return '';
  const d = new Date(props.modelValue + 'T00:00:00');
  if (isNaN(d.getTime())) return props.modelValue;
  return d.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    calendar: 'buddhist',
  });
});

// v-date-picker needs a Date object or null
const pickerValue = computed<Date | null>(() => {
  if (!props.modelValue) return null;
  const d = new Date(props.modelValue + 'T00:00:00');
  return isNaN(d.getTime()) ? null : d;
});

function onPick(value: unknown): void {
  const d = value instanceof Date
    ? value
    : typeof value === 'string'
      ? new Date(value + 'T00:00:00')
      : null;

  if (!d || isNaN(d.getTime())) {
    emit('update:modelValue', null);
  } else {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    emit('update:modelValue', `${y}-${m}-${day}`);
  }
  open.value = false;
}
</script>
