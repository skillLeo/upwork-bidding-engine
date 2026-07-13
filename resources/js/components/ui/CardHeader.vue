<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() =>
  cn("flex items-center justify-between gap-3 border-b border-border px-5 py-4", props.class),
);
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
