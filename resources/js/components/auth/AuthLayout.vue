<script setup>
import { useBrandingStore } from "@/stores/branding";
import AgingColumn from "@/components/auth/AgingColumn.vue";

// The shared two-column shell for every auth screen: paper form on the left
// (42%), the ink signature panel on the right (58%), a hard 1px --hairline
// seam between them — no gradient, no fade, no softened corner. Below 1024px
// the ink panel is removed entirely (decoration below the fold is dead weight
// on a phone). Everything in the left panel hangs off ONE left line: the
// wordmark box and the form box share the same mx-auto/max-w-[360px]/padding,
// so their left edges align exactly rather than being centred independently.
const branding = useBrandingStore();
</script>

<template>
  <div class="auth-screen flex min-h-screen" style="background: var(--paper)">
    <!-- LEFT: paper. The panel IS the card — no card, shadow, or border. -->
    <div
      class="relative flex w-full flex-col justify-center px-6 py-16 sm:px-10 lg:w-[42%] lg:px-16"
    >
      <!-- Wordmark, top-left, on the same alignment line as the form. -->
      <div class="absolute top-8 right-0 left-0 px-6 sm:px-10 lg:px-16">
        <div class="mx-auto w-full max-w-[360px]">
          <router-link
            to="/login"
            class="auth-mono inline-block"
            style="font-weight: 600; font-size: 18px; letter-spacing: -0.02em; color: var(--paper-ink)"
            >{{ branding.name }}</router-link
          >
        </div>
      </div>

      <!-- Form column: 360px, vertically centred, same left edge as wordmark. -->
      <div class="mx-auto w-full max-w-[360px]">
        <slot />
      </div>
    </div>

    <!-- RIGHT: ink signature panel, hidden below 1024px. The 1px --hairline
         left border is the hard paper-to-ink seam. -->
    <div
      class="hidden items-center justify-center lg:flex lg:w-[58%]"
      style="background: var(--ink); border-left: 1px solid var(--hairline)"
      aria-hidden="true"
    >
      <AgingColumn />
    </div>
  </div>
</template>
