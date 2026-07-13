<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("mb-1.5 block text-sm font-medium text-text-primary", props.class));
</script>

<template>
  <label :class="classes"><slot /></label>
</template>
