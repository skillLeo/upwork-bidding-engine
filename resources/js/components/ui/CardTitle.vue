<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("text-base font-semibold text-text-primary", props.class));
</script>

<template>
  <h2 :class="classes"><slot /></h2>
</template>
