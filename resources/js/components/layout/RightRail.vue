<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({ class: { type: String, default: "" } });
const classes = computed(() => cn("hidden w-[280px] shrink-0 xl:block", props.class));
</script>

<template>
  <aside :class="classes">
    <div class="sticky top-[72px] space-y-4">
      <slot />
    </div>
  </aside>
</template>
