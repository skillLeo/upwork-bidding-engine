<script setup>
import { ref, computed, onMounted } from "vue";
import { Calendar, ChevronDown, Check } from "@lucide/vue";
import {
  format,
  startOfDay,
  startOfMonth,
  endOfMonth,
  subDays,
  subMonths,
} from "date-fns";
import { cn } from "@/lib/utils";

const props = defineProps({
  from: { type: [String, null], default: null },
  to: { type: [String, null], default: null },
});
const emit = defineEmits(["update:from", "update:to"]);

const SQL_FORMAT = "yyyy-MM-dd HH:mm:ss";

// Every preset resolves to a concrete {from, to} pair at click time (not a
// stored duration) - "Last 3 days" always means the 3 days ending now, not
// a range that goes stale the moment you open the panel.
const presets = [
  { id: "today", label: "Today", range: () => [startOfDay(new Date()), new Date()] },
  { id: "3d", label: "Last 3 days", range: () => [subDays(new Date(), 3), new Date()] },
  { id: "5d", label: "Last 5 days", range: () => [subDays(new Date(), 5), new Date()] },
  { id: "1w", label: "Last week", range: () => [subDays(new Date(), 7), new Date()] },
  { id: "2w", label: "Last 2 weeks", range: () => [subDays(new Date(), 14), new Date()] },
  { id: "this_month", label: "This month", range: () => [startOfMonth(new Date()), new Date()] },
  {
    id: "last_month",
    label: "Last month",
    range: () => [startOfMonth(subMonths(new Date(), 1)), endOfMonth(subMonths(new Date(), 1))],
  },
  { id: "6m", label: "Last 6 months", range: () => [subDays(new Date(), 182), new Date()] },
  { id: "all", label: "All time", range: () => [null, null] },
];

const DEFAULT_PRESET = "3d";

const open = ref(false);
const activePreset = ref(DEFAULT_PRESET);
const customFrom = ref("");
const customTo = ref("");

function applyPreset(preset) {
  activePreset.value = preset.id;
  const [from, to] = preset.range();
  emit("update:from", from ? format(from, SQL_FORMAT) : null);
  emit("update:to", to ? format(to, SQL_FORMAT) : null);
  open.value = false;
}

function applyCustom() {
  if (!customFrom.value && !customTo.value) return;
  activePreset.value = "custom";
  emit("update:from", customFrom.value ? customFrom.value.replace("T", " ") + ":00" : null);
  emit("update:to", customTo.value ? customTo.value.replace("T", " ") + ":00" : null);
  open.value = false;
}

const triggerLabel = computed(() => {
  if (activePreset.value === "custom") {
    const from = customFrom.value ? format(new Date(customFrom.value), "MMM d") : "…";
    const to = customTo.value ? format(new Date(customTo.value), "MMM d") : "…";
    return `${from} – ${to}`;
  }
  return presets.find((p) => p.id === activePreset.value)?.label ?? "Date range";
});

onMounted(() => {
  // Fire the default range immediately so the parent's query includes it on
  // first load, instead of the page briefly showing an unfiltered "all
  // time" list before a preset is picked.
  const defaultPreset = presets.find((p) => p.id === DEFAULT_PRESET);
  applyPreset(defaultPreset);
});
</script>

<template>
  <div class="relative">
    <button
      @click="open = !open"
      :class="
        cn(
          'flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md border px-2.5 text-xs font-medium transition-colors',
          open || activePreset !== 'all'
            ? 'border-primary bg-primary-tint text-primary'
            : 'border-border-strong text-text-secondary hover:bg-black/5',
        )
      "
    >
      <Calendar class="h-3.5 w-3.5" />
      {{ triggerLabel }}
      <ChevronDown class="h-3 w-3 opacity-60" />
    </button>

    <template v-if="open">
      <div class="fixed inset-0 z-10" @click="open = false" />
      <div
        class="absolute left-0 z-20 mt-1.5 w-80 overflow-hidden rounded-card border border-border bg-white shadow-popover"
      >
        <div class="border-b border-border px-3 py-2">
          <p class="text-xs font-semibold tracking-wide text-text-tertiary uppercase">
            Posted date
          </p>
        </div>

        <div class="flex flex-col gap-0.5 p-1.5">
          <button
            v-for="preset in presets"
            :key="preset.id"
            @click="applyPreset(preset)"
            :class="
              cn(
                'flex items-center justify-between rounded-md px-2.5 py-1.5 text-left text-sm font-medium transition-colors hover:bg-black/5',
                activePreset === preset.id ? 'text-primary' : 'text-text-secondary',
              )
            "
          >
            {{ preset.label }}
            <Check v-if="activePreset === preset.id" class="h-3.5 w-3.5" />
          </button>
        </div>

        <div class="space-y-2.5 border-t border-border p-3">
          <p class="text-xs font-semibold tracking-wide text-text-tertiary uppercase">
            Custom range
          </p>
          <div>
            <label class="mb-1 block text-[11px] font-medium text-text-tertiary">From</label>
            <input
              v-model="customFrom"
              type="datetime-local"
              class="h-9 w-full rounded-md border border-border-strong bg-white px-2 text-xs text-text-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            />
          </div>
          <div>
            <label class="mb-1 block text-[11px] font-medium text-text-tertiary">To</label>
            <input
              v-model="customTo"
              type="datetime-local"
              class="h-9 w-full rounded-md border border-border-strong bg-white px-2 text-xs text-text-primary focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            />
          </div>
          <button
            @click="applyCustom"
            :disabled="!customFrom && !customTo"
            class="h-8 w-full rounded-md bg-primary text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
          >
            Apply custom range
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
