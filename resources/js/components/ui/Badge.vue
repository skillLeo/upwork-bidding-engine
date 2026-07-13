<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  tone: { type: String, default: "neutral" },
  class: { type: String, default: "" },
});

const toneClasses = {
  success: "bg-success-bg text-success border-success-border",
  info: "bg-info-bg text-info border-info-border",
  neutral: "bg-neutral-bg text-neutral border-neutral-border",
  warning: "bg-warning-bg text-warning border-warning-border",
  danger: "bg-danger-bg text-danger border-danger-border",
};

const classes = computed(() =>
  cn(
    "inline-flex items-center gap-1 whitespace-nowrap rounded-pill border px-2.5 py-0.5 text-xs font-semibold",
    toneClasses[props.tone],
    props.class,
  ),
);
</script>

<template>
  <span :class="classes"><slot /></span>
</template>
