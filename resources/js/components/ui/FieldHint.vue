<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("mt-1.5 text-xs text-text-tertiary", props.class));
</script>

<template>
  <p :class="classes"><slot /></p>
</template>
