<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";
import { scoreTier } from "@/lib/utils";

const props = defineProps({
  score: { type: [Number, null], default: null },
});

const tone = computed(() => scoreTier(props.score));
</script>

<template>
  <Badge v-if="score === null" tone="neutral">Not scored</Badge>
  <Badge v-else :tone="tone">{{ score }}/10</Badge>
</template>
