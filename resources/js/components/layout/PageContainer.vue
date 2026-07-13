<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("mx-auto max-w-[1128px] px-4 py-6", props.class));
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>
