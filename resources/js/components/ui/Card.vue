<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("rounded-card bg-surface shadow-card", props.class));
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
