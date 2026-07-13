<script setup>
import { computed } from "vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  lines: { type: Number, default: 3 },
  class: { type: String, default: "" },
});
const classes = computed(() => cn("space-y-2", props.class));
</script>

<template>
  <div :class="classes">
    <Skeleton
      v-for="i in lines"
      :key="i"
      :class="cn('h-3', i === lines ? 'w-2/3' : 'w-full')"
    />
  </div>
</template>
