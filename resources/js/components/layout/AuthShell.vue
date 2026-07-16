<script setup>
defineProps({ tagline: { type: String, default: "" } });
</script>

<template>
  <div class="flex min-h-screen flex-1 flex-col items-center justify-center bg-bg px-4 py-12">
    <div class="mb-8 flex flex-col items-center gap-3">
      <div class="flex items-center gap-2.5">
        <span class="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-base font-bold text-white">
          SL
        </span>
        <span class="text-2xl font-bold text-text-primary">SkillLeo</span>
      </div>
      <p v-if="tagline" class="max-w-[280px] text-center text-sm text-text-secondary">
        {{ tagline }}
      </p>
    </div>

    <div class="w-full max-w-[380px] overflow-hidden rounded-card border border-border bg-surface shadow-card">
      <div class="h-[3px] bg-primary" aria-hidden="true" />
      <div class="p-8 sm:p-9">
        <slot />
      </div>
    </div>

    <div v-if="$slots.footer" class="mt-6 w-full max-w-[380px]">
      <slot name="footer" />
    </div>
  </div>
</template>
