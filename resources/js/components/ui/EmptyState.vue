<script setup>
defineProps({
  icon: { type: [Object, Function], default: null },
  title: { type: String, required: true },
  description: { type: String, default: "" },
});
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div
      v-if="icon"
      class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-neutral-bg"
    >
      <component :is="icon" class="h-6 w-6 text-text-tertiary" :stroke-width="1.5" />
    </div>
    <p class="text-sm font-semibold text-text-primary">{{ title }}</p>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-text-secondary">{{ description }}</p>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>
