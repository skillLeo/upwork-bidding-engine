<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("text-sm text-text-secondary", props.class));
</script>

<template>
  <p :class="classes"><slot /></p>
</template>
