<script setup>
import { Sparkles, Check } from "@lucide/vue";
import { aiProgress } from "@/stores/aiProgress";
</script>

<template>
  <Transition
    enter-active-class="transition duration-300 ease-out"
    enter-from-class="translate-y-3 opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="transition duration-300 ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="translate-y-3 opacity-0"
  >
    <div
      v-if="aiProgress.task"
      class="fixed right-4 bottom-4 z-50 w-72 rounded-card border border-border bg-surface p-3.5 shadow-card"
      role="status"
      aria-live="polite"
    >
      <div class="flex items-center gap-2.5">
        <span
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
          :class="aiProgress.task.done ? 'bg-success/10' : 'bg-primary-tint'"
        >
          <Check v-if="aiProgress.task.done" class="h-4 w-4 text-success" />
          <Sparkles v-else class="h-4 w-4 animate-pulse text-primary" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-text-primary">
            {{ aiProgress.task.done ? "Done" : aiProgress.task.label }}
          </p>
          <p v-if="!aiProgress.task.done" class="text-xs text-text-tertiary">
            ~{{ aiProgress.task.remaining }}s left · you can keep working
          </p>
        </div>
      </div>
      <div class="mt-2.5 h-1 overflow-hidden rounded-pill bg-neutral-bg">
        <div
          class="h-full rounded-pill transition-[width] duration-200 ease-linear"
          :class="aiProgress.task.done ? 'bg-success' : 'bg-primary'"
          :style="{ width: aiProgress.task.progress + '%' }"
        />
      </div>
    </div>
  </Transition>
</template>
