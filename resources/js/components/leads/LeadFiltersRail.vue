<script setup>
import { cn } from "@/lib/utils";

defineProps({
  status: { type: String, required: true },
  scoreMin: { type: [Number, null], default: undefined },
});
const emit = defineEmits(["update:status", "update:scoreMin"]);

const statusOptions = [
  { value: "all", label: "All leads" },
  { value: "new", label: "New" },
  { value: "scoring", label: "Scoring" },
  { value: "ready", label: "Ready" },
  { value: "sent", label: "Sent" },
  { value: "replied", label: "Replied" },
  { value: "won", label: "Won" },
  { value: "archived", label: "Archived" },
];

const scoreOptions = [
  { value: undefined, label: "Any" },
  { value: 7, label: "7+ · Bid" },
  { value: 9, label: "9+ · Bid & boost" },
];
</script>

<template>
  <div class="space-y-4">
    <div class="overflow-hidden rounded-card border border-border bg-surface shadow-card">
      <div class="border-b border-border px-4 py-3">
        <p class="text-sm font-semibold text-text-primary">Status</p>
      </div>
      <div class="flex flex-col gap-0.5 p-2">
        <button
          v-for="opt in statusOptions"
          :key="opt.value"
          @click="emit('update:status', opt.value)"
          :class="
            cn(
              'rounded-md px-3 py-2 text-left text-sm font-medium text-text-secondary transition-colors hover:bg-black/5',
              status === opt.value && 'bg-primary-tint text-primary',
            )
          "
        >
          {{ opt.label }}
        </button>
      </div>
    </div>

    <div class="rounded-card border border-border bg-surface p-4 shadow-card">
      <p class="mb-2 text-sm font-semibold text-text-primary">Minimum score</p>
      <div class="flex flex-col gap-1">
        <button
          v-for="opt in scoreOptions"
          :key="opt.label"
          @click="emit('update:scoreMin', opt.value)"
          :class="
            cn(
              'rounded-md border px-3 py-1.5 text-left text-sm font-medium transition-colors',
              scoreMin === opt.value
                ? 'border-primary bg-primary-tint text-primary'
                : 'border-transparent text-text-secondary hover:bg-black/5',
            )
          "
        >
          {{ opt.label }}
        </button>
      </div>
    </div>
  </div>
</template>
