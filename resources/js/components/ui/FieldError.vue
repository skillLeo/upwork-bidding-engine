<script setup>
defineProps({ text: { type: String, default: "" } });
</script>

<template>
  <p v-if="text" class="mt-1.5 text-xs font-medium text-danger">{{ text }}</p>
</template>
