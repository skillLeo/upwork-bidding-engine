<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("hidden w-[220px] shrink-0 lg:block", props.class));
</script>

<template>
  <aside :class="classes">
    <div class="sticky top-[72px] space-y-4">
      <slot />
    </div>
  </aside>
</template>
