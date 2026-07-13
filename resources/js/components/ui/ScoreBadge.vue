<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";

const props = defineProps({
  score: { type: [Number, null], default: null },
});

const tone = computed(() =>
  props.score >= 9 ? "success" : props.score >= 7 ? "info" : "neutral",
);
</script>

<template>
  <Badge v-if="score === null" tone="neutral">Not scored</Badge>
  <Badge v-else :tone="tone">{{ score }}/10</Badge>
</template>
