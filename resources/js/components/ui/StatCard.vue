<script setup>
import { computed } from "vue";
import Card from "@/components/ui/Card.vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  icon: { type: [Object, Function], default: null },
  hint: { type: String, default: "" },
  trend: { type: String, default: "neutral" },
});

const hintClass = computed(() =>
  cn(
    "mt-1 text-xs",
    props.trend === "up" && "text-success",
    props.trend === "down" && "text-danger",
    (!props.trend || props.trend === "neutral") && "text-text-tertiary",
  ),
);
</script>

<template>
  <Card class="p-4">
    <div class="flex items-start justify-between">
      <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">{{ label }}</p>
      <component :is="icon" v-if="icon" class="h-4 w-4 text-text-tertiary" :stroke-width="1.75" />
    </div>
    <p class="mt-2 text-2xl font-semibold text-text-primary">{{ value }}</p>
    <p v-if="hint" :class="hintClass">{{ hint }}</p>
  </Card>
</template>
