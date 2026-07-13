<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("p-5", props.class));
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
