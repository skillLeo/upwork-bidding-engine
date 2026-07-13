<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
  modelValue: { type: String, default: "" },
  class: { type: String, default: "" },
});
defineEmits(["update:modelValue"]);

const classes = computed(() =>
  cn(
    "w-full rounded-md border border-border-strong bg-white px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary",
    "focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20",
    "disabled:cursor-not-allowed disabled:bg-black/5 disabled:text-text-tertiary",
    props.class,
  ),
);
</script>

<template>
  <textarea
    :class="classes"
    :value="modelValue"
    @input="$emit('update:modelValue', $event.target.value)"
  />
</template>
