<template>
  <button
    type="button"
    class="scope-card"
    :class="{ 'scope-card--selected': selected }"
    @click="$emit('select')"
  >
    <div class="scope-card__icon" :style="{ backgroundColor: `${color}18`, color }">
      <v-icon :icon="icon" size="20" />
    </div>
    <div class="scope-card__content">
      <div class="text-subtitle-1 font-weight-bold">{{ title }}</div>
      <div class="text-body-2 text-medium-emphasis">{{ subtitle }}</div>
    </div>
    <div class="scope-card__radio">
      <v-icon
        :icon="selected ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'"
        :color="selected ? 'admin-primary' : 'grey'"
      />
    </div>
  </button>
</template>

<script setup lang="ts">
defineProps<{
  title: string;
  subtitle: string;
  icon: string;
  color: string;
  selected: boolean;
}>();

defineEmits<{ select: [] }>();
</script>

<style scoped>
.scope-card {
  align-items: center;
  background: #fff;
  border: 1px solid #dbe5f1;
  border-radius: 18px;
  cursor: pointer;
  display: flex;
  gap: 14px;
  padding: 16px 18px;
  text-align: left;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  width: 100%;
}

.scope-card:hover {
  border-color: #9db8ee;
  transform: translateY(-1px);
}

.scope-card--selected {
  background: #f8fbff;
  border-color: #3156b8;
  box-shadow: 0 10px 24px rgba(49, 86, 184, 0.08);
}

.scope-card__icon {
  align-items: center;
  border-radius: 12px;
  display: flex;
  flex: 0 0 42px;
  height: 42px;
  justify-content: center;
}

.scope-card__content {
  flex: 1 1 auto;
  min-width: 0;
}

.scope-card__radio {
  flex: 0 0 auto;
}
</style>
