<script setup>
import { computed } from "vue";
import { Loader2 } from "@lucide/vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  variant: { type: String, default: "primary" },
  size: { type: String, default: "md" },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  class: { type: String, default: "" },
});

const variantClasses = {
  primary:
    "bg-primary text-white hover:bg-primary-hover active:bg-primary-pressed disabled:bg-primary/50",
  secondary:
    "bg-white text-primary border border-primary hover:bg-primary-tint disabled:opacity-50 disabled:hover:bg-white",
  ghost:
    "bg-transparent text-text-secondary hover:bg-black/5 hover:text-text-primary disabled:opacity-50",
  danger:
    "bg-white text-danger border border-danger hover:bg-danger-bg disabled:opacity-50",
};

const sizeClasses = {
  sm: "h-8 px-3 text-sm gap-1.5",
  md: "h-9 px-4 text-sm gap-2",
  lg: "h-11 px-6 text-base gap-2",
};

const classes = computed(() =>
  cn(
    "inline-flex shrink-0 items-center justify-center rounded-pill font-medium transition-colors duration-150 whitespace-nowrap disabled:cursor-not-allowed",
    variantClasses[props.variant],
    sizeClasses[props.size],
    props.class,
  ),
);
</script>

<template>
  <button :class="classes" :disabled="disabled || loading">
    <Loader2 v-if="loading" class="h-4 w-4 animate-spin" />
    <slot />
  </button>
</template>
