<script setup>
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";

const props = defineProps({ status: { type: String, required: true } });

const statusLabels = {
  new: "New",
  scoring: "Scoring",
  needs_review: "Needs review",
  ready: "Ready",
  sent: "Sent",
  replied: "Replied",
  won: "Won",
  lost: "Lost",
  archived: "Archived",
};

const statusTones = {
  new: "neutral",
  scoring: "warning",
  needs_review: "danger",
  ready: "info",
  sent: "info",
  replied: "warning",
  won: "success",
  lost: "neutral",
  archived: "neutral",
};

const label = computed(() => statusLabels[props.status] ?? props.status);
const tone = computed(() => statusTones[props.status] ?? "neutral");
</script>

<template>
  <Badge :tone="tone">{{ label }}</Badge>
</template>
