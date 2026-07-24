<script setup>
// A button in the auth language. While loading it is disabled and shows an
// inline spinner, but its label stays readable (brief).
defineProps({
  variant: { type: String, default: "primary" },
  type: { type: String, default: "button" },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
</script>

<template>
  <button
    :type="type"
    class="auth-btn"
    style="position: relative"
    :class="variant === 'primary' ? 'auth-btn-primary' : 'auth-btn-secondary'"
    :disabled="loading || disabled"
    :aria-busy="loading"
  >
    <!-- Absolutely positioned so the label stays centred and does not move
         when the spinner appears — the button never changes width or shifts. -->
    <svg
      v-if="loading"
      class="auth-spinner"
      style="position: absolute; left: 16px"
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="7" cy="7" r="6" stroke="currentColor" stroke-width="2" stroke-opacity="0.25" />
      <path d="M7 1a6 6 0 0 1 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
    <slot />
  </button>
</template>
