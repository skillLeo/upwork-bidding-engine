<script setup>
import { computed } from "vue";

// A labelled input in the auth language: a real <label> (never a placeholder
// standing in for one), a 6px label-to-input gap, and an error that pushes the
// layout down over 140ms rather than jumping (the 0fr→1fr grid trick, in
// .auth-error). The error region is aria-live="polite".
const model = defineModel({ type: String, default: "" });
const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  error: { type: String, default: "" },
});
defineOptions({ inheritAttrs: false });

const hasError = computed(() => !!props.error);
</script>

<template>
  <div>
    <label :for="id" class="auth-label">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      class="auth-input"
      style="margin-top: 6px"
      :class="{ 'is-invalid': hasError }"
      :aria-invalid="hasError ? 'true' : 'false'"
      :aria-describedby="hasError ? `${id}-error` : undefined"
      v-bind="$attrs"
    />
    <div :id="`${id}-error`" class="auth-error" :class="{ open: hasError }" aria-live="polite">
      <span>{{ error }}</span>
    </div>
  </div>
</template>
