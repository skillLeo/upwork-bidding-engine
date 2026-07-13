<script setup>
import { computed } from "vue";
import { cn, initials } from "@/lib/utils";

const props = defineProps({
  name: { type: String, required: true },
  size: { type: String, default: "md" },
  class: { type: String, default: "" },
});

const sizeClasses = {
  sm: "h-7 w-7 text-[11px]",
  md: "h-9 w-9 text-sm",
  lg: "h-14 w-14 text-lg",
};

const classes = computed(() =>
  cn(
    "flex shrink-0 items-center justify-center rounded-full bg-primary-tint font-semibold text-primary",
    sizeClasses[props.size],
    props.class,
  ),
);
</script>

<template>
  <div :class="classes" :title="name">{{ initials(name) }}</div>
</template>
